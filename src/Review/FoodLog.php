<?php

namespace App\Review;

/**
 * What was eaten on each logged day of a week, plus a little about the person, as the text an AI reviews.
 * Its hash changes whenever anything in it changes, so a stored review can be reused until then.
 */
final readonly class FoodLog
{
    /**
     * @param string                $from  first day ('Y-m-d')
     * @param string                $to    last day ('Y-m-d', inclusive)
     * @param array<string, list<array{time: string, name: string, grams: float, kcal: float, protein: float, carbs: float, fat: float, category: string}>> $days
     *                                     items by day, days without meals absent
     * @param float                 $goalKgPerWeek negative = losing weight
     * @param string|null           $sex   'male'/'female' when the user has said so
     */
    public function __construct(
        public string $from,
        public string $to,
        public array $days,
        public float $goalKgPerWeek,
        public ?string $sex = null,
        public ?int $age = null,
    ) {
    }

    public function loggedDays(): int
    {
        return count($this->days);
    }

    /** Days covered, from $from to $to inclusive. */
    public function dayCount(): int
    {
        return (int) (new \DateTimeImmutable($this->from))->diff(new \DateTimeImmutable($this->to))->days + 1;
    }

    public function toText(): string
    {
        $about = array_filter([
            null === $this->sex ? null : $this->sex,
            null === $this->age ? null : $this->age.' years old',
            match (true) {
                $this->goalKgPerWeek < 0 => sprintf('wants to lose %s kg/week', abs($this->goalKgPerWeek)),
                $this->goalKgPerWeek > 0 => sprintf('wants to gain %s kg/week', $this->goalKgPerWeek),
                default => 'wants to keep their weight',
            },
        ]);
        $lines = [
            'Person: '.implode(', ', $about).'.',
            sprintf('Food log from %s to %s (%d of %d days logged; days not listed were not logged).', $this->from, $this->to, $this->loggedDays(), $this->dayCount()),
        ];
        $number = fn (float $value) => rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
        foreach ($this->days as $day => $items) {
            $lines[] = '';
            $lines[] = (new \DateTimeImmutable($day))->format('l Y-m-d').':';
            foreach ($items as $item) {
                $lines[] = sprintf('- %s %s: %s g, %s kcal, protein %s g, carbs %s g, fat %s g%s',
                    $item['time'], $item['name'], $number($item['grams']), $number($item['kcal']),
                    $number($item['protein']), $number($item['carbs']), $number($item['fat']),
                    'food' === $item['category'] ? '' : ' ('.$item['category'].')');
            }
        }

        return implode("\n", $lines);
    }

    public function hash(): string
    {
        return hash('sha256', $this->toText());
    }
}
