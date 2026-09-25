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
 * Python endpoint to generate a positioning report for a given market.
 */
class MarketReportService
{
    private const AGENT_URL = 'http://127.0.0.1:8000';

    private const TIMEOUT_START = 10;
    private const TIMEOUT_POLL = 10;

    public function __construct(
        private \Symfony\Contracts\HttpClient\HttpClientInterface $httpClient,
        private \Psr\Log\LoggerInterface $logger,
        private MarketChatMessageRepository $chatRepo,
        private ProductRepository $productRepository,
    ) {
    }

    /**
     * Start a report generation job.
     *
     * @return string|null Job ID or null on error
     */
    public function startReport(Market $market): ?string
    {
        $payload = $this->buildPayload($market);

        try {
            $response = $this->httpClient->request('POST', self::AGENT_URL . '/report', [
                'json' => $payload,
                'timeout' => self::TIMEOUT_START,
            ]);

            $data = $response->toArray();

            return $data['job_id'] ?? null;
        } catch (\Throwable $e) {
            $this->logger->error('MarketReportService: failed to start report job', [
                'error' => $e->getMessage(),
                'market_id' => $market->getId(),
            ]);

            return null;
        }
    }

    /**
     * Get report job result (single check, no polling).
     *
     * @return array{status: string, result?: array}
     */
    public function getResult(string $jobId): array
    {
        try {
            $response = $this->httpClient->request('GET', self::AGENT_URL . '/report/result/' . $jobId, [
                'timeout' => self::TIMEOUT_POLL,
            ]);

            return $response->toArray();
        } catch (\Throwable $e) {
            $this->logger->warning('MarketReportService: failed to get report result', [
                'error' => $e->getMessage(),
                'job_id' => $jobId,
            ]);

            return ['status' => 'error', 'result' => ['error' => $e->getMessage()]];
        }
    }

    public function isHealthy(): bool
    {
        try {
            $response = $this->httpClient->request('GET', self::AGENT_URL . '/health', [
                'timeout' => 5,
            ]);

            return 200 === $response->getStatusCode();
        } catch (\Throwable) {
            return false;
        }
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
