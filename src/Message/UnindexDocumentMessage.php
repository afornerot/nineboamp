<?php

namespace App\Message;

/**
 * Retire une pièce d'un marché de l'index amoxtli.
 *
 * La pièce a déjà été supprimée du disque par le bundle de fichiers : ce
 * message ne fait que désindexer ce qu'elle avait indexé.
 */
final class UnindexDocumentMessage
{
    public function __construct(
        private string $marketId,
        private string $piece,
    ) {
    }

    public function getMarketId(): string
    {
        return $this->marketId;
    }

    /**
     * Nom de la pièce, relatif au dossier du marché (uploads/boamp/{id}).
     */
    public function getPiece(): string
    {
        return $this->piece;
    }
}
