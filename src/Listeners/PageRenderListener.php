<?php

declare(strict_types=1);

namespace Calevans\StaticForgePodcast\Listeners;

use Calevans\StaticForgePodcast\Services\PodcastMediaService;
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

    public function handle(array $parameters): array
    {
        $metadata = $parameters['metadata'] ?? [];

        // mimicking the file structure expected by PodcastMediaService
        // It expects ['metadata' => $metadata]
        $fileData = ['metadata' => $metadata];

        // Check if this is a podcast item
        if (empty($metadata['audio_file']) && empty($metadata['video_file'])) {
            return $parameters;
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
                $parameters['metadata'][$key] = $mediaData['url'];
                $parameters['metadata']['media_type'] = $mediaData['type'];
                $parameters['metadata']['media_length'] = $mediaData['length'];

                // Also inject into file_metadata which is used by MarkdownRenderer
                if (isset($parameters['file_metadata'])) {
                    $parameters['file_metadata'][$key] = $mediaData['url'];
                    $parameters['file_metadata']['media_type'] = $mediaData['type'];
                    $parameters['file_metadata']['media_length'] = $mediaData['length'];
                }
            }
        } catch (\Exception $e) {
            $this->logger->log('ERROR', "Podcast: Failed to process media for page: " . $e->getMessage());
        }

        return $parameters;
    }
}
