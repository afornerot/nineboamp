<?php

namespace App\Service;

/**
 * Agent Service - Calls Agent running on localhost:8000
 * Supports async jobs for long-running requests.
 */
class AgentService
{
    private const AGENT_URL = 'http://127.0.0.1:8000';

    private const TIMEOUT_START = 10;

    public function __construct(
        private \Symfony\Contracts\HttpClient\HttpClientInterface $httpClient,
        private \Psr\Log\LoggerInterface $logger,
    ) {
    }

    /**
     * Start a chat job.
     *
     * @return string|null Job ID or null on error
     */
    public function startChat(int $marketId, string $message, array $history = [], bool $resetSession = false): ?string
    {
        try {
            $response = $this->httpClient->request('POST', self::AGENT_URL . '/chat', [
                'json' => [
                    'market_id' => $marketId,
                    'message' => $message,
                    'history' => $history,
                    'reset_session' => $resetSession,
                ],
                'timeout' => self::TIMEOUT_START,
            ]);

            $data = $response->toArray();

            return $data['job_id'] ?? null;
        } catch (\Throwable $e) {
            $this->logger->error('AgentService: failed to start chat job', [
                'error' => $e->getMessage(),
                'market_id' => $marketId,
            ]);

            return null;
        }
    }

    /**
     * Get job result without polling (single check).
     *
     * @return array{status: string, result?: array}
     */
    public function getResult(string $jobId): array
    {
        try {
            $response = $this->httpClient->request('GET', self::AGENT_URL . '/chat/result/' . $jobId, [
                'timeout' => 10,
            ]);

            $data = $response->toArray();
            return $data;
        } catch (\Throwable $e) {
            $this->logger->warning('AgentService: failed to get job result', [
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
