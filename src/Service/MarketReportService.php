<?php

namespace App\Service;

use App\Entity\Market;
use App\Entity\MarketChatMessage;
use App\Entity\MarketProduct;
use App\Entity\Product;
use App\Repository\MarketChatMessageRepository;
use App\Repository\ProductRepository;

/**
 * Market Report Service - Builds the payload and calls the agent
 * endpoint (through AgentJobClient) to generate a positioning report for a given market.
 */
class MarketReportService
{
    public function __construct(
        private AgentJobClient $agentJobs,
        private MarketChatMessageRepository $chatRepo,
        private ProductRepository $productRepository,
    ) {
    }

    /**
     * Start a report generation job.
     *
     * @return string|null Job ID or null on error (raison journalisée par AgentJobClient)
     */
    public function startReport(Market $market): ?string
    {
        try {
            return $this->agentJobs->start(AgentJobClient::REPORT, $this->buildPayload($market))['job_id'];
        } catch (\RuntimeException) {
            return null;
        }
    }

    /**
     * Get report job result (single check, no polling).
     */
    public function getResult(string $jobId): AgentJobResult
    {
        return $this->agentJobs->fetch(AgentJobClient::REPORT, $jobId);
    }

    /**
     * Build the JSON payload sent to the agent endpoint.
     *
     * @return array<string, mixed>
     */
    public function buildPayload(Market $market): array
    {
        $importantMessages = $this->chatRepo->findImportantForMarket($market->getId(), 50);

        $products = [];
        $marketProducts = $market->getMarketProducts()->toArray();
        usort($marketProducts, fn (MarketProduct $a, MarketProduct $b) => ($b->getScore() ?? 0) <=> ($a->getScore() ?? 0));
        foreach ($marketProducts as $mp) {
            $product = $mp->getProduct();
            if (!$product instanceof Product) {
                continue;
            }
            $products[] = [
                'id' => $product->getId(),
                'name' => $product->getName(),
                'description' => mb_substr($product->getDescription() ?? '', 0, 200),
                'keywords' => $product->getKeywords() ?? '',
                'sectors' => $product->getSectors() ?? '',
            ];
        }

        $catalogue = $this->buildCatalogueString();

        $chatHistory = array_map(function (MarketChatMessage $m) {
            return [
                'role' => $m->getRole(),
                'content' => $m->getContent(),
                'createdAt' => $m->getCreatedAt()?->format('c'),
            ];
        }, $importantMessages);

        return [
            'market_id' => $market->getId(),
            'title' => $market->getTitle() ?? '',
            'buyer' => $market->getBuyer() ?? '',
            'description' => $market->getDescription() ?? '',
            'amount' => $market->getAmount() ?? '',
            'deadline' => $market->getDeadline()?->format('Y-m-d'),
            'products' => $products,
            'catalogue' => $catalogue,
            'chat_history' => $chatHistory,
        ];
    }

    private function buildCatalogueString(): string
    {
        $lines = [];
        foreach ($this->productRepository->findAll() as $product) {
            $lines[] = sprintf(
                "- %s (ID: %d) — %s",
                $product->getName() ?? '?',
                $product->getId() ?? 0,
                mb_substr($product->getDescription() ?? '', 0, 150)
            );
        }

        return implode("\n", $lines);
    }
}
