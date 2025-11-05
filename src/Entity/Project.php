<?php

namespace App\Entity;

use App\Repository\ProjectRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProjectRepository::class)]
class Project
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $clientName = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 7)]
    private string $color = '#016dae';

    #[ORM\Column(length: 50)]
    private string $status = 'active';

    #[ORM\ManyToOne(inversedBy: 'projects')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\OneToMany(mappedBy: 'project', targetEntity: Audit::class)]
    private Collection $audits;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    public function __construct()
    {
        $this->audits = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
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

    public function getClientName(): ?string
    {
        return $this->clientName;
    }

    public function setClientName(?string $clientName): static
    {
        $this->clientName = $clientName;
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

    public function getColor(): string
    {
        return $this->color;
    }

    public function setColor(string $color): static
    {
        $this->color = $color;
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

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    /**
     * @return Collection<int, Audit>
     */
    public function getAudits(): Collection
    {
        return $this->audits;
    }

    public function addAudit(Audit $audit): static
    {
        if (!$this->audits->contains($audit)) {
            $this->audits->add($audit);
            $audit->setProject($this);
        }

        return $this;
    }

    public function removeAudit(Audit $audit): static
    {
        if ($this->audits->removeElement($audit)) {
            if ($audit->getProject() === $this) {
                $audit->setProject(null);
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

    public function getArchivedAt(): ?\DateTimeImmutable
    {
        return $this->archivedAt;
    }

    public function setArchivedAt(?\DateTimeImmutable $archivedAt): static
    {
        $this->archivedAt = $archivedAt;
        return $this;
    }

    /**
     * Check if project is archived
     */
    public function isArchived(): bool
    {
        return $this->archivedAt !== null;
    }

    /**
     * Get audit count
     */
    public function getAuditCount(): int
    {
        return $this->audits->count();
    }

    /**
     * Get completed audits count
     */
    public function getCompletedAuditCount(): int
    {
        return $this->audits->filter(function (Audit $audit) {
            return $audit->getStatus() === \App\Enum\AuditStatus::COMPLETED;
        })->count();
    }

    /**
     * Get last audit date
     */
    public function getLastAuditDate(): ?\DateTimeImmutable
    {
        if ($this->audits->isEmpty()) {
            return null;
        }

        $lastAudit = $this->audits->toArray();
        usort($lastAudit, function (Audit $a, Audit $b) {
            return $b->getCreatedAt() <=> $a->getCreatedAt();
        });

        return $lastAudit[0]->getCreatedAt();
    }

    /**
     * Get average conformity rate
     */
    public function getAverageConformity(): ?float
    {
        $completedAudits = $this->audits->filter(function (Audit $audit) {
            return $audit->getStatus() === \App\Enum\AuditStatus::COMPLETED
                && $audit->getConformityRate() !== null;
        });

        if ($completedAudits->isEmpty()) {
            return null;
        }

        $total = 0;
        foreach ($completedAudits as $audit) {
            $total += (float) $audit->getConformityRate();
        }

        return round($total / $completedAudits->count(), 2);
    }
}
