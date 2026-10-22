<?php

namespace App\Service;

/**
 * Agent Service - Calls the agent chat endpoint through AgentJobClient
 */
class AgentService
{
    public function __construct(
        private AgentJobClient $agentJobs,
    ) {
    }

    /**
     * Start a chat job.
     *
     * @return string|null Job ID or null on error (raison journalisée par AgentJobClient)
     */
    public function startChat(int $marketId, string $message, array $history = [], bool $resetSession = false): ?string
    {
        try {
            return $this->agentJobs->start(AgentJobClient::CHAT, [
                'market_id' => $marketId,
                'message' => $message,
                'history' => $history,
                'reset_session' => $resetSession,
            ])['job_id'];
        } catch (\RuntimeException) {
            return null;
        }
    }

    /**
     * Get job result without polling (single check).
     */
    public function getResult(string $jobId): AgentJobResult
    {
        return $this->agentJobs->fetch(AgentJobClient::CHAT, $jobId);
    }

    /**
     * Check if the agent is healthy.
     */
    public function isHealthy(): bool
    {
        return $this->agentJobs->isHealthy();
    }
}
