<?php

namespace App\Ai;

final readonly class GeminiResult
{
    /** @param array<mixed> $data decoded JSON answer */
    public function __construct(
        public array $data,
        /** Model that answered, as reported by the API (e.g. "gemini-3.5-flash-lite"). */
        public string $model,
    ) {
    }
}
