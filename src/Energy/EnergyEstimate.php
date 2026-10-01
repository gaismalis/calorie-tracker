<?php

namespace App\Energy;

final readonly class EnergyEstimate
{
    public const SOURCE_ADAPTIVE = 'adaptive';
    public const SOURCE_FORMULA = 'formula';

    /** @param list<string> $missing profile data the formula still needs, e.g. ['height', 'weight'] */
    public function __construct(
        public ?float $bmr,
        /** Formula estimate (BMR × activity). */
        public ?float $formulaTdee,
        public array $missing = [],
        public ?AdaptiveTdee $adaptive = null,
    ) {
    }

    public function withAdaptive(AdaptiveTdee $adaptive): self
    {
        return new self($this->bmr, $this->formulaTdee, $this->missing, $adaptive);
    }

    /** Best available estimate of daily expenditure: the user's own data when there's enough, else the formula. */
    public function getTdee(): ?float
    {
        return $this->adaptive?->tdee ?? $this->formulaTdee;
    }

    public function getSource(): ?string
    {
        return match (true) {
            null !== $this->adaptive?->tdee => self::SOURCE_ADAPTIVE,
            null !== $this->formulaTdee => self::SOURCE_FORMULA,
            default => null,
        };
    }

    public function isComplete(): bool
    {
        return null !== $this->getTdee();
    }
}
