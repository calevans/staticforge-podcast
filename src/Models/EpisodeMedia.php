<?php

declare(strict_types=1);

namespace Calevans\StaticForgePodcast\Models;

/**
 * A resolved episode media reference, ready to be written into a RenderEvent's
 * metadata or turned into an RSS enclosure.
 */
final readonly class EpisodeMedia
{
    public function __construct(
        public string $url,
        public int $length,
        public string $type,
        public ?string $duration,
        public bool $isVideo,
    ) {
    }
}
