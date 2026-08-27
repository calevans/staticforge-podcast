<?php

declare(strict_types=1);

namespace Calevans\StaticForgePodcast\Models;

/**
 * Result of inspecting a single media file (master or staged/tagged copy).
 */
final readonly class MediaInfo
{
    public function __construct(
        public int $size,
        public string $mimeType,
        public float $durationSeconds,
        public string $formattedDuration,
    ) {
    }
}
