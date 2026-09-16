<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class BoampReport
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private ?\DateTime $executedAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $marketsFound = null;

    #[ORM\Column(nullable: true)]
    private ?int $marketsQualified = null;

    #[ORM\Column(nullable: true)]
    private ?int $marketsNotified = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $error = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTime $duration = null;

    public function __construct()
    {
        $this->executedAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getExecutedAt(): ?\DateTime
    {
        return $this->executedAt;
    }

    public function setExecutedAt(?\DateTime $executedAt): static
    {
        $this->executedAt = $executedAt;

        return $this;
    }

    public function getMarketsFound(): ?int
    {
        return $this->marketsFound;
    }

    public function setMarketsFound(?int $marketsFound): static
    {
        $this->marketsFound = $marketsFound;

        return $this;
    }

    public function getMarketsQualified(): ?int
    {
        return $this->marketsQualified;
    }

    public function setMarketsQualified(?int $marketsQualified): static
    {
        $this->marketsQualified = $marketsQualified;

        return $this;
    }

    public function getMarketsNotified(): ?int
    {
        return $this->marketsNotified;
    }

    public function setMarketsNotified(?int $marketsNotified): static
    {
        $this->marketsNotified = $marketsNotified;

        return $this;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function setError(?string $error): static
    {
        $this->error = $error;

        return $this;
    }

    public function getDuration(): ?\DateTime
    {
        return $this->duration;
    }

    public function setDuration(?\DateTime $duration): static
    {
        $this->duration = $duration;

        return $this;
    }

    public function getDurationSeconds(): ?int
    {
        if (null === $this->duration) {
            return null;
        }

        return (int) $this->duration->format('H') * 3600
            + (int) $this->duration->format('i') * 60
            + (int) $this->duration->format('s');
    }
}
