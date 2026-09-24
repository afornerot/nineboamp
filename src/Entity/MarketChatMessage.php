<?php

namespace App\Entity;

use App\Repository\MarketChatMessageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MarketChatMessageRepository::class)]
class MarketChatMessage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Market::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Market $market = null;

    #[ORM\Column(length: 20)]
    private string $role;

    #[ORM\Column(type: 'text')]
    private string $content;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $sources = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $debugData = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTime $createdAt = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $important = true;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->createdAt->setTimezone(new \DateTimeZone('Europe/Paris'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMarket(): ?Market
    {
        return $this->market;
    }

    public function setMarket(?Market $market): static
    {
        $this->market = $market;

        return $this;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;

        return $this;
    }

    public function getSources(): ?array
    {
        return $this->sources;
    }

    public function setSources(?array $sources): static
    {
        $this->sources = $sources;

        return $this;
    }

    public function getDebugData(): ?array
    {
        return $this->debugData;
    }

    public function setDebugData(?array $debugData): static
    {
        $this->debugData = $debugData;

        return $this;
    }

    public function getCreatedAt(): ?\DateTime
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?\DateTime $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function isImportant(): bool
    {
        return $this->important;
    }

    public function setImportant(bool $important): static
    {
        $this->important = $important;

        return $this;
    }
}
