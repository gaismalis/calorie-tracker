<?php

namespace App\Voice;

/** What a voice message said was eaten: the foods one by one ("2 eggs", "a slice of rye bread"), and the whole sentence. */
final readonly class HeardFoods
{
    /** @param list<string> $foods */
    public function __construct(
        public array $foods,
        public string $transcript,
    ) {
    }

    /** As the meal text the normal estimate gets: "2 eggs, a slice of rye bread, a coffee with milk". */
    public function asText(): string
    {
        return implode(', ', $this->foods);
    }
}
