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
        if (!$this->amoxtliService->isAvailable()) {
            return;
        }

        $id = $message->getMarketId();

        $this->amoxtliService->initWorkspace($id);
        $this->amoxtliService->indexMarket($id);
    }
}
