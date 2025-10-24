<?php

namespace App\Entity;

use App\Repository\AuditRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AuditRepository::class)]
class Audit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'audits')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(length: 500)]
    private ?string $url = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2, nullable: true)]
    private ?string $conformityRate = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $summary = null;

    #[ORM\Column(length: 50)]
    private ?string $status = \App\Enum\AuditStatus::PENDING;

    #[ORM\Column(nullable: true)]
    private ?int $criticalCount = 0;

    #[ORM\Column(nullable: true)]
    private ?int $majorCount = 0;

    #[ORM\Column(nullable: true)]
    private ?int $minorCount = 0;

    #[ORM\Column(nullable: true)]
    private ?int $totalIssues = 0;

    #[ORM\Column(nullable: true)]
    private ?int $conformCriteria = 0;

    #[ORM\Column(nullable: true)]
    private ?int $nonConformCriteria = 0;

    #[ORM\Column(nullable: true)]
    private ?int $notApplicableCriteria = 0;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $nonConformDetails = null;

    #[ORM\OneToMany(targetEntity: AuditResult::class, mappedBy: 'audit', orphanRemoval: true, cascade: ['persist', 'remove'])]
    private Collection $auditResults;

    #[ORM\OneToMany(targetEntity: ManualCheck::class, mappedBy: 'audit', orphanRemoval: true, cascade: ['persist', 'remove'])]
    private Collection $manualChecks;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    public function __construct()
    {
        $this->auditResults = new ArrayCollection();
        $this->manualChecks = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(string $url): static
    {
        $this->url = $url;

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

    public function getConformityRate(): ?string
    {
        return $this->conformityRate;
    }

    public function setConformityRate(?string $conformityRate): static
    {
        $this->conformityRate = $conformityRate;

        return $this;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    public function setSummary(?string $summary): static
    {
        $this->summary = $summary;

        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getCriticalCount(): ?int
    {
        return $this->criticalCount;
    }

    public function setCriticalCount(?int $criticalCount): static
    {
        $this->criticalCount = $criticalCount;

        return $this;
    }

    public function getMajorCount(): ?int
    {
        return $this->majorCount;
    }

    public function setMajorCount(?int $majorCount): static
    {
        $this->majorCount = $majorCount;

        return $this;
    }

    public function getMinorCount(): ?int
    {
        return $this->minorCount;
    }

    public function setMinorCount(?int $minorCount): static
    {
        $this->minorCount = $minorCount;

        return $this;
    }

    public function getTotalIssues(): ?int
    {
        return $this->totalIssues;
    }

    public function setTotalIssues(?int $totalIssues): static
    {
        $this->totalIssues = $totalIssues;

        return $this;
    }

    public function getConformCriteria(): ?int
    {
        return $this->conformCriteria;
    }

    public function setConformCriteria(?int $conformCriteria): static
    {
        $this->conformCriteria = $conformCriteria;

        return $this;
    }

    public function getNonConformCriteria(): ?int
    {
        return $this->nonConformCriteria;
    }

    public function setNonConformCriteria(?int $nonConformCriteria): static
    {
        $this->nonConformCriteria = $nonConformCriteria;

        return $this;
    }

    public function getNotApplicableCriteria(): ?int
    {
        return $this->notApplicableCriteria;
    }

    public function setNotApplicableCriteria(?int $notApplicableCriteria): static
    {
        $this->notApplicableCriteria = $notApplicableCriteria;

        return $this;
    }

    /**
     * @return Collection<int, AuditResult>
     */
    public function getAuditResults(): Collection
    {
        return $this->auditResults;
    }

    public function addAuditResult(AuditResult $auditResult): static
    {
        if (!$this->auditResults->contains($auditResult)) {
            $this->auditResults->add($auditResult);
            $auditResult->setAudit($this);
        }

        return $this;
    }

    public function removeAuditResult(AuditResult $auditResult): static
    {
        if ($this->auditResults->removeElement($auditResult)) {
            if ($auditResult->getAudit() === $this) {
                $auditResult->setAudit(null);
            }
        }

        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): static
    {
        $this->errorMessage = $errorMessage;

        return $this;
    }

    public function getNonConformDetails(): ?array
    {
        return $this->nonConformDetails;
    }

    public function setNonConformDetails(?array $nonConformDetails): static
    {
        $this->nonConformDetails = $nonConformDetails;

        return $this;
    }

    /**
     * @return Collection<int, ManualCheck>
     */
    public function getManualChecks(): Collection
    {
        return $this->manualChecks;
    }

    public function addManualCheck(ManualCheck $manualCheck): static
    {
        if (!$this->manualChecks->contains($manualCheck)) {
            $this->manualChecks->add($manualCheck);
            $manualCheck->setAudit($this);
        }

        return $this;
    }

    public function removeManualCheck(ManualCheck $manualCheck): static
    {
        if ($this->manualChecks->removeElement($manualCheck)) {
            // set the owning side to null (unless already changed)
            if ($manualCheck->getAudit() === $this) {
                $manualCheck->setAudit(null);
            }
        }

        return $this;
    }
}
