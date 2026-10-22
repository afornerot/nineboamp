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
            new MockResponse('{"status":"error","result":{"error":"Impossible de parser la réponse JSON","code":"parse"}}'),
            new MockResponse('{"status":"done","result":{"score":75}}'),
        ]);

        $job = (new ScoringAgentService($client, new NullLogger()))->waitForResult('job-1', 10, 0);

        $this->assertSame(['status' => 'error', 'error' => 'Impossible de parser la réponse JSON', 'code' => 'parse'], $job);
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

    public function testAgentFailureKeepsItsReasonWithoutParseCode(): void
    {
        $client = new MockHttpClient([new MockResponse('{"status":"error","result":{"error":"délai de l\'agent dépassé (2m45s)"}}')]);

        $job = (new ScoringAgentService($client, new NullLogger()))->waitForResult('job-1', 10, 0);

        $this->assertSame(['status' => 'error', 'error' => 'délai de l\'agent dépassé (2m45s)', 'code' => null], $job);
    }

    public function testTimeoutWhileStillPending(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse('{"status":"pending","result":null}'));

        $job = (new ScoringAgentService($client, new NullLogger()))->waitForResult('job-1', 0, 0, 0);

        $this->assertSame(['status' => 'timeout', 'error' => 'aucun statut final de l\'agent après 0 s (job perdu ou agent bloqué)'], $job);
        // Le dernier poll a lieu à l'échéance, pas avant.
        $this->assertSame(1, $client->getRequestsCount());
    }

    public function testStartScoringJobReturnsTheAgentTimeout(): void
    {
        $client = new MockHttpClient([new MockResponse('{"job_id":"job-1","timeout":165}')]);

        $started = (new ScoringAgentService($client, new NullLogger()))->startScoringJob(1, 't', 'b', 'd', '1000', null, []);

        $this->assertSame(['job_id' => 'job-1', 'timeout' => 165], $started);
    }

    public function testStartScoringJobRefusedKeepsTheAgentReason(): void
    {
        $client = new MockHttpClient([new MockResponse('{"error":"impossible de créer le job"}', ['http_code' => 500])]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('job de scoring refusé par l\'agent (HTTP 500) : impossible de créer le job');

        (new ScoringAgentService($client, new NullLogger()))->startScoringJob(1, 't', 'b', 'd', '1000', null, []);
    }

    public function testStartScoringJobUnreachableAgent(): void
    {
        $client = new MockHttpClient([new MockResponse('', ['error' => 'Connection refused'])]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('job de scoring non démarré : Connection refused');

        (new ScoringAgentService($client, new NullLogger()))->startScoringJob(1, 't', 'b', 'd', '1000', null, []);
    }

    public function testFailedPollIsNotAJobError(): void
    {
        $client = new MockHttpClient([new MockResponse('', ['http_code' => 502])]);

        $result = (new ScoringAgentService($client, new NullLogger()))->getScoringResult('job-1');

        $this->assertSame('unreachable', $result['status']);
    }
}
