<?php

namespace App\Entity;

use App\Repository\ActionPlanRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ActionPlanRepository::class)]
#[ORM\HasLifecycleCallbacks]
class ActionPlan
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\ManyToOne(inversedBy: 'actionPlans')]
    #[ORM\JoinColumn(nullable: false)]
    private ?AuditCampaign $campaign = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTimeInterface $startDate = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTimeInterface $endDate = null;

    #[ORM\Column]
    private ?int $durationYears = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2, nullable: true)]
    private ?string $currentConformityRate = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2, nullable: true)]
    private ?string $targetConformityRate = null;

    #[ORM\Column]
    private ?int $totalIssues = null;

    #[ORM\Column]
    private ?int $criticalIssues = null;

    #[ORM\Column]
    private ?int $majorIssues = null;

    #[ORM\Column]
    private ?int $minorIssues = null;

    /**
     * @var Collection<int, ActionPlanItem>
     * @deprecated Use annualPlans instead - kept for backward compatibility
     */
    #[ORM\OneToMany(targetEntity: ActionPlanItem::class, mappedBy: 'actionPlan', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['priority' => 'ASC', 'quarter' => 'ASC'])]
    private Collection $items;

    /**
     * @var Collection<int, AnnualActionPlan>
     */
    #[ORM\OneToMany(targetEntity: AnnualActionPlan::class, mappedBy: 'pluriAnnualPlan', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['year' => 'ASC'])]
    private Collection $annualPlans;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $executiveSummary = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $strategicOrientations = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $progressAxes = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $annualObjectives = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $resources = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $indicators = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(length: 50)]
    private ?string $status = 'draft';

    public function __construct()
    {
        $this->items = new ArrayCollection();
        $this->annualPlans = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
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

    public function getCampaign(): ?AuditCampaign
    {
        return $this->campaign;
    }

    public function setCampaign(?AuditCampaign $campaign): static
    {
        $this->campaign = $campaign;

        return $this;
    }

    public function getStartDate(): ?\DateTimeInterface
    {
        return $this->startDate;
    }

    public function setStartDate(\DateTimeInterface $startDate): static
    {
        $this->startDate = $startDate;

        return $this;
    }

    public function getEndDate(): ?\DateTimeInterface
    {
        return $this->endDate;
    }

    public function setEndDate(\DateTimeInterface $endDate): static
    {
        $this->endDate = $endDate;

        return $this;
    }

    public function getDurationYears(): ?int
    {
        return $this->durationYears;
    }

    public function setDurationYears(int $durationYears): static
    {
        $this->durationYears = $durationYears;

        return $this;
    }

    public function getCurrentConformityRate(): ?string
    {
        return $this->currentConformityRate;
    }

    public function setCurrentConformityRate(?string $currentConformityRate): static
    {
        $this->currentConformityRate = $currentConformityRate;

        return $this;
    }

    public function getTargetConformityRate(): ?string
    {
        return $this->targetConformityRate;
    }

    public function setTargetConformityRate(?string $targetConformityRate): static
    {
        $this->targetConformityRate = $targetConformityRate;

        return $this;
    }

    public function getTotalIssues(): ?int
    {
        return $this->totalIssues;
    }

    public function setTotalIssues(int $totalIssues): static
    {
        $this->totalIssues = $totalIssues;

        return $this;
    }

    public function getCriticalIssues(): ?int
    {
        return $this->criticalIssues;
    }

    public function setCriticalIssues(int $criticalIssues): static
    {
        $this->criticalIssues = $criticalIssues;

        return $this;
    }

    public function getMajorIssues(): ?int
    {
        return $this->majorIssues;
    }

    public function setMajorIssues(int $majorIssues): static
    {
        $this->majorIssues = $majorIssues;

        return $this;
    }

    public function getMinorIssues(): ?int
    {
        return $this->minorIssues;
    }

    public function setMinorIssues(int $minorIssues): static
    {
        $this->minorIssues = $minorIssues;

        return $this;
    }

    /**
     * @return Collection<int, ActionPlanItem>
     */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(ActionPlanItem $item): static
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setActionPlan($this);
        }

        return $this;
    }

    public function removeItem(ActionPlanItem $item): static
    {
        if ($this->items->removeElement($item)) {
            // set the owning side to null (unless already changed)
            if ($item->getActionPlan() === $this) {
                $item->setActionPlan(null);
            }
        }

        return $this;
    }

    public function getExecutiveSummary(): ?string
    {
        return $this->executiveSummary;
    }

    public function setExecutiveSummary(?string $executiveSummary): static
    {
        $this->executiveSummary = $executiveSummary;

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

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    /**
     * Get items by quarter
     */
    public function getItemsByQuarter(int $year, int $quarter): Collection
    {
        return $this->items->filter(function (ActionPlanItem $item) use ($year, $quarter) {
            return $item->getYear() === $year && $item->getQuarter() === $quarter;
        });
    }

    /**
     * Get quick win items
     */
    public function getQuickWins(): Collection
    {
        return $this->items->filter(function (ActionPlanItem $item) {
            return $item->isQuickWin();
        });
    }

    /**
     * Get completion percentage
     */
    public function getCompletionPercentage(): float
    {
        $total = $this->items->count();
        if ($total === 0) {
            return 0.0;
        }

        $completed = $this->items->filter(function (ActionPlanItem $item) {
            return $item->getStatus() === \App\Enum\ActionStatus::COMPLETED;
        })->count();

        return round(($completed / $total) * 100, 2);
    }

    /**
     * @return Collection<int, AnnualActionPlan>
     */
    public function getAnnualPlans(): Collection
    {
        return $this->annualPlans;
    }

    public function addAnnualPlan(AnnualActionPlan $annualPlan): static
    {
        if (!$this->annualPlans->contains($annualPlan)) {
            $this->annualPlans->add($annualPlan);
            $annualPlan->setPluriAnnualPlan($this);
        }

        return $this;
    }

    public function removeAnnualPlan(AnnualActionPlan $annualPlan): static
    {
        if ($this->annualPlans->removeElement($annualPlan)) {
            if ($annualPlan->getPluriAnnualPlan() === $this) {
                $annualPlan->setPluriAnnualPlan(null);
            }
        }

        return $this;
    }

    public function getStrategicOrientations(): ?array
    {
        return $this->strategicOrientations;
    }

    public function setStrategicOrientations(?array $strategicOrientations): static
    {
        $this->strategicOrientations = $strategicOrientations;

        return $this;
    }

    public function getProgressAxes(): ?array
    {
        return $this->progressAxes;
    }

    public function setProgressAxes(?array $progressAxes): static
    {
        $this->progressAxes = $progressAxes;

        return $this;
    }

    public function getAnnualObjectives(): ?array
    {
        return $this->annualObjectives;
    }

    public function setAnnualObjectives(?array $annualObjectives): static
    {
        $this->annualObjectives = $annualObjectives;

        return $this;
    }

    public function getResources(): ?array
    {
        return $this->resources;
    }

    public function setResources(?array $resources): static
    {
        $this->resources = $resources;

        return $this;
    }

    public function getIndicators(): ?array
    {
        return $this->indicators;
    }

    public function setIndicators(?array $indicators): static
    {
        $this->indicators = $indicators;

        return $this;
    }
}
