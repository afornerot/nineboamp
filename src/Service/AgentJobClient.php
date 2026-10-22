<?php

namespace App\Service;

/**
 * Client des jobs de l'agent (localhost:8000) : POST /{kind} crée un job,
 * GET /{kind}/result/{id} le lit.
 *
 * Seul endroit qui interprète les statuts de l'agent : les services préparent
 * les payloads, les contrôleurs traduisent le résultat pour le JS.
 */
class AgentJobClient
{
    public const CHAT = 'chat';
    public const SCORE = 'score';
    public const REPORT = 'report';

    private const LABELS = [
        self::CHAT => 'chat',
        self::SCORE => 'scoring',
        self::REPORT => 'rapport',
    ];

    private const AGENT_URL = 'http://127.0.0.1:8000';

    private const TIMEOUT_START = 10;
    private const TIMEOUT_POLL = 10;

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
     * Start a job.
     *
     * @param array<string, mixed> $payload
     *
     * @throws \RuntimeException si le job n'a pas été créé, avec la raison (agent injoignable, job refusé…)
     *
     * @return array{job_id: string, timeout: int} Job ID et délai (s) au bout duquel l'agent garantit un statut final
     */
    public function start(string $kind, array $payload): array
    {
        try {
            $response = $this->httpClient->request('POST', self::AGENT_URL.'/'.$kind, [
                'json' => $payload,
                'timeout' => self::TIMEOUT_START,
            ]);

            // false : un code HTTP d'erreur ne lève pas, on veut le message de l'agent.
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            // Agent injoignable, réponse non JSON, requête impossible à encoder…
            throw $this->startFailed($kind, $payload, sprintf('job de %s non démarré : %s', self::LABELS[$kind], $e->getMessage()), $e);
        }

        if (!isset($data['job_id'])) {
            // Agent joint, mais il a refusé le job (500 « impossible de créer le job », 422…).
            $reason = $data['error'] ?? $data['detail'][0]['msg'] ?? 'réponse sans job_id';

            throw $this->startFailed($kind, $payload, sprintf('job de %s refusé par l\'agent (HTTP %d) : %s', self::LABELS[$kind], $response->getStatusCode(), $reason));
        }

        return ['job_id' => $data['job_id'], 'timeout' => (int) ($data['timeout'] ?? self::DEFAULT_AGENT_TIMEOUT)];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function startFailed(string $kind, array $payload, string $message, ?\Throwable $previous = null): \RuntimeException
    {
        $this->logger->error('AgentJobClient: failed to start job', [
            'kind' => $kind,
            'error' => $message,
            'market_id' => $payload['market_id'] ?? null,
        ]);

        return new \RuntimeException($message, 0, $previous);
    }

    /**
     * Get the job state (single check, no polling).
     */
    public function fetch(string $kind, string $jobId): AgentJobResult
    {
        try {
            $response = $this->httpClient->request('GET', sprintf('%s/%s/result/%s', self::AGENT_URL, $kind, rawurlencode($jobId)), [
                'timeout' => self::TIMEOUT_POLL,
            ]);
            $job = $response->toArray();
        } catch (\Throwable $e) {
            $this->logger->warning('AgentJobClient: failed to get job result', [
                'kind' => $kind,
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);

            return new AgentJobResult(AgentJobResult::UNREACHABLE, error: $e->getMessage());
        }

        $result = $this->toResult($job);

        if ($result->isFinal() && !$result->isDone()) {
            $this->logger->warning('AgentJobClient: job failed', [
                'kind' => $kind,
                'job_id' => $jobId,
                'status' => $result->status,
                'error' => $result->error,
                'raw_response' => $result->result['raw_response'] ?? null,
            ]);
        }

        return $result;
    }

    /**
     * @param array<mixed> $job réponse de GET /{kind}/result/{id}
     */
    private function toResult(array $job): AgentJobResult
    {
        $result = $job['result'] ?? null;

        return match ($job['status'] ?? null) {
            'pending' => new AgentJobResult(AgentJobResult::PENDING),
            'done' => is_array($result)
                ? new AgentJobResult(AgentJobResult::DONE, $result)
                : new AgentJobResult(AgentJobResult::ERROR, error: 'réponse vide de l\'agent'),
            'error' => new AgentJobResult(AgentJobResult::ERROR, is_array($result) ? $result : [], (string) ($result['error'] ?? 'erreur inconnue de l\'agent')),
            // L'agent ne perd pas un job en cours : il a fini depuis trop longtemps,
            // ou l'agent a redémarré et vidé son dossier de jobs.
            'not_found' => new AgentJobResult(AgentJobResult::NOT_FOUND, error: 'job introuvable côté agent (résultat expiré ou agent redémarré)'),
            default => new AgentJobResult(AgentJobResult::UNREACHABLE, error: 'réponse inattendue de l\'agent'),
        };
    }

    /**
     * Wait synchronously for the job's final state.
     *
     * $agentTimeout est le délai annoncé par start() : passé ce délai, l'agent
     * a écrit un statut final ; un job encore en attente au-delà est perdu.
     */
    public function wait(string $kind, string $jobId, int $agentTimeout, int $delaySeconds = 2, int $marginSeconds = self::RESULT_MARGIN): AgentJobResult
    {
        $timeoutSeconds = $agentTimeout + $marginSeconds;
        $deadline = time() + $timeoutSeconds;

        while (true) {
            // Un poll en échec (agent momentanément injoignable) n'est pas final : on réessaie.
            $job = $this->fetch($kind, $jobId);
            if ($job->isFinal()) {
                return $job;
            }

            // Dernier poll fait à l'échéance ou après : un job fini pendant le sleep est vu.
            if (time() >= $deadline) {
                break;
            }
            sleep($delaySeconds);
        }

        $this->logger->warning('AgentJobClient: timeout waiting for result', ['kind' => $kind, 'job_id' => $jobId, 'timeout' => $timeoutSeconds]);

        return new AgentJobResult(AgentJobResult::TIMEOUT, error: sprintf('aucun statut final de l\'agent après %d s (job perdu ou agent bloqué)', $timeoutSeconds));
    }

    /**
     * Check if the agent is healthy.
     */
    public function isHealthy(): bool
    {
        try {
            $response = $this->httpClient->request('GET', self::AGENT_URL.'/health', [
                'timeout' => 5,
            ]);

            return 200 === $response->getStatusCode();
        } catch (\Throwable) {
            return false;
        }
    }
}
