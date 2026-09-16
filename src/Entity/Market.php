<?php

namespace App\Entity;

use App\Repository\MarketRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MarketRepository::class)]
class Market
{
    public const PRIORITY_A = 'A';
    public const PRIORITY_B = 'B';
    public const PRIORITY_C = 'C';

    public const PRIORITIES = [
        'A' => self::PRIORITY_A,
        'B' => self::PRIORITY_B,
        'C' => self::PRIORITY_C,
    ];

    public static function priorityFromScore(?int $score): ?string
    {
        if (null === $score) {
            return null;
        }

        if ($score >= 80) {
            return self::PRIORITY_A;
        }

        if ($score >= 60) {
            return self::PRIORITY_B;
        }

        return self::PRIORITY_C;
    }

    public const STATUS_DETECTED = 'detected';
    public const STATUS_QUALIFIED = 'qualified';
    public const STATUS_GO = 'go';
    public const STATUS_TO_STUDY = 'to_study';
    public const STATUS_NOGO = 'nogo';
    public const STATUS_ANSWERED = 'answered';
    public const STATUS_WON = 'won';
    public const STATUS_LOST = 'lost';

    public const STATUSES = [
        'Detecté' => self::STATUS_DETECTED,
        'Qualifié' => self::STATUS_QUALIFIED,
        'Go' => self::STATUS_GO,
        'À étudier' => self::STATUS_TO_STUDY,
        'Nogo' => self::STATUS_NOGO,
        'Répondu' => self::STATUS_ANSWERED,
        'Gagné' => self::STATUS_WON,
        'Perdu' => self::STATUS_LOST,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, unique: true)]
    private ?string $idweb = null;

    #[ORM\Column(length: 500)]
    private ?string $title = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $buyer = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTime $deadline = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $amount = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $url = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $rawData = null;

    #[ORM\Column(length: 1, nullable: true)]
    private ?string $priority = null;

    #[ORM\Column(nullable: true)]
    private ?int $score = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_DETECTED;

    #[ORM\Column(nullable: true)]
    private ?\DateTime $createdAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $comment = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $explanation = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTime $updatedAt = null;

    /**
     * @var Collection<int, MarketProduct>
     */
    #[ORM\OneToMany(targetEntity: MarketProduct::class, mappedBy: 'market', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $marketProducts;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
        $this->marketProducts = new ArrayCollection();
    }

    public function getMarketProducts(): Collection
    {
        return $this->marketProducts;
    }

    public function addMarketProduct(MarketProduct $mp): static
    {
        if (!$this->marketProducts->contains($mp)) {
            $this->marketProducts->add($mp);
            $mp->setMarket($this);
        }

        return $this;
    }

    public function removeMarketProduct(MarketProduct $mp): static
    {
        $this->marketProducts->removeElement($mp);

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIdweb(): ?string
    {
        return $this->idweb;
    }

    public function setIdweb(string $idweb): static
    {
        $this->idweb = $idweb;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getBuyer(): ?string
    {
        return $this->buyer;
    }

    public function setBuyer(?string $buyer): static
    {
        $this->buyer = $buyer;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getDeadline(): ?\DateTime
    {
        return $this->deadline;
    }

    public function setDeadline(?\DateTime $deadline): static
    {
        $this->deadline = $deadline;

        return $this;
    }

    public function getAmount(): ?string
    {
        return $this->amount;
    }

    public function setAmount(?string $amount): static
    {
        $this->amount = $amount;

        return $this;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(?string $url): static
    {
        $this->url = $url;

        return $this;
    }

    public function getRawData(): ?string
    {
        return $this->rawData;
    }

    public function setRawData(?string $rawData): static
    {
        $this->rawData = $rawData;

        return $this;
    }

    public function getPriority(): ?string
    {
        return $this->priority;
    }

    public function setPriority(?string $priority): static
    {
        $this->priority = $priority;

        return $this;
    }

    public function getScore(): ?int
    {
        return $this->score;
    }

    public function setScore(?int $score): static
    {
        $this->score = $score;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        $this->updatedAt = new \DateTime();

        return $this;
    }

    public function getStatuslabel(): string
    {
        return match ($this->status) {
            self::STATUS_DETECTED => 'Detecté',
            self::STATUS_QUALIFIED => 'Qualifié',
            self::STATUS_GO => 'Go',
            self::STATUS_TO_STUDY => 'À étudier',
            self::STATUS_NOGO => 'Nogo',
            self::STATUS_ANSWERED => 'Répondu',
            self::STATUS_WON => 'Gagné',
            self::STATUS_LOST => 'Perdu',
            default => $this->status,
        };
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

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): static
    {
        $this->comment = $comment;

        return $this;
    }

    public function getExplanation(): ?string
    {
        return $this->explanation;
    }

    public function setExplanation(?string $explanation): static
    {
        $this->explanation = $explanation;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTime
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTime $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}
