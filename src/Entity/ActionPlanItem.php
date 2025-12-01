<?php

namespace App\Entity;

use App\Enum\ActionCategory;
use App\Enum\ActionSeverity;
use App\Enum\ActionStatus;
use App\Repository\ActionPlanItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ActionPlanItemRepository::class)]
#[ORM\HasLifecycleCallbacks]
class ActionPlanItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: true)]
    private ?ActionPlan $actionPlan = null;

    #[ORM\ManyToOne(inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: true)]
    private ?AnnualActionPlan $annualPlan = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 50, enumType: ActionCategory::class)]
    private ?ActionCategory $category = null;

    #[ORM\Column(length: 50, enumType: ActionSeverity::class)]
    private ?ActionSeverity $severity = null;

    #[ORM\Column]
    private ?int $priority = null; // 1-100

    #[ORM\Column(nullable: true)]
    private ?int $displayOrder = null; // Custom order for drag & drop

    #[ORM\Column]
    private ?int $year = null;

    #[ORM\Column]
    private ?int $quarter = null; // 1-4

    #[ORM\Column]
    private ?bool $quickWin = false;

    #[ORM\Column(nullable: true)]
    private ?int $estimatedEffort = null; // hours

    #[ORM\Column(nullable: true)]
    private ?int $impactScore = null; // 1-100

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $acceptanceCriteria = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $technicalDetails = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $affectedPages = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $rgaaCriteria = null;

    #[ORM\Column(length: 50, enumType: ActionStatus::class)]
    private ?ActionStatus $status = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->status = ActionStatus::PENDING;
    }

    #[ORM\PreUpdate]
    public function setUpdatedAtValue(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getActionPlan(): ?ActionPlan
    {
        return $this->actionPlan;
    }

    public function setActionPlan(?ActionPlan $actionPlan): static
    {
        $this->actionPlan = $actionPlan;

        return $this;
    }

    public function getAnnualPlan(): ?AnnualActionPlan
    {
        return $this->annualPlan;
    }

    public function setAnnualPlan(?AnnualActionPlan $annualPlan): static
    {
        $this->annualPlan = $annualPlan;

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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getCategory(): ?ActionCategory
    {
        return $this->category;
    }

    public function setCategory(ActionCategory|string $category): static
    {
        $this->category = is_string($category) ? ActionCategory::from($category) : $category;

        return $this;
    }

    public function getSeverity(): ?ActionSeverity
    {
        return $this->severity;
    }

    public function setSeverity(ActionSeverity|string $severity): static
    {
        $this->severity = is_string($severity) ? ActionSeverity::from($severity) : $severity;

        return $this;
    }

    public function getPriority(): ?int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): static
    {
        $this->priority = $priority;

        return $this;
    }

    public function getDisplayOrder(): ?int
    {
        return $this->displayOrder;
    }

    public function setDisplayOrder(?int $displayOrder): static
    {
        $this->displayOrder = $displayOrder;

        return $this;
    }

    public function getYear(): ?int
    {
        return $this->year;
    }

    public function setYear(int $year): static
    {
        $this->year = $year;

        return $this;
    }

    public function getQuarter(): ?int
    {
        return $this->quarter;
    }

    public function setQuarter(int $quarter): static
    {
        $this->quarter = $quarter;

        return $this;
    }

    public function isQuickWin(): ?bool
    {
        return $this->quickWin;
    }

    public function setQuickWin(bool $quickWin): static
    {
        $this->quickWin = $quickWin;

        return $this;
    }

    public function getEstimatedEffort(): ?int
    {
        return $this->estimatedEffort;
    }

    public function setEstimatedEffort(?int $estimatedEffort): static
    {
        $this->estimatedEffort = $estimatedEffort;

        return $this;
    }

    public function getImpactScore(): ?int
    {
        return $this->impactScore;
    }

    public function setImpactScore(?int $impactScore): static
    {
        $this->impactScore = $impactScore;

        return $this;
    }

    public function getAcceptanceCriteria(): ?string
    {
        return $this->acceptanceCriteria;
    }

    public function setAcceptanceCriteria(?string $acceptanceCriteria): static
    {
        $this->acceptanceCriteria = $acceptanceCriteria;

        return $this;
    }

    public function getTechnicalDetails(): ?string
    {
        return $this->technicalDetails;
    }

    public function setTechnicalDetails(?string $technicalDetails): static
    {
        $this->technicalDetails = $technicalDetails;

        return $this;
    }

    public function getAffectedPages(): ?array
    {
        return $this->affectedPages;
    }

    public function setAffectedPages(?array $affectedPages): static
    {
        $this->affectedPages = $affectedPages;

        return $this;
    }

    public function getRgaaCriteria(): ?array
    {
        return $this->rgaaCriteria;
    }

    public function setRgaaCriteria(?array $rgaaCriteria): static
    {
        $this->rgaaCriteria = $rgaaCriteria;

        return $this;
    }

    public function getStatus(): ?ActionStatus
    {
        return $this->status;
    }

    public function setStatus(ActionStatus|string $status): static
    {
        $statusEnum = is_string($status) ? ActionStatus::from($status) : $status;
        $this->status = $statusEnum;

        if ($statusEnum === ActionStatus::COMPLETED && !$this->completedAt) {
            $this->completedAt = new \DateTimeImmutable();
        }

        return $this;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeImmutable $completedAt): static
    {
        $this->completedAt = $completedAt;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;

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
     * Get quarter label
     */
    public function getQuarterLabel(): string
    {
        return "Q{$this->quarter} {$this->year}";
    }

    /**
     * Get category label
     */
    public function getCategoryLabel(): string
    {
        return $this->category?->getLabel() ?? 'Autre';
    }

    /**
     * Get severity badge class
     */
    public function getSeverityBadgeClass(): string
    {
        return $this->severity?->getBadgeClass() ?? 'bg-secondary';
    }

    /**
     * Get status badge class
     */
    public function getStatusBadgeClass(): string
    {
        return $this->status?->getBadgeClass() ?? 'bg-secondary';
    }

    /**
     * Get status label
     */
    public function getStatusLabel(): string
    {
        return $this->status?->getLabel() ?? 'Inconnu';
    }

    /**
     * Get severity label
     */
    public function getSeverityLabel(): string
    {
        return $this->severity?->getLabel() ?? 'Inconnu';
    }
}
