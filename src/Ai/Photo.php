<?php

namespace App\Ai;

/** A photo sent to the AI along with text (e.g. of a meal or a nutrition label). */
final readonly class Photo
{
    /** Formats Gemini accepts; phones send JPEG (or HEIC from iPhones when not converted). */
    public const MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];
    /** The browser shrinks photos before upload; this is the limit for ones that arrive unshrunk. */
    public const MAX_BYTES = 10 * 1024 * 1024;

    public function __construct(
        public string $bytes,
        public string $mimeType,
    ) {
        if (!in_array($mimeType, self::MIME_TYPES, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported photo type "%s".', $mimeType));
        }
    }
}
