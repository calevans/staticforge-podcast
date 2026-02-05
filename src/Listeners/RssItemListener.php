<?php

declare(strict_types=1);

namespace Calevans\StaticForgePodcast\Listeners;

use Calevans\StaticForgePodcast\Services\PodcastMediaService;
use EICC\StaticForge\Features\RssFeed\Models\FeedItem;
use EICC\Utils\Log;

class RssItemListener
{
    public function __construct(
        private PodcastMediaService $mediaService,
        private Log $logger,
        private string $outputDir,
        private string $sourceDir
    ) {
    }

    public function handle(array $eventData, string $siteBaseUrl): void
    {
        /** @var FeedItem $item */
        $item = $eventData['item'];
        /** @var array $file */
        $file = $eventData['file'];

        $metadata = $file['metadata'] ?? [];

        // Check if this is a podcast item
        if (empty($metadata['audio_file']) && empty($metadata['video_file'])) {
            return;
        }

        try {
            // Clean Content: Extract only the content body from the full HTML
            if ($item->content) {
                if (preg_match('/<div class="content-body">(.*?)<\/div>/s', $item->content, $matches)) {
                    $item->content = trim($matches[1]);
                } else {
                    // Fallback: If no content-body div, use description
                    $item->content = $item->description;
                }
            }

            $mediaData = $this->mediaService->processMedia(
                $file,
                $this->sourceDir,
                $this->outputDir
            );

            if ($mediaData) {
                // Ensure URL is absolute if needed
                if (!preg_match('~^https?://~i', $mediaData['url'])) {
                    $mediaData['url'] = rtrim($siteBaseUrl, '/') . '/' . ltrim($mediaData['url'], '/');
                }

                $item->enclosure = $mediaData;
                $this->logger->log('INFO', "Added podcast enclosure for: {$item->title}");
            }
        } catch (\Exception $e) {
            $this->logger->log('ERROR', "Failed to process podcast media for {$item->title}: " . $e->getMessage());
        }
    }
}
