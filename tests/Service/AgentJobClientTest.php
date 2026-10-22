<?php

namespace App\Tests\Service;

use App\Service\AgentJobClient;
use App\Service\AgentJobResult;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class AgentJobClientTest extends TestCase
{
    public function testWaitsUntilJobIsDone(): void
    {
        $client = new MockHttpClient([
            new MockResponse('{"status":"pending","result":null}'),
            new MockResponse('{"status":"done","result":{"score":75,"priority":"B"}}'),
        ]);

        $job = $this->agentJobs($client)->wait(AgentJobClient::SCORE, 'job-1', 10, 0);

        $this->assertSame(AgentJobResult::DONE, $job->status);
        $this->assertSame(['score' => 75, 'priority' => 'B'], $job->result);
        $this->assertSame(2, $client->getRequestsCount());
    }

    public function testAgentErrorStopsWaitingImmediately(): void
    {
        $client = new MockHttpClient([
            new MockResponse('{"status":"error","result":{"error":"Impossible de parser la réponse JSON","code":"parse"}}'),
            new MockResponse('{"status":"done","result":{"score":75}}'),
        ]);

        $job = $this->agentJobs($client)->wait(AgentJobClient::SCORE, 'job-1', 10, 0);

        $this->assertSame(AgentJobResult::ERROR, $job->status);
        $this->assertSame('Impossible de parser la réponse JSON', $job->error);
        $this->assertSame('parse', $job->code());
        $this->assertSame(1, $client->getRequestsCount());
    }

    public function testDoneWithoutResultIsAnError(): void
    {
        $client = new MockHttpClient([new MockResponse('{"status":"done","result":null}')]);

        $job = $this->agentJobs($client)->wait(AgentJobClient::SCORE, 'job-1', 10, 0);

        $this->assertSame(AgentJobResult::ERROR, $job->status);
        $this->assertSame('réponse vide de l\'agent', $job->error);
    }

    public function testUnreachableAgentIsRetried(): void
    {
        $client = new MockHttpClient([
            new MockResponse('', ['error' => 'Connection refused']),
            new MockResponse('', ['http_code' => 502]),
            new MockResponse('{"status":"done","result":{"score":40}}'),
        ]);

        $job = $this->agentJobs($client)->wait(AgentJobClient::SCORE, 'job-1', 10, 0);

        $this->assertSame(AgentJobResult::DONE, $job->status);
        $this->assertSame(['score' => 40], $job->result);
        $this->assertSame(3, $client->getRequestsCount());
    }

    public function testJobNotFoundIsFinal(): void
    {
        $client = new MockHttpClient([
            new MockResponse('{"status":"not_found","result":null}'),
            new MockResponse('{"status":"done","result":{"score":40}}'),
        ]);

        $job = $this->agentJobs($client)->wait(AgentJobClient::SCORE, 'job-1', 10, 0);

        $this->assertSame(AgentJobResult::NOT_FOUND, $job->status);
        $this->assertSame('job introuvable côté agent (résultat expiré ou agent redémarré)', $job->error);
        $this->assertSame(1, $client->getRequestsCount());
    }

    public function testAgentFailureKeepsItsReasonWithoutParseCode(): void
    {
        $client = new MockHttpClient([new MockResponse('{"status":"error","result":{"error":"délai de l\'agent dépassé (2m45s)"}}')]);

        $job = $this->agentJobs($client)->wait(AgentJobClient::SCORE, 'job-1', 10, 0);

        $this->assertSame('délai de l\'agent dépassé (2m45s)', $job->error);
        $this->assertNull($job->code());
    }

    public function testTimeoutWhileStillPending(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse('{"status":"pending","result":null}'));

        $job = $this->agentJobs($client)->wait(AgentJobClient::SCORE, 'job-1', 0, 0, 0);

        $this->assertSame(AgentJobResult::TIMEOUT, $job->status);
        $this->assertSame('aucun statut final de l\'agent après 0 s (job perdu ou agent bloqué)', $job->error);
        // Le dernier poll a lieu à l'échéance, pas avant.
        $this->assertSame(1, $client->getRequestsCount());
    }

    public function testFetchReadsTheResultOfTheGivenKind(): void
    {
        $client = new MockHttpClient(function (string $method, string $url): MockResponse {
            $this->assertSame('GET', $method);
            $this->assertSame('http://127.0.0.1:8000/report/result/job-1', $url);

            return new MockResponse('{"status":"pending","result":null}');
        });

        $job = $this->agentJobs($client)->fetch(AgentJobClient::REPORT, 'job-1');

        $this->assertSame(AgentJobResult::PENDING, $job->status);
        $this->assertFalse($job->isFinal());
    }

    public function testFailedPollIsNotAJobError(): void
    {
        $client = new MockHttpClient([new MockResponse('', ['http_code' => 502])]);

        $job = $this->agentJobs($client)->fetch(AgentJobClient::CHAT, 'job-1');

        $this->assertSame(AgentJobResult::UNREACHABLE, $job->status);
        $this->assertFalse($job->isFinal());
    }

    public function testStartReturnsTheAgentTimeout(): void
    {
        $client = new MockHttpClient([new MockResponse('{"job_id":"job-1","timeout":600}')]);

        $started = $this->agentJobs($client)->start(AgentJobClient::REPORT, ['market_id' => 1]);

        $this->assertSame(['job_id' => 'job-1', 'timeout' => 600], $started);
    }

    public function testStartRefusedKeepsTheAgentReason(): void
    {
        $client = new MockHttpClient([new MockResponse('{"error":"impossible de créer le job"}', ['http_code' => 500])]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('job de rapport refusé par l\'agent (HTTP 500) : impossible de créer le job');

        $this->agentJobs($client)->start(AgentJobClient::REPORT, ['market_id' => 1]);
    }

    public function testStartUnreachableAgent(): void
    {
        $client = new MockHttpClient([new MockResponse('', ['error' => 'Connection refused'])]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('job de chat non démarré : Connection refused');

        $this->agentJobs($client)->start(AgentJobClient::CHAT, ['market_id' => 1]);
    }

    private function agentJobs(MockHttpClient $client): AgentJobClient
    {
        return new AgentJobClient($client, new NullLogger());
    }
}
