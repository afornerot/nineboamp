<?php

namespace App\Service;

class Prompt
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private readonly string $name,
        private readonly string $path,
        private readonly string $body,
        private readonly array $metadata,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getMetadataValue(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    public function getTemperature(): float
    {
        return (float) ($this->metadata['temperature'] ?? 0.2);
    }

    public function getMaxTokens(): int
    {
        return (int) ($this->metadata['max_tokens'] ?? 1024);
    }
}
