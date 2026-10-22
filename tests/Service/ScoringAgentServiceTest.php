<?php

namespace App\Tests\Service;

use App\Service\AgentJobClient;
use App\Service\ScoringAgentService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class ScoringAgentServiceTest extends TestCase
{
    public function testStartScoringJobPostsTheMarketAndReturnsTheAgentTimeout(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->assertSame('POST', $method);
            $this->assertSame('http://127.0.0.1:8000/score', $url);
            $this->assertSame(
                ['market_id' => 1, 'title' => 't', 'buyer' => 'b', 'description' => 'd', 'amount' => '1000', 'deadline' => null, 'products' => []],
                json_decode($options['body'], true),
            );

            return new MockResponse('{"job_id":"job-1","timeout":165}');
        });

        $started = $this->scoringAgent($client)->startScoringJob(1, 't', 'b', 'd', '1000', null, []);

        $this->assertSame(['job_id' => 'job-1', 'timeout' => 165], $started);
    }

    public function testStartScoringJobRefusedKeepsTheAgentReason(): void
    {
        $client = new MockHttpClient([new MockResponse('{"error":"impossible de créer le job"}', ['http_code' => 500])]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('job de scoring refusé par l\'agent (HTTP 500) : impossible de créer le job');

        $this->scoringAgent($client)->startScoringJob(1, 't', 'b', 'd', '1000', null, []);
    }

    private function scoringAgent(MockHttpClient $client): ScoringAgentService
    {
        return new ScoringAgentService(new AgentJobClient($client, new NullLogger()));
    }
}
