<?php

namespace App\Ai;

/**
 * A short voice message sent to the AI (e.g. "two eggs and a coffee"). The browser records it as WAV
 * (voice_controller.js), which every browser can make and Gemini accepts; the other types are ones Gemini also takes.
 */
final readonly class Recording
{
    public const MIME_TYPES = ['audio/wav', 'audio/x-wav', 'audio/wave', 'audio/webm', 'audio/ogg', 'audio/mpeg', 'audio/mp3', 'audio/aac', 'audio/flac'];
    public const MAX_SECONDS = 60;
    /** A minute of 16 kHz mono 16-bit WAV is about 1.9 MB. */
    public const MAX_BYTES = 4 * 1024 * 1024;

    public function __construct(
        public string $bytes,
        public string $mimeType,
    ) {
        if (!in_array($mimeType, self::MIME_TYPES, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported recording type "%s".', $mimeType));
        }
    }
}
