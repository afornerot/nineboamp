<?php

namespace App\Service;

/**
 * Scoring Agent Service - Calls the agent scoring endpoint through AgentJobClient
 */
class ScoringAgentService
{
    public function __construct(
        private AgentJobClient $agentJobs,
    ) {
    }

    /**
     * Start a scoring job.
     *
     * @return array{job_id: string, timeout: int} Job ID et délai (s) au bout duquel l'agent garantit un statut final
     *
     * @throws \RuntimeException si le job n'a pas été créé, avec la raison (agent injoignable, job refusé…)
     */
    public function startScoringJob(
        int $marketId,
        string $idweb,
        string $title,
        string $buyer,
        string $description,
        string $amount,
        ?string $deadline,
        array $products
    ): array {
        return $this->agentJobs->start(AgentJobClient::SCORE, [
            'market_id' => $marketId,
            'idweb' => $idweb,
            'title' => $title,
            'buyer' => $buyer,
            'description' => $description,
            'amount' => $amount,
            'deadline' => $deadline,
            'products' => $products,
        ]);
    }

    /**
     * Get scoring result (single check, no polling).
     */
    public function getScoringResult(string $jobId): AgentJobResult
    {
        return $this->agentJobs->fetch(AgentJobClient::SCORE, $jobId);
    }

    /**
     * Wait synchronously for scoring result.
     *
     * $agentTimeout est le délai annoncé par startScoringJob().
     */
    public function waitForResult(string $jobId, int $agentTimeout): AgentJobResult
    {
        return $this->agentJobs->wait(AgentJobClient::SCORE, $jobId, $agentTimeout);
    }
}
