<?php

namespace App\Entity;

use App\Repository\AuditCampaignRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AuditCampaignRepository::class)]
class AuditCampaign
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'auditCampaigns')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Project $project = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $startDate = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $endDate = null;

    #[ORM\Column(length: 50)]
    private string $status = 'draft'; // draft, in_progress, completed, archived

    #[ORM\Column(length: 50)]
    private string $sampleType = 'custom'; // representative, exhaustive, custom

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2, nullable: true)]
    private ?string $avgConformityRate = null;

    #[ORM\Column(nullable: true)]
    private ?int $totalPages = 0;

    #[ORM\Column(nullable: true)]
    private ?int $totalIssues = 0;

    #[ORM\Column(nullable: true)]
    private ?int $criticalCount = 0;

    #[ORM\Column(nullable: true)]
    private ?int $majorCount = 0;

    #[ORM\Column(nullable: true)]
    private ?int $minorCount = 0;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $reportPdfPath = null;

    #[ORM\OneToMany(targetEntity: Audit::class, mappedBy: 'campaign', orphanRemoval: true, cascade: ['persist', 'remove'])]
    private Collection $pageAudits;

    /**
     * @var Collection<int, ActionPlan>
     */
    #[ORM\OneToMany(targetEntity: ActionPlan::class, mappedBy: 'campaign', orphanRemoval: true, cascade: ['persist', 'remove'])]
    private Collection $actionPlans;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->pageAudits = new ArrayCollection();
        $this->actionPlans = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->startDate = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function setProject(?Project $project): static
    {
        $this->project = $project;
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
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

    public function getStartDate(): ?\DateTimeImmutable
    {
        return $this->startDate;
    }

    public function setStartDate(\DateTimeImmutable $startDate): static
    {
        $this->startDate = $startDate;
        return $this;
    }

    public function getEndDate(): ?\DateTimeImmutable
    {
        return $this->endDate;
    }

    public function setEndDate(?\DateTimeImmutable $endDate): static
    {
        $this->endDate = $endDate;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getSampleType(): string
    {
        return $this->sampleType;
    }

    public function setSampleType(string $sampleType): static
    {
        $this->sampleType = $sampleType;
        return $this;
    }

    public function getAvgConformityRate(): ?string
    {
        return $this->avgConformityRate;
    }

    public function setAvgConformityRate(?string $avgConformityRate): static
    {
        $this->avgConformityRate = $avgConformityRate;
        return $this;
    }

    public function getTotalPages(): ?int
    {
        return $this->totalPages;
    }

    public function setTotalPages(?int $totalPages): static
    {
        $this->totalPages = $totalPages;
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

    public function getReportPdfPath(): ?string
    {
        return $this->reportPdfPath;
    }

    public function setReportPdfPath(?string $reportPdfPath): static
    {
        $this->reportPdfPath = $reportPdfPath;
        return $this;
    }

    /**
     * @return Collection<int, Audit>
     */
    public function getPageAudits(): Collection
    {
        return $this->pageAudits;
    }

    public function addPageAudit(Audit $pageAudit): static
    {
        if (!$this->pageAudits->contains($pageAudit)) {
            $this->pageAudits->add($pageAudit);
            $pageAudit->setCampaign($this);
        }

        return $this;
    }

    public function removePageAudit(Audit $pageAudit): static
    {
        if ($this->pageAudits->removeElement($pageAudit)) {
            if ($pageAudit->getCampaign() === $this) {
                $pageAudit->setCampaign(null);
            }
        }

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

    /**
     * Get completed page audits count
     */
    public function getCompletedPageAuditsCount(): int
    {
        return $this->pageAudits->filter(function (Audit $audit) {
            return $audit->getStatus() === \App\Enum\AuditStatus::COMPLETED;
        })->count();
    }

    /**
     * Get pending page audits count
     */
    public function getPendingPageAuditsCount(): int
    {
        return $this->pageAudits->filter(function (Audit $audit) {
            return in_array($audit->getStatus(), [\App\Enum\AuditStatus::PENDING, \App\Enum\AuditStatus::RUNNING]);
        })->count();
    }

    /**
     * Calculate and update statistics from page audits
     */
    public function recalculateStatistics(): void
    {
        $completedAudits = $this->pageAudits->filter(function (Audit $audit) {
            return $audit->getStatus() === \App\Enum\AuditStatus::COMPLETED;
        });

        $this->totalPages = $this->pageAudits->count();

        if ($completedAudits->isEmpty()) {
            $this->avgConformityRate = null;
            $this->totalIssues = 0;
            $this->criticalCount = 0;
            $this->majorCount = 0;
            $this->minorCount = 0;
            return;
        }

        // Calculate averages and sums
        $totalConformity = 0;
        $totalIssues = 0;
        $totalCritical = 0;
        $totalMajor = 0;
        $totalMinor = 0;

        foreach ($completedAudits as $audit) {
            if ($audit->getConformityRate()) {
                $totalConformity += (float) $audit->getConformityRate();
            }
            $totalIssues += $audit->getTotalIssues() ?? 0;
            $totalCritical += $audit->getCriticalCount() ?? 0;
            $totalMajor += $audit->getMajorCount() ?? 0;
            $totalMinor += $audit->getMinorCount() ?? 0;
        }

        $count = $completedAudits->count();
        $this->avgConformityRate = $count > 0 ? (string) round($totalConformity / $count, 2) : null;
        $this->totalIssues = $totalIssues;
        $this->criticalCount = $totalCritical;
        $this->majorCount = $totalMajor;
        $this->minorCount = $totalMinor;
    }

    /**
     * Check if campaign is archived
     */
    public function isArchived(): bool
    {
        return $this->status === 'archived';
    }

    /**
     * Check if campaign is completed
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Check if all pages are audited
     */
    public function areAllPagesAudited(): bool
    {
        if ($this->pageAudits->isEmpty()) {
            return false;
        }

        foreach ($this->pageAudits as $audit) {
            if ($audit->getStatus() !== \App\Enum\AuditStatus::COMPLETED) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return Collection<int, ActionPlan>
     */
    public function getActionPlans(): Collection
    {
        return $this->actionPlans;
    }

    public function addActionPlan(ActionPlan $actionPlan): static
    {
        if (!$this->actionPlans->contains($actionPlan)) {
            $this->actionPlans->add($actionPlan);
            $actionPlan->setCampaign($this);
        }

        return $this;
    }

    public function removeActionPlan(ActionPlan $actionPlan): static
    {
        if ($this->actionPlans->removeElement($actionPlan)) {
            // set the owning side to null (unless already changed)
            if ($actionPlan->getCampaign() === $this) {
                $actionPlan->setCampaign(null);
            }
        }

        return $this;
    }

    /**
     * Check if campaign has at least one completed audit
     */
    public function hasCompletedAudits(): bool
    {
        foreach ($this->pageAudits as $audit) {
            if ($audit->getStatus() === \App\Enum\AuditStatus::COMPLETED) {
                return true;
            }
        }

        return false;
    }
}
