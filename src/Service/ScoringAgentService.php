<?php

namespace App\Service;

/**
 * Scoring Agent Service - Calls the agent scoring endpoint on localhost:8000
 */
class ScoringAgentService
{
    private const AGENT_URL = 'http://127.0.0.1:8000';

    public function __construct(
        private \Symfony\Contracts\HttpClient\HttpClientInterface $httpClient,
        private \Psr\Log\LoggerInterface $logger,
    ) {
    }

    /**
     * Start a scoring job.
     *
     * @return string|null Job ID or null on error
     */
    public function startScoringJob(
        int $marketId,
        string $title,
        string $buyer,
        string $description,
        string $amount,
        ?string $deadline,
        array $products
    ): ?string {
        try {
            $response = $this->httpClient->request('POST', self::AGENT_URL . '/score', [
                'json' => [
                    'market_id' => $marketId,
                    'title' => $title,
                    'buyer' => $buyer,
                    'description' => $description,
                    'amount' => $amount,
                    'deadline' => $deadline,
                    'products' => $products,
                ],
                'timeout' => 10,
            ]);

            $data = $response->toArray();

            return $data['job_id'] ?? null;
        } catch (\Throwable $e) {
            $this->logger->error('ScoringAgentService: failed to start scoring job', [
                'error' => $e->getMessage(),
                'market_id' => $marketId,
            ]);

            return null;
        }
    }

    /**
     * Get scoring result (single check, no polling).
     *
     * @return array{status: string, result?: array}
     */
    public function getScoringResult(string $jobId): array
    {
        try {
            $response = $this->httpClient->request('GET', self::AGENT_URL . '/score/result/' . $jobId, [
                'timeout' => 10,
            ]);

            return $response->toArray();
        } catch (\Throwable $e) {
            $this->logger->warning('ScoringAgentService: failed to get scoring result', [
                'error' => $e->getMessage(),
                'job_id' => $jobId,
            ]);

            return ['status' => 'error', 'result' => ['error' => $e->getMessage()]];
        }
    }

    /**
     * Check if the agent is healthy.
     */
    public function isHealthy(): bool
    {
        try {
            $response = $this->httpClient->request('GET', self::AGENT_URL . '/health', [
                'timeout' => 5,
            ]);

            return 200 === $response->getStatusCode();
        } catch (\Throwable) {
            return false;
        }
    }
}
