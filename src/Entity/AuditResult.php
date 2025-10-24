<?php

namespace App\Entity;

use App\Repository\AuditResultRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AuditResultRepository::class)]
class AuditResult
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'auditResults')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Audit $audit = null;

    #[ORM\Column(length: 100)]
    private ?string $errorType = null;

    #[ORM\Column(length: 50)]
    private ?string $severity = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $description = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $recommendation = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $codeFix = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $selector = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $context = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $wcagCriteria = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $rgaaCriteria = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $impactUser = null;

    #[ORM\Column(length: 50)]
    private ?string $source = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAudit(): ?Audit
    {
        return $this->audit;
    }

    public function setAudit(?Audit $audit): static
    {
        $this->audit = $audit;

        return $this;
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

    public function getSeverity(): ?string
    {
        return $this->severity;
    }

    public function setSeverity(string $severity): static
    {
        $this->severity = $severity;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getRecommendation(): ?string
    {
        return $this->recommendation;
    }

    public function setRecommendation(?string $recommendation): static
    {
        $this->recommendation = $recommendation;

        return $this;
    }

    public function getCodeFix(): ?string
    {
        return $this->codeFix;
    }

    public function setCodeFix(?string $codeFix): static
    {
        $this->codeFix = $codeFix;

        return $this;
    }

    public function getSelector(): ?string
    {
        return $this->selector;
    }

    public function setSelector(?string $selector): static
    {
        $this->selector = $selector;

        return $this;
    }

    public function getContext(): ?string
    {
        return $this->context;
    }

    public function setContext(?string $context): static
    {
        $this->context = $context;

        return $this;
    }

    public function getWcagCriteria(): ?string
    {
        return $this->wcagCriteria;
    }

    public function setWcagCriteria(?string $wcagCriteria): static
    {
        $this->wcagCriteria = $wcagCriteria;

        return $this;
    }

    public function getRgaaCriteria(): ?string
    {
        return $this->rgaaCriteria;
    }

    public function setRgaaCriteria(?string $rgaaCriteria): static
    {
        $this->rgaaCriteria = $rgaaCriteria;

        return $this;
    }

    public function getImpactUser(): ?string
    {
        return $this->impactUser;
    }

    public function setImpactUser(?string $impactUser): static
    {
        $this->impactUser = $impactUser;

        return $this;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function setSource(string $source): static
    {
        $this->source = $source;

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
}
