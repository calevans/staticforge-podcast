<?php

declare(strict_types=1);

namespace Calevans\StaticForgePodcast\Listeners;

use Calevans\StaticForgePodcast\Services\PodcastMediaService;
use EICC\StaticForge\Core\Events\RenderEvent;
use EICC\Utils\Log;

class PageRenderListener
{
    public function __construct(
        private PodcastMediaService $mediaService,
        private Log $logger,
        private string $outputDir,
        private string $sourceDir
    ) {
    }

    public function handle(RenderEvent $event): void
    {
        $metadata = $event->metadata;

        // mimicking the file structure expected by PodcastMediaService
        // It expects ['metadata' => $metadata]
        $fileData = ['metadata' => $metadata];

        // Check if this is a podcast item
        if (empty($metadata['audio_file']) && empty($metadata['video_file'])) {
            return;
        }

        try {
            $mediaData = $this->mediaService->processMedia(
                $fileData,
                $this->sourceDir,
                $this->outputDir
            );

            if ($mediaData) {
                // Determine variable name based on type
                $key = (strpos($mediaData['type'], 'video') !== false) ? 'video_url' : 'audio_url';

                // Inject into metadata so it's available in template
                $event->metadata[$key] = $mediaData['url'];
                $event->metadata['media_type'] = $mediaData['type'];
                $event->metadata['media_length'] = $mediaData['length'];
            }
        } catch (\Exception $e) {
            $this->logger->log('ERROR', "Podcast: Failed to process media for page: " . $e->getMessage());
        }
    }
}
