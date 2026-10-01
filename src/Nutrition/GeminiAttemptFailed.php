<?php

namespace App\Nutrition;

/**
 * @internal One failed call to one Gemini model, after which another attempt or model may still succeed.
 */
final class GeminiAttemptFailed extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $retrySameModel, ?\Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }
}
