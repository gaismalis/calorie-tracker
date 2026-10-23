<?php

namespace App\Review;

/**
 * An AI's view of the quality of a week's food (not the calories): a rating, a short summary, what's going
 * well, what to try, and nutrients that are likely too low or too high.
 */
final readonly class FoodReview
{
    public const RATINGS = ['great', 'good', 'mixed', 'poor'];
    public const MAX_STRENGTHS = 3;
    public const MAX_SUGGESTIONS = 3;
    public const MAX_NUTRIENTS = 4;
    private const MAX_TEXT = 300;

    /**
     * @param list<string>                                                $strengths
     * @param list<string>                                                $suggestions most helpful first
     * @param list<array{name: string, status: 'low'|'high', note: string}> $nutrients
     */
    public function __construct(
        public string $rating,
        public string $summary,
        public array $strengths,
        public array $suggestions,
        public array $nutrients,
    ) {
    }

    /**
     * From an AI answer or a stored review: unknown ratings become "mixed", lists are capped, texts trimmed.
     *
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $texts = fn (mixed $list, int $max) => array_slice(array_values(array_filter(array_map(
            fn (mixed $text) => is_string($text) ? self::text($text) : '',
            is_array($list) ? $list : [],
        ))), 0, $max);

        $nutrients = [];
        foreach (is_array($data['nutrients'] ?? null) ? $data['nutrients'] : [] as $nutrient) {
            $name = self::text((string) ($nutrient['name'] ?? ''));
            $status = $nutrient['status'] ?? null;
            if ('' !== $name && in_array($status, ['low', 'high'], true)) {
                $nutrients[] = ['name' => $name, 'status' => $status, 'note' => self::text((string) ($nutrient['note'] ?? ''))];
            }
        }

        return new self(
            in_array($data['rating'] ?? null, self::RATINGS, true) ? $data['rating'] : 'mixed',
            self::text((string) ($data['summary'] ?? '')),
            $texts($data['strengths'] ?? [], self::MAX_STRENGTHS),
            $texts($data['suggestions'] ?? [], self::MAX_SUGGESTIONS),
            array_slice($nutrients, 0, self::MAX_NUTRIENTS),
        );
    }

    /** @return array{rating: string, summary: string, strengths: list<string>, suggestions: list<string>, nutrients: list<array{name: string, status: string, note: string}>} */
    public function toArray(): array
    {
        return ['rating' => $this->rating, 'summary' => $this->summary, 'strengths' => $this->strengths, 'suggestions' => $this->suggestions, 'nutrients' => $this->nutrients];
    }

    private static function text(string $text): string
    {
        return mb_strimwidth(trim(preg_replace('/\s+/u', ' ', $text) ?? ''), 0, self::MAX_TEXT, '…');
    }
}
