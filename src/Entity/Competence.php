<?php

namespace App\Entity;

use App\Repository\CompetenceRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Élément du contexte entreprise injecté dans les prompts LLM (placeholder
 * {{competences}}) : présentation, savoir-faire, offre, ou exclusion.
 */
#[ORM\Entity(repositoryClass: CompetenceRepository::class)]
class Competence
{
    public const CATEGORY_PRESENTATION = 'presentation';
    public const CATEGORY_SAVOIR_FAIRE = 'savoir_faire';
    public const CATEGORY_OFFRE = 'offre';
    public const CATEGORY_EXCLUSION = 'exclusion';

    /**
     * Catégories dans l'ordre de rendu du prompt, avec leur libellé.
     */
    public const CATEGORIES = [
        self::CATEGORY_PRESENTATION => 'Présentation',
        self::CATEGORY_SAVOIR_FAIRE => 'Savoir-faire',
        self::CATEGORY_OFFRE => 'Ce que nous pouvons offrir',
        self::CATEGORY_EXCLUSION => 'Ce que nous ne faisons pas',
    ];

    public const LABEL_MAX_LENGTH = 500;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32)]
    #[Assert\Choice(choices: [self::CATEGORY_PRESENTATION, self::CATEGORY_SAVOIR_FAIRE, self::CATEGORY_OFFRE, self::CATEGORY_EXCLUSION])]
    private string $category = self::CATEGORY_SAVOIR_FAIRE;

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank(message: 'Le libellé est obligatoire.', normalizer: 'trim')]
    #[Assert\Length(max: self::LABEL_MAX_LENGTH, maxMessage: 'Le libellé ne doit pas dépasser {{ limit }} caractères.')]
    private ?string $label = null;

    #[ORM\Column]
    #[Assert\NotNull(message: 'L\'ordre est obligatoire.')]
    private ?int $position = 0;

    #[ORM\Column]
    private bool $active = true;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function setCategory(string $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getCategoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position ?? 0;
    }

    public function setPosition(?int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }
}
