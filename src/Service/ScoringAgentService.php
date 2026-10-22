<?php

namespace App\Service;

/**
 * Scoring Agent Service - Calls the agent scoring endpoint on localhost:8000
 */
class ScoringAgentService
{
    private const AGENT_URL = 'http://127.0.0.1:8000';

    // Marge au-delà du délai annoncé par l'agent (après lequel il garantit un statut
    // final) : intervalle de poll et timeout HTTP d'un poll.
    private const RESULT_MARGIN = 20;

    // Délai d'attente si l'agent n'en annonce pas.
    private const DEFAULT_AGENT_TIMEOUT = 180;

    public function __construct(
        private \Symfony\Contracts\HttpClient\HttpClientInterface $httpClient,
        private \Psr\Log\LoggerInterface $logger,
    ) {
    }

    /**
     * Start a scoring job.
     *
     * @return array{job_id: string, timeout: int}|null Job ID et délai (s) au bout duquel l'agent garantit un statut final, null on error
     */
    public function startScoringJob(
        int $marketId,
        string $title,
        string $buyer,
        string $description,
        string $amount,
        ?string $deadline,
        array $products
    ): ?array {
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
            if (!isset($data['job_id'])) {
                return null;
            }

            return ['job_id' => $data['job_id'], 'timeout' => (int) ($data['timeout'] ?? self::DEFAULT_AGENT_TIMEOUT)];
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
     * @return array<string, mixed>
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
     * $agentTimeout est le délai annoncé par startScoringJob() : passé ce délai, l'agent
     * a écrit un statut final ; un job encore en attente au-delà est perdu.
     * Le code d'erreur 'parse' signale une réponse du LLM reçue mais inexploitable.
     *
     * @return array{status: 'done', result: array<string, mixed>}|array{status: 'error', error: string, code: ?string}|array{status: 'not_found'|'timeout', error: string}
     */
    public function waitForResult(string $jobId, int $agentTimeout, int $delaySeconds = 2, int $marginSeconds = self::RESULT_MARGIN): array
    {
        $timeoutSeconds = $agentTimeout + $marginSeconds;
        $deadline = time() + $timeoutSeconds;

        while (true) {
            try {
                $job = $this->fetchJob($jobId);
            } catch (\Throwable $e) {
                // Agent momentanément injoignable : le job continue peut-être, on réessaie.
                $this->logger->warning('ScoringAgentService: failed to get scoring result', [
                    'error' => $e->getMessage(),
                    'job_id' => $jobId,
                ]);
                $job = [];
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

                return ['status' => 'error', 'error' => $error, 'code' => $job['result']['code'] ?? null];
            }

            if ('not_found' === $status) {
                $this->logger->warning('ScoringAgentService: job not found', ['job_id' => $jobId]);

                return ['status' => 'not_found', 'error' => 'job introuvable côté agent'];
            }

            // Dernier poll fait à l'échéance ou après : un job fini pendant le sleep est vu.
            if (time() >= $deadline) {
                break;
            }
            sleep($delaySeconds);
        }

        $this->logger->warning('ScoringAgentService: timeout waiting for result', ['job_id' => $jobId, 'timeout' => $timeoutSeconds]);

        return ['status' => 'timeout', 'error' => sprintf('aucun statut final de l\'agent après %d s (job perdu ou agent bloqué)', $timeoutSeconds)];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchJob(string $jobId): array
    {
        $response = $this->httpClient->request('GET', self::AGENT_URL . '/score/result/' . $jobId, [
            'timeout' => 10,
        ]);

        return $response->toArray();
    }
}
