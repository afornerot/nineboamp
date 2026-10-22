<?php

namespace App\Tests\Service;

use App\Service\ScoringAgentService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class ScoringAgentServiceTest extends TestCase
{
    public function testWaitsUntilJobIsDone(): void
    {
        $client = new MockHttpClient([
            new MockResponse('{"status":"pending","result":null}'),
            new MockResponse('{"status":"done","result":{"score":75,"priority":"B"}}'),
        ]);

        $job = (new ScoringAgentService($client, new NullLogger()))->waitForResult('job-1', 10, 0);

        $this->assertSame(['status' => 'done', 'result' => ['score' => 75, 'priority' => 'B']], $job);
        $this->assertSame(2, $client->getRequestsCount());
    }

    public function testAgentErrorStopsWaitingImmediately(): void
    {
        $client = new MockHttpClient([
            new MockResponse('{"status":"error","result":{"error":"Impossible de parser la réponse JSON"}}'),
            new MockResponse('{"status":"done","result":{"score":75}}'),
        ]);

        $job = (new ScoringAgentService($client, new NullLogger()))->waitForResult('job-1', 10, 0);

        $this->assertSame(['status' => 'error', 'error' => 'Impossible de parser la réponse JSON'], $job);
        $this->assertSame(1, $client->getRequestsCount());
    }

    public function testDoneWithoutResultIsAnError(): void
    {
        $client = new MockHttpClient([new MockResponse('{"status":"done","result":null}')]);

        $job = (new ScoringAgentService($client, new NullLogger()))->waitForResult('job-1', 10, 0);

        $this->assertSame('error', $job['status']);
    }

    public function testUnreachableAgentIsRetried(): void
    {
        $client = new MockHttpClient([
            new MockResponse('', ['error' => 'Connection refused']),
            new MockResponse('', ['http_code' => 502]),
            new MockResponse('{"status":"done","result":{"score":40}}'),
        ]);

        $job = (new ScoringAgentService($client, new NullLogger()))->waitForResult('job-1', 10, 0);

        $this->assertSame(['status' => 'done', 'result' => ['score' => 40]], $job);
        $this->assertSame(3, $client->getRequestsCount());
    }

    public function testJobNotFound(): void
    {
        $client = new MockHttpClient([new MockResponse('{"status":"not_found","result":null}')]);

        $job = (new ScoringAgentService($client, new NullLogger()))->waitForResult('job-1', 10, 0);

        $this->assertSame('not_found', $job['status']);
    }

    public function testTimeoutWhileStillPending(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse('{"status":"pending","result":null}'));

        $job = (new ScoringAgentService($client, new NullLogger()))->waitForResult('job-1', 0, 0);

        $this->assertSame(['status' => 'timeout', 'error' => 'pas de résultat de l\'agent après 0 s'], $job);
    }

    public function testResultTimeoutCoversAgentWorstCase(): void
    {
        // Pire cas d'un job de scoring côté agent : appel structuré (45 s) puis repli texte (120 s).
        $this->assertGreaterThan(45 + 120, ScoringAgentService::RESULT_TIMEOUT);
    }
}
