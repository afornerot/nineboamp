<?php

namespace App\MessageHandler;

use App\Message\UnindexDocumentMessage;
use App\Service\AmoxtliService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class UnindexDocumentHandler
{
    private AmoxtliService $amoxtliService;

    public function __construct(AmoxtliService $amoxtliService)
    {
        $this->amoxtliService = $amoxtliService;
    }

    public function __invoke(UnindexDocumentMessage $message): void
    {
        $this->amoxtliService->unindexDocument($message->getMarketId(), $message->getPiece());
    }
}
