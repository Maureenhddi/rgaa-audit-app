<?php

namespace App\Entity;

use App\Repository\AnnualActionPlanRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AnnualActionPlanRepository::class)]
#[ORM\HasLifecycleCallbacks]
class AnnualActionPlan
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'annualPlans')]
    #[ORM\JoinColumn(nullable: false)]
    private ?ActionPlan $pluriAnnualPlan = null;

    #[ORM\Column]
    private ?int $year = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\OneToMany(mappedBy: 'annualPlan', targetEntity: ActionPlanItem::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $items;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->items = new ArrayCollection();
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

    public function getPluriAnnualPlan(): ?ActionPlan
    {
        return $this->pluriAnnualPlan;
    }

    public function setPluriAnnualPlan(?ActionPlan $pluriAnnualPlan): static
    {
        $this->pluriAnnualPlan = $pluriAnnualPlan;

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
            $item->setAnnualPlan($this);
        }

        return $this;
    }

    public function removeItem(ActionPlanItem $item): static
    {
        if ($this->items->removeElement($item)) {
            // set the owning side to null (unless already changed)
            if ($item->getAnnualPlan() === $this) {
                $item->setAnnualPlan(null);
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
     * Get completion percentage for this annual plan
     */
    public function getCompletionPercentage(): float
    {
        $totalItems = $this->items->count();
        if ($totalItems === 0) {
            return 0;
        }

        $completedItems = $this->items->filter(fn($item) => $item->getStatus()->value === 'completed')->count();

        return round(($completedItems / $totalItems) * 100, 2);
    }

    /**
     * Get total estimated effort in hours
     */
    public function getTotalEstimatedEffort(): int
    {
        $total = 0;
        foreach ($this->items as $item) {
            $total += $item->getEstimatedEffort() ?? 0;
        }
        return $total;
    }

    /**
     * Get critical issues count
     */
    public function getCriticalCount(): int
    {
        return $this->items->filter(fn($item) => $item->getSeverity()->value === 'critical')->count();
    }

    /**
     * Get major issues count
     */
    public function getMajorCount(): int
    {
        return $this->items->filter(fn($item) => $item->getSeverity()->value === 'major')->count();
    }

    /**
     * Get minor issues count
     */
    public function getMinorCount(): int
    {
        return $this->items->filter(fn($item) => $item->getSeverity()->value === 'minor')->count();
    }

    /**
     * Get quick wins count
     */
    public function getQuickWinsCount(): int
    {
        return $this->items->filter(fn($item) => $item->isQuickWin())->count();
    }
}
