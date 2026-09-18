<?php

namespace App\Service;

use Psr\Log\LoggerInterface;

class PromptLoader
{
    private string $promptsDir;

    /**
     * @var array<string, Prompt>
     */
    private array $cache = [];

    public function __construct(
        private LoggerInterface $logger,
        string $promptsDir = '',
    ) {
        if ('' === $promptsDir) {
            $promptsDir = dirname(__DIR__, 2) . '/src/DataFixtures/data/scoring';
        }
        $this->promptsDir = $promptsDir;
    }

    public function getPromptsDirectory(): string
    {
        return $this->promptsDir;
    }

    /**
     * Charge un prompt par nom (sans extension). Retourne null si introuvable.
     */
    public function load(string $name): ?Prompt
    {
        if (isset($this->cache[$name])) {
            return $this->cache[$name];
        }

        $path = $this->promptsDir . '/' . $name . '.md';
        if (!is_file($path)) {
            $this->logger->warning('PromptLoader: fichier manquant', [
                'name' => $name,
                'path' => $path,
            ]);

            return null;
        }

        $raw = @file_get_contents($path);
        if (false === $raw) {
            $this->logger->warning('PromptLoader: fichier illisible', ['name' => $name]);

            return null;
        }

        [$metadata, $body] = $this->parseFrontMatter($raw);

        $prompt = new Prompt($name, $path, $body, $metadata);
        $this->cache[$name] = $prompt;

        return $prompt;
    }

    /**
     * Charge un prompt ou retourne un fallback.
     */
    public function loadOrFallback(string $name, string $fallbackName): ?Prompt
    {
        return $this->load($name) ?? $this->load($fallbackName);
    }

    /**
     * Rend un prompt utilisateur avec substitution de placeholders.
     * Format placeholder : {{key}}.
     *
     * @param array<string, string> $vars
     */
    public function renderUser(string $name, array $vars, string $fallback = ''): string
    {
        $prompt = $this->load($name);
        if (null === $prompt) {
            return $fallback;
        }

        return $this->applyVars($prompt->getBody(), $vars);
    }

    /**
     * Rend un prompt système.
     */
    public function renderSystem(string $name, string $fallback = ''): string
    {
        $prompt = $this->load($name);
        if (null === $prompt) {
            return $fallback;
        }

        return $prompt->getBody();
    }

    public function clearCache(): void
    {
        $this->cache = [];
    }

    /**
     * Parse le front-matter (entre `---` et `---`) et retourne [metadata, body].
     * Utilise un parser clé: valeur simple adapté à notre format.
     *
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function parseFrontMatter(string $raw): array
    {
        $raw = ltrim($raw);
        if (!str_starts_with($raw, '---')) {
            return [[], $raw];
        }

        $end = strpos($raw, "\n---", 3);
        if (false === $end) {
            return [[], $raw];
        }

        $yamlContent = substr($raw, 3, $end - 3);
        $body = ltrim(substr($raw, $end + 4));

        $parsed = $this->parseSimpleYaml($yamlContent);

        return [$parsed, $body];
    }

    /**
     * Parse un front-matter YAML simple : lines "key: value" uniquement.
     * Supporte les booléens et les nombres mais pas les arrays/objects imbriqués.
     *
     * @return array<string, mixed>
     */
    private function parseSimpleYaml(string $yaml): array
    {
        $result = [];
        $currentKey = null;
        foreach (preg_split('/\r?\n/', $yaml) as $line) {
            if ('' === trim($line)) {
                continue;
            }
            if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_-]*)\s*:\s*(.*)$/m', $line, $m)) {
                $key = $m[1];
                $value = trim($m[2]);
                $result[$key] = $this->castYamlValue($value);
                $currentKey = $key;
            }
        }

        return $result;
    }

    /**
     * Convertit une valeur YAML scalaire en type PHP.
     */
    private function castYamlValue(string $value): mixed
    {
        if ('' === $value) {
            return '';
        }
        if ('true' === $value || 'True' === $value) {
            return true;
        }
        if ('false' === $value || 'False' === $value) {
            return false;
        }
        if (preg_match('/^-?\d+$/', $value)) {
            return (int) $value;
        }
        if (preg_match('/^-?\d+\.\d+$/', $value)) {
            return (float) $value;
        }

        return $value;
    }

    /**
     * @param array<string, string> $vars
     */
    private function applyVars(string $template, array $vars): string
    {
        $result = $template;
        foreach ($vars as $key => $value) {
            $result = str_replace('{{' . $key . '}}', (string) $value, $result);
        }

        return $result;
    }
}
