<?php

namespace App\Service;

/**
 * État d'un job de l'agent, lu par AgentJobClient.
 *
 * Seuls 'pending' et 'unreachable' appellent un nouveau poll : les autres
 * statuts sont définitifs.
 */
final class AgentJobResult
{
    public const DONE = 'done';
    public const PENDING = 'pending';
    // L'agent a fini le job en échec.
    public const ERROR = 'error';
    // L'agent ne connaît pas ce job : résultat expiré ou agent redémarré.
    public const NOT_FOUND = 'not_found';
    // Le poll a échoué (agent momentanément injoignable) ; le job continue peut-être.
    public const UNREACHABLE = 'unreachable';
    // Aucun statut final dans le délai annoncé par l'agent (AgentJobClient::wait()).
    public const TIMEOUT = 'timeout';

    /**
     * @param array<string, mixed> $result payload de l'agent : résultat d'un job done, détails d'un job en erreur
     * @param ?string              $error  raison d'un statut autre que done ou pending
     */
    public function __construct(
        public readonly string $status,
        public readonly array $result = [],
        public readonly ?string $error = null,
    ) {
    }

    public function isDone(): bool
    {
        return self::DONE === $this->status;
    }

    public function isFinal(): bool
    {
        return !in_array($this->status, [self::PENDING, self::UNREACHABLE], true);
    }

    /**
     * Code d'erreur de l'agent : 'parse' signale une réponse du LLM reçue mais inexploitable.
     */
    public function code(): ?string
    {
        return $this->result['code'] ?? null;
    }
}
