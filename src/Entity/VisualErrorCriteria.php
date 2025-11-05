<?php

namespace App\Entity;

use App\Repository\VisualErrorCriteriaRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Mapping dynamique des types d'erreurs visuelles vers les critères WCAG/RGAA
 * Auto-appris à partir des suggestions de Gemini Vision
 */
#[ORM\Entity(repositoryClass: VisualErrorCriteriaRepository::class)]
#[ORM\Table(name: 'visual_error_criteria')]
class VisualErrorCriteria
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Type d'erreur (ex: "image-alt-irrelevant")
     */
    #[ORM\Column(length: 100, unique: true)]
    private ?string $errorType = null;

    /**
     * Critère WCAG (ex: "1.1.1")
     */
    #[ORM\Column(length: 20)]
    private ?string $wcagCriteria = null;

    /**
     * Critère RGAA (ex: "1.3")
     */
    #[ORM\Column(length: 20)]
    private ?string $rgaaCriteria = null;

    /**
     * Description du problème
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    /**
     * Nombre de fois que ce type a été détecté
     */
    #[ORM\Column]
    private int $detectionCount = 0;

    /**
     * Ajouté automatiquement par Gemini (true) ou manuellement (false)
     */
    #[ORM\Column]
    private bool $autoLearned = true;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getErrorType(): ?string
    {
        return $this->errorType;
    }

    public function setErrorType(string $errorType): static
    {
        $this->errorType = $errorType;
        return $this;
    }

    public function getWcagCriteria(): ?string
    {
        return $this->wcagCriteria;
    }

    public function setWcagCriteria(string $wcagCriteria): static
    {
        $this->wcagCriteria = $wcagCriteria;
        return $this;
    }

    public function getRgaaCriteria(): ?string
    {
        return $this->rgaaCriteria;
    }

    public function setRgaaCriteria(string $rgaaCriteria): static
    {
        $this->rgaaCriteria = $rgaaCriteria;
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

    public function getDetectionCount(): int
    {
        return $this->detectionCount;
    }

    public function setDetectionCount(int $detectionCount): static
    {
        $this->detectionCount = $detectionCount;
        return $this;
    }

    public function incrementDetectionCount(): static
    {
        $this->detectionCount++;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function isAutoLearned(): bool
    {
        return $this->autoLearned;
    }

    public function setAutoLearned(bool $autoLearned): static
    {
        $this->autoLearned = $autoLearned;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }
}
