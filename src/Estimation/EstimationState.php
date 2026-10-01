<?php

namespace App\Estimation;

use Doctrine\ORM\Mapping as ORM;

/** Status and retry bookkeeping shared by meals and exercises ({@see Estimable}). */
trait EstimationState
{
    #[ORM\Column(length: 20, enumType: EstimationStatus::class, options: ['default' => 'estimated'])]
    private EstimationStatus $status = EstimationStatus::Pending;

    /** Who produced the numbers (e.g. "gemini:gemini-3.5-flash-lite", "random", "user"). Null until estimated. */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $estimatedBy = null;

    /** How many times estimation has been tried (automatic and manual). */
    #[ORM\Column(options: ['default' => 0])]
    private int $estimationAttempts = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastEstimationAttemptAt = null;

    /** Technical reason of the last failure, for logs/support. Not shown to the user verbatim. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastEstimationError = null;

    public function estimationFailed(string $error, \DateTimeImmutable $at, bool $giveUp): void
    {
        $this->recordAttempt($at);
        $this->lastEstimationError = $error;
        $this->status = $giveUp ? EstimationStatus::Failed : EstimationStatus::Pending;
    }

    /** Call when numbers were applied successfully. */
    private function estimationSucceeded(string $estimatedBy, \DateTimeImmutable $at): void
    {
        $this->recordAttempt($at);
        $this->estimatedBy = $estimatedBy;
        $this->lastEstimationError = null;
        $this->status = EstimationStatus::Estimated;
    }

    private function recordAttempt(\DateTimeImmutable $at): void
    {
        ++$this->estimationAttempts;
        $this->lastEstimationAttemptAt = $at;
    }

    public function getStatus(): EstimationStatus
    {
        return $this->status;
    }

    public function isPending(): bool
    {
        return EstimationStatus::Pending === $this->status;
    }

    public function isFailed(): bool
    {
        return EstimationStatus::Failed === $this->status;
    }

    public function getEstimatedBy(): ?string
    {
        return $this->estimatedBy;
    }

    public function getEstimationAttempts(): int
    {
        return $this->estimationAttempts;
    }

    public function getLastEstimationAttemptAt(): ?\DateTimeImmutable
    {
        return $this->lastEstimationAttemptAt;
    }

    public function getLastEstimationError(): ?string
    {
        return $this->lastEstimationError;
    }
}
