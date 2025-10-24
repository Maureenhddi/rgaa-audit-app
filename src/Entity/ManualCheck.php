<?php

namespace App\Entity;

use App\Repository\ManualCheckRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ManualCheckRepository::class)]
#[ORM\Table(name: 'manual_check')]
class ManualCheck
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'manualChecks')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Audit $audit = null;

    #[ORM\Column(length: 10)]
    private ?string $criteriaNumber = null;

    #[ORM\Column(length: 20)]
    private ?string $status = 'not_checked'; // not_checked, conform, non_conform

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
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

    public function getCriteriaNumber(): ?string
    {
        return $this->criteriaNumber;
    }

    public function setCriteriaNumber(string $criteriaNumber): static
    {
        $this->criteriaNumber = $criteriaNumber;

        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;
        $this->updatedAt = new \DateTimeImmutable();

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
