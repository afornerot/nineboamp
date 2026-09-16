<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class RocketChatNotifier
{
    public function __construct(
        #[Autowire(env: 'ROCKETCHAT_URL')]
        private string $serverUrl,
        #[Autowire(env: 'ROCKETCHAT_USER_ID')]
        private string $userId,
        #[Autowire(env: 'ROCKETCHAT_AUTH_TOKEN')]
        private string $authToken,
        #[Autowire(env: 'ROCKETCHAT_CHANNEL')]
        private string $channel,
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
    ) {
    }

    public function sendMarket(string $idweb, string $title, string $priority, int $score, string $buyer, string $description, string $relevance, string $deadline, string $amount, array $products, string $url): bool
    {
        $text = $this->formatMessage($idweb, $title, $priority, $score, $buyer, $description, $relevance, $deadline, $amount, $products, $url);

        try {
            $response = $this->httpClient->request('POST', rtrim($this->serverUrl, '/') . '/api/v1/chat.postMessage', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Auth-Token' => $this->authToken,
                    'X-User-Id' => $this->userId,
                ],
                'json' => [
                    'channel' => $this->channel,
                    'text' => $text,
                ],
            ]);

            $data = $response->toArray();

            if (!($data['success'] ?? false)) {
                $this->logger->error('RocketChatNotifier: envoi échoué', ['error' => $data['error'] ?? 'unknown']);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('RocketChatNotifier: exception', [
                'error' => $e->getMessage(),
                'idweb' => $idweb,
            ]);

            return false;
        }
    }

    /**
     * @param string[] $products
     */
    private function formatMessage(string $idweb, string $title, string $priority, int $score, string $buyer, string $description, string $relevance, string $deadline, string $amount, array $products, string $url): string
    {
        $productsList = implode(', ', $products);

        return <<<TEXT
**[Priorité {$priority} — {$score}/100] {$title}**

**Acheteur** : {$buyer}
{$description}

**Pertinence** : {$relevance}

Date limite : ** {$deadline}** — Montant : **{$amount}**
**Produits** : **{$productsList}**
{$url}
TEXT;
    }
}
