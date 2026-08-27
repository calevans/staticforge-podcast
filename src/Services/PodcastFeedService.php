<?php

declare(strict_types=1);

namespace Calevans\StaticForgePodcast\Services;

use Calevans\StaticForgePodcast\Config\PodcastConfig;
use Calevans\StaticForgePodcast\Feed\PodcastExtension;
use EICC\StaticForge\Core\Events\RssBuilderInitEvent;
use EICC\StaticForge\Core\Events\RssItemBuildingEvent;
use EICC\Utils\Container;
use EICC\Utils\Log;

/**
 * RSS_BUILDER_INIT gates the whole iTunes namespace on an explicit
 * `podcast: true` marker in the category definition's frontmatter - a plain
 * category feed with no podcast content should not advertise Podcasting
 * support it doesn't have.
 *
 * RSS_ITEM_BUILDING is intentionally NOT gated the same way: a plain
 * <enclosure> is valid RSS anywhere, so any item carrying resolved media gets
 * one regardless of whether its category opted into the iTunes namespace.
 * This method does no filesystem I/O - only enclosure/content shape from
 * already-resolved PRE_RENDER metadata.
 */
final class PodcastFeedService
{
    public function __construct(
        private readonly Log $logger,
        private readonly Container $container,
    ) {
    }

    public function handleRssBuilderInit(RssBuilderInitEvent $event): void
    {
        $categoryMetadata = $event->categoryMetadata;

        $siteConfig = $this->container->getVariable('site_config');
        $siteConfig = is_array($siteConfig) ? $siteConfig : [];

        if (($categoryMetadata['podcast'] ?? null) !== true) {
            $this->reportMissingGate($categoryMetadata, $siteConfig);
            return;
        }

        $config = PodcastConfig::resolve($categoryMetadata, $siteConfig);
        $siteBaseUrl = (string) ($this->container->getVariable('SITE_BASE_URL') ?? '');

        $event->builder->addExtension(new PodcastExtension($siteBaseUrl, $config));
    }

    /**
     * RSS_BUILDER_INIT fires once per category, so an unconditional warning
     * here would nag on every ordinary blog feed. Only speak up where the
     * user plainly meant to publish a podcast: the category already carries
     * itunes_* keys, or they configured a site-wide `podcast:` block but have
     * no category definition file at all to hang `podcast: true` on (the
     * category gets invented on the fly, so $categoryMetadata is empty).
     *
     * @param array<string, mixed> $categoryMetadata
     * @param array<string, mixed> $siteConfig
     */
    private function reportMissingGate(array $categoryMetadata, array $siteConfig): void
    {
        $hasItunesKeys = array_any(
            array_keys($categoryMetadata),
            static fn (int|string $key): bool => is_string($key) && str_starts_with($key, 'itunes_')
        );
        $configuredSiteWide = $categoryMetadata === [] && isset($siteConfig['podcast']);

        if (!$hasItunesKeys && !$configuredSiteWide) {
            return;
        }

        $sourceDir = (string) ($this->container->getVariable('SOURCE_DIR') ?? '');
        $this->logger->log(
            'WARNING',
            "Podcast: iTunes tags omitted for this feed - no 'podcast: true' in the category " .
            "frontmatter. Add it to the category definition file (the one with 'type: category') " .
            "under {$sourceDir}; create that file if it does not exist yet."
        );
    }

    public function handleRssItemBuilding(RssItemBuildingEvent $event): void
    {
        $metadata = $event->file['metadata'] ?? null;
        if (!is_array($metadata)) {
            return;
        }

        $url = $metadata['audio_url'] ?? $metadata['video_url'] ?? null;
        if (!is_string($url) || $url === '') {
            return;
        }

        $length = is_int($metadata['media_length'] ?? null) ? $metadata['media_length'] : 0;
        $type = is_string($metadata['media_type'] ?? null) ? $metadata['media_type'] : 'application/octet-stream';

        if (preg_match('~^https?://~i', $url) !== 1) {
            $siteBaseUrl = (string) ($this->container->getVariable('SITE_BASE_URL') ?? '');
            $url = rtrim($siteBaseUrl, '/') . '/' . ltrim($url, '/');
        }

        $event->item->enclosure = ['url' => $url, 'length' => $length, 'type' => $type];

        // Absent on every incremental-cache-hit build and every .html episode
        // (MARKDOWN_CONVERTED only fires for a fresh Markdown RENDER) - never
        // fall back to the full rendered page here, or content:encoded ships
        // head/nav/footer to every subscriber. $item->description already
        // carries the episode in that case.
        $showNotes = $metadata['podcast_show_notes_html'] ?? null;
        $event->item->content = (is_string($showNotes) && $showNotes !== '') ? $showNotes : null;

        $this->logger->log('INFO', "Podcast: added enclosure for {$event->item->title}");
    }
}
