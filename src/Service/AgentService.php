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
    private const TIMEOUT_POLL = 120;
    private const POLL_INTERVAL = 2;

    public function __construct(
        private \Symfony\Contracts\HttpClient\HttpClientInterface $httpClient,
        private \Psr\Log\LoggerInterface $logger,
    ) {
    }

    /**
     * Start a chat job and wait for result.
     * Uses async polling to avoid long timeouts.
     *
     * @param int $marketId Market ID for the session
     * @param string $message User message
     * @param array<int, array{role: string, content: string}> $history Chat history
     * @param bool $resetSession Whether to reset the session
     * @return array{answer: string, sources: array<int, string>, tools_used: array<int, string>, error: ?string}
     */
    public function chat(int $marketId, string $message, array $history = [], bool $resetSession = false): array
    {
        $jobId = $this->startChat($marketId, $message, $history, $resetSession);

        if (null === $jobId) {
            return [
                'answer' => '',
                'sources' => [],
                'tools_used' => [],
                'error' => 'Impossible de démarrer le job agent',
            ];
        }

        return $this->waitForResult($jobId);
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
     * Wait for a chat job result with polling.
     *
     * @return array{answer: string, sources: array<int, string>, tools_used: array<int, string>, error: ?string}
     */
    public function waitForResult(string $jobId): array
    {
        $start = time();

        while (time() - $start < self::TIMEOUT_POLL) {
            try {
                $response = $this->httpClient->request('GET', self::AGENT_URL . '/chat/result/' . $jobId, [
                    'timeout' => 10,
                ]);

                $data = $response->toArray();
                $status = $data['status'] ?? 'unknown';

                if ('done' === $status) {
                    $result = $data['result'] ?? [];
                    return [
                        'answer' => $result['answer'] ?? '',
                        'sources' => $result['sources'] ?? [],
                        'tools_used' => $result['tools_used'] ?? [],
                        'error' => $result['error'] ?? null,
                    ];
                }

                if ('error' === $status || 'not_found' === $status) {
                    $result = $data['result'] ?? [];
                    return [
                        'answer' => '',
                        'sources' => [],
                        'tools_used' => [],
                        'error' => $result['error'] ?? 'Job failed or not found',
                    ];
                }

                sleep(self::POLL_INTERVAL);

            } catch (\Throwable $e) {
                $this->logger->warning('AgentService: polling error', [
                    'error' => $e->getMessage(),
                    'job_id' => $jobId,
                ]);
                sleep(self::POLL_INTERVAL);
            }
        }

        return [
            'answer' => '',
            'sources' => [],
            'tools_used' => [],
            'error' => 'Timeout en attendant le résultat du job agent',
        ];
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
