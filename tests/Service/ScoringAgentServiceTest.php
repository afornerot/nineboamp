<?php

namespace App\Tests\Service;

use App\Service\AgentJobClient;
use App\Service\CompanyContextBuilder;
use App\Service\ScoringAgentService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class ScoringAgentServiceTest extends TestCase
{
    private const HEALTH = '{"status":"ok","service":"agent","capabilities":["company_context"]}';

    public function testStartScoringJobPostsTheMarketAndReturnsTheAgentTimeout(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            if ('http://127.0.0.1:8000/health' === $url) {
                return new MockResponse(self::HEALTH);
            }
            $this->assertSame('POST', $method);
            $this->assertSame('http://127.0.0.1:8000/score', $url);
            $this->assertSame(
                ['market_id' => 1, 'idweb' => '26-1', 'title' => 't', 'buyer' => 'b', 'description' => 'd', 'amount' => '1000', 'deadline' => null, 'products' => [], 'company_context' => '### Savoir-faire'],
                json_decode($options['body'], true),
            );

            return new MockResponse('{"job_id":"job-1","timeout":165}');
        });

        $started = $this->scoringAgent($client)->startScoringJob(1, '26-1', 't', 'b', 'd', '1000', null, []);

        $this->assertSame(['job_id' => 'job-1', 'timeout' => 165], $started);
    }

    public function testStartScoringJobRefusedKeepsTheAgentReason(): void
    {
        $client = new MockHttpClient([new MockResponse(self::HEALTH), new MockResponse('{"error":"impossible de créer le job"}', ['http_code' => 500])]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('job de scoring refusé par l\'agent (HTTP 500) : impossible de créer le job');

        $this->scoringAgent($client)->startScoringJob(1, '26-1', 't', 'b', 'd', '1000', null, []);
    }

    public function testStartScoringJobRefusesAnAgentOlderThanTheCompanyContext(): void
    {
        // Agent d'avant le champ company_context : /health sans « capabilities ».
        $client = new MockHttpClient([new MockResponse('{"status":"ok","service":"agent"}')]);

        try {
            $this->scoringAgent($client)->startScoringJob(1, '26-1', 't', 'b', 'd', '1000', null, []);
            $this->fail('un agent sans la capacité company_context doit être refusé');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('antérieur au code de l\'application', $e->getMessage());
            $this->assertStringContainsString('--build', $e->getMessage());
        }

        $this->assertSame(1, $client->getRequestsCount(), 'aucun job ne doit être envoyé à un agent obsolète');
    }

    public function testStartScoringJobTellsAnUnreachableAgentFromAnOutdatedOne(): void
    {
        $client = new MockHttpClient([new MockResponse('', ['error' => 'Connection refused'])]);

        try {
            $this->scoringAgent($client)->startScoringJob(1, '26-1', 't', 'b', 'd', '1000', null, []);
            $this->fail('un agent injoignable doit faire échouer le démarrage');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('agent IA injoignable (Connection refused', $e->getMessage());
            $this->assertStringNotContainsString('--build', $e->getMessage());
        }
    }

    private function scoringAgent(MockHttpClient $client): ScoringAgentService
    {
        $companyContext = $this->createStub(CompanyContextBuilder::class);
        $companyContext->method('build')->willReturn('### Savoir-faire');

        return new ScoringAgentService(new AgentJobClient($client, new NullLogger()), $companyContext);
    }
}
