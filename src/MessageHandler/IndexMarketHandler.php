<?php

namespace App\MessageHandler;

use App\Message\IndexMarketMessage;
use App\Service\AmoxtliService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class IndexMarketHandler
{
    private AmoxtliService $amoxtliService;

    public function __construct(AmoxtliService $amoxtliService)
    {
        $this->amoxtliService = $amoxtliService;
    }

    public function __invoke(IndexMarketMessage $message): void
    {
        // indexMarket() initialise le workspace seulement si le marché a un dossier de pièces.
        $this->amoxtliService->indexMarket($message->getMarketId());
    }
}
