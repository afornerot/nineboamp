<?php

namespace App\Message;

final class IndexMarketMessage
{
    public function __construct(private string $marketId)
    {
    }

    public function getMarketId(): string
    {
        return $this->marketId;
    }
}
