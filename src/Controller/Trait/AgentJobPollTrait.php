<?php

namespace App\Controller\Trait;

use App\Service\AgentJobResult;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Réponse commune des endpoints de poll (chat, rescore, rapport) pour un job
 * de l'agent qui n'a pas abouti, ou pas encore.
 */
trait AgentJobPollTrait
{
    /**
     * - pending : le JS interroge à nouveau ;
     * - unreachable : 503, le JS réessaie (le poll a échoué, le job continue peut-être) ;
     * - error, not_found, timeout : 200 avec la raison, que le JS affiche.
     */
    private function agentJobResponse(AgentJobResult $job): JsonResponse
    {
        return match ($job->status) {
            AgentJobResult::PENDING => new JsonResponse(['status' => 'pending']),
            AgentJobResult::UNREACHABLE => new JsonResponse(['status' => 'unreachable', 'error' => $job->error], 503),
            default => new JsonResponse(['status' => 'error', 'error' => $job->error]),
        };
    }
}
