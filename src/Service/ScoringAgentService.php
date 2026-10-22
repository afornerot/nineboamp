<?php

namespace App\Service;

/**
 * Scoring Agent Service - Calls the agent scoring endpoint on localhost:8000
 */
class ScoringAgentService
{
    private const AGENT_URL = 'http://127.0.0.1:8000';

    // Doit couvrir le pire cas d'un job de scoring côté agent (cmd/nineagent) :
    // -score-structured-timeout (45 s) puis le repli texte -call-timeout (120 s).
    public const RESULT_TIMEOUT = 180;

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
            return $this->fetchJob($jobId);
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

    /**
     * Wait synchronously for scoring result.
     *
     * @return array{status: 'done', result: array}|array{status: 'error'|'not_found'|'timeout', error: string}
     */
    public function waitForResult(string $jobId, int $timeoutSeconds = self::RESULT_TIMEOUT, int $delaySeconds = 2): array
    {
        $deadline = time() + $timeoutSeconds;

        do {
            try {
                $job = $this->fetchJob($jobId);
            } catch (\Throwable $e) {
                // Agent momentanément injoignable : le job continue peut-être, on réessaie.
                $this->logger->warning('ScoringAgentService: failed to get scoring result', [
                    'error' => $e->getMessage(),
                    'job_id' => $jobId,
                ]);
                sleep($delaySeconds);
                continue;
            }

            $status = $job['status'] ?? null;

            if ('done' === $status && is_array($job['result'] ?? null)) {
                return ['status' => 'done', 'result' => $job['result']];
            }

            if ('error' === $status || 'done' === $status) {
                $error = $job['result']['error'] ?? 'réponse vide de l\'agent';
                $this->logger->warning('ScoringAgentService: scoring job failed', [
                    'job_id' => $jobId,
                    'error' => $error,
                    'raw_response' => $job['result']['raw_response'] ?? null,
                ]);

                return ['status' => 'error', 'error' => $error];
            }

            if ('not_found' === $status) {
                $this->logger->warning('ScoringAgentService: job not found', ['job_id' => $jobId]);

                return ['status' => 'not_found', 'error' => 'job introuvable côté agent'];
            }

            sleep($delaySeconds);
        } while (time() < $deadline);

        $this->logger->warning('ScoringAgentService: timeout waiting for result', ['job_id' => $jobId, 'timeout' => $timeoutSeconds]);

        return ['status' => 'timeout', 'error' => sprintf('pas de résultat de l\'agent après %d s', $timeoutSeconds)];
    }

    /**
     * @return array{status: string, result?: mixed}
     */
    private function fetchJob(string $jobId): array
    {
        $response = $this->httpClient->request('GET', self::AGENT_URL . '/score/result/' . $jobId, [
            'timeout' => 10,
        ]);

        return $response->toArray();
    }
}
