<?php

namespace App\Estimation;

use App\Entity\User;

/** Something the user describes in words and the AI turns into numbers (a meal, an exercise). */
interface Estimable
{
    public function getId(): ?int;

    public function getUser(): User;

    public function getRawText(): string;

    public function getCreatedAt(): \DateTimeImmutable;

    public function getStatus(): EstimationStatus;

    public function isPending(): bool;

    public function isFailed(): bool;

    public function getEstimationAttempts(): int;

    public function getLastEstimationAttemptAt(): ?\DateTimeImmutable;

    public function getLastEstimationError(): ?string;

    /** Records a failed attempt. Becomes Failed when $giveUp, otherwise stays Pending for another try. */
    public function estimationFailed(string $error, \DateTimeImmutable $at, bool $giveUp): void;
}
