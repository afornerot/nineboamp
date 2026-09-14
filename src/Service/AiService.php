<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AiService
{
    private const MAX_TEMPERATURE = 2.0;

    private const MAX_TOKENS = 100_000;

    public function __construct(
        #[Autowire(param: 'aiProvider')]
        private string $provider,
        #[Autowire(param: 'aiModel')]
        private string $model,
        #[Autowire(param: 'aiApiKey')]
        private string $apiKey,
        #[Autowire(param: 'aiBaseUrl')]
        private string $baseUrl,
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
    ) {
    }

    public function ask(
        string $prompt,
        ?string $system = null,
        float $temperature = 0.7,
        int $maxTokens = 1024,
    ): string {
        $messages = [];

        if ($system) {
            $messages[] = ['role' => 'system', 'content' => $system];
        }

        $messages[] = ['role' => 'user', 'content' => $prompt];

        $data = $this->request('/chat/completions', [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => max(0.0, min(self::MAX_TEMPERATURE, $temperature)),
            'max_tokens' => max(1, min(self::MAX_TOKENS, $maxTokens)),
        ]);

        if (isset($data['error'])) {
            $message = is_string($data['error']) ? $data['error'] : (string) ($data['error']['message'] ?? 'erreur inconnue');
            $this->logger->error('AiService: erreur API LLM', ['error' => $message]);

            return '';
        }

        $content = $data['choices'][0]['message']['content'] ?? '';

        if ('' === $content) {
            $this->logger->error('AiService: réponse IA vide ou inattendue', ['keys' => array_keys($data)]);
        }

        return is_string($content) ? $content : '';
    }

    /**
     * Conversation multi-tours : $messages = [['role' => 'system'|'user'|'assistant', 'content' => '...']].
     *
     * @param array<int, array{role: string, content: string}> $messages
     */
    public function askMessages(array $messages, float $temperature = 0.7, int $maxTokens = 1024): string
    {
        $data = $this->request('/chat/completions', [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => max(0.0, min(self::MAX_TEMPERATURE, $temperature)),
            'max_tokens' => max(1, min(self::MAX_TOKENS, $maxTokens)),
        ]);

        if (isset($data['error'])) {
            $message = is_string($data['error']) ? $data['error'] : (string) ($data['error']['message'] ?? 'erreur inconnue');
            $this->logger->error('AiService: erreur API LLM', ['error' => $message]);

            return '';
        }

        $content = $data['choices'][0]['message']['content'] ?? '';

        if ('' === $content) {
            $this->logger->error('AiService: réponse IA vide ou inattendue', ['keys' => array_keys($data)]);
        }

        return is_string($content) ? $content : '';
    }

    public function prompt(string $prompt): string
    {
        return $this->ask($prompt);
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function request(string $endpoint, array $payload): array
    {
        try {
            $response = $this->httpClient->request('POST', $this->baseUrl.$endpoint, [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->apiKey,
                ],
                'json' => $payload,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('AiService: appels HTTP IA en échec', ['endpoint' => $endpoint, 'error' => $e->getMessage()]);

            return [];
        }

        try {
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->error('AiService: réponse IA illisible', ['endpoint' => $endpoint, 'error' => $e->getMessage()]);

            return [];
        }

        return (array) $data;
    }
}
