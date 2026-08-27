<?php

declare(strict_types=1);

namespace Calevans\StaticForgePodcast;

use Calevans\StaticForgePodcast\Commands\InspectMediaCommand;
use Calevans\StaticForgePodcast\Services\EpisodeMediaService;
use Calevans\StaticForgePodcast\Services\Id3TagWriter;
use Calevans\StaticForgePodcast\Services\MediaInspector;
use Calevans\StaticForgePodcast\Services\MediaStateCache;
use Calevans\StaticForgePodcast\Services\PodcastFeedService;
use EICC\StaticForge\Core\BaseFeature;
use EICC\StaticForge\Core\ConfigurableFeatureInterface;
use EICC\StaticForge\Core\Events\ConsoleInitEvent;
use EICC\StaticForge\Core\Events\Event;
use EICC\StaticForge\Core\Events\EventListener;
use EICC\StaticForge\Core\Events\RenderEvent;
use EICC\StaticForge\Core\Events\RssBuilderInitEvent;
use EICC\StaticForge\Core\Events\RssItemBuildingEvent;
use EICC\StaticForge\Core\EventManager;
use EICC\StaticForge\Core\FeatureInterface;
use EICC\Utils\Container;
use EICC\Utils\Log;
use Throwable;

/**
 * Registration does nothing but wire #[EventListener] attributes -
 * FeatureManager::loadFeatures() runs on EVERY CLI invocation, so anything
 * heavier here (constructing getID3, reading the media state cache) would
 * run even for `staticforge list`. All real services are lazy private
 * accessors that only read SOURCE_DIR/OUTPUT_DIR/site_config, and only
 * construct getID3, on first use during an actual build.
 */
final class Feature extends BaseFeature implements FeatureInterface, ConfigurableFeatureInterface
{
    protected string $name = 'Podcast';

    private ?EpisodeMediaService $mediaService = null;
    private ?PodcastFeedService $feedService = null;
    private ?MediaStateCache $stateCache = null;

    public function __construct(
        Container $container,
        private readonly Log $logger,
    ) {
        // $container is inherited (non-readonly) from BaseFeature - it cannot
        // be re-declared readonly via constructor promotion.
        $this->container = $container;
    }

    public function register(EventManager $eventManager): void
    {
        parent::register($eventManager);
    }

    #[EventListener('CONSOLE_INIT', priority: 100)]
    public function registerCommands(ConsoleInitEvent $event): void
    {
        $event->application->addCommand(new InspectMediaCommand($this->container));
    }

    /**
     * Only thing that will tell a 3.0 user why their badges/iTunes tags
     * vanished after upgrading: root-level `podcast_platforms`/`itunes_*`
     * keys moved under a single `podcast:` root key in 3.1.0 and are silently
     * ignored otherwise.
     */
    #[EventListener('CREATE', priority: 100)]
    public function handleCreate(Event $event): void
    {
        $siteConfig = $this->container->getVariable('site_config');
        $siteConfig = is_array($siteConfig) ? $siteConfig : [];

        if (isset($siteConfig['podcast'])) {
            return;
        }

        $hasLegacyPlatforms = isset($siteConfig['podcast_platforms']);
        $hasLegacyItunesKeys = array_any(
            array_keys($siteConfig),
            static fn (int|string $key): bool => is_string($key) && str_starts_with($key, 'itunes_')
        );

        if ($hasLegacyPlatforms || $hasLegacyItunesKeys) {
            $this->logger->log(
                'WARNING',
                "Podcast: root-level 'podcast_platforms'/'itunes_*' keys in siteconfig.yaml are no " .
                "longer read as of 3.1.0. Move them under a single 'podcast:' root key " .
                "('platforms:' replaces 'podcast_platforms:')."
            );
        }
    }

    /**
     * Deliberately does NOT go through mediaService(): that accessor
     * constructs getID3, whose constructor throws on a startup error (an
     * open_basedir-restricted temp dir, an unwelcome memory_limit). Building
     * it here would let a site with no episodes at all die at PRE_LOOP. If
     * the service was never built, there is nothing to reset anyway.
     */
    #[EventListener('PRE_LOOP', priority: 100)]
    public function handlePreLoop(Event $event): void
    {
        $this->mediaService?->resetForNewBuild();
    }

    #[EventListener('PRE_RENDER', priority: 50)]
    public function handlePreRender(RenderEvent $event): void
    {
        try {
            $this->mediaService()->onPreRender($event);
        } catch (Throwable $e) {
            $this->logger->log('ERROR', "Podcast: failed to process media for {$event->filePath}: {$e->getMessage()}");
        }
    }

    /**
     * Priority 900, not the more obvious 500: TableOfContents also listens at
     * 500, and usort on equal priorities is registration-order dependent.
     * 900 guarantees we run after every other MARKDOWN_CONVERTED listener and
     * capture the final converted HTML.
     */
    #[EventListener('MARKDOWN_CONVERTED', priority: 900)]
    public function handleMarkdownConverted(RenderEvent $event): void
    {
        try {
            $this->mediaService()->onMarkdownConverted($event);
        } catch (Throwable $e) {
            $this->logger->log(
                'ERROR',
                "Podcast: failed to attach show notes for {$event->filePath}: {$e->getMessage()}"
            );
        }
    }

    #[EventListener('RSS_BUILDER_INIT', priority: 100)]
    public function handleRssBuilderInit(RssBuilderInitEvent $event): void
    {
        try {
            $this->feedService()->handleRssBuilderInit($event);
        } catch (Throwable $e) {
            $this->logger->log('ERROR', "Podcast: failed to initialize feed extension: {$e->getMessage()}");
        }
    }

    #[EventListener('RSS_ITEM_BUILDING', priority: 100)]
    public function handleRssItemBuilding(RssItemBuildingEvent $event): void
    {
        try {
            $this->feedService()->handleRssItemBuilding($event);
        } catch (Throwable $e) {
            $this->logger->log(
                'ERROR',
                "Podcast: failed to build enclosure for {$event->item->title}: {$e->getMessage()}"
            );
        }
    }

    /**
     * Priority 110: after TemplateAssets (100), which unconditionally
     * overwrites OUTPUT_DIR/assets/** from the untagged SOURCE_DIR/assets/**
     * master on every build. Publishing any earlier would let that clobber
     * the tagged artifact after RssFeed (POST_LOOP 90) already wrote a feed
     * advertising its byte length.
     */
    #[EventListener('POST_LOOP', priority: 110)]
    public function handlePostLoop(Event $event): void
    {
        try {
            $this->mediaService()->publishPending();
        } catch (Throwable $e) {
            $this->logger->log('ERROR', "Podcast: failed to publish episode media: {$e->getMessage()}");
        }
    }

    #[EventListener('DESTROY', priority: 100)]
    public function handleDestroy(Event $event): void
    {
        try {
            $this->stateCache()->flush();
        } catch (Throwable $e) {
            $this->logger->log('ERROR', "Podcast: failed to flush media state cache: {$e->getMessage()}");
        }
    }

    public function getRequiredConfig(): array
    {
        return ['site.name'];
    }

    public function getRequiredEnv(): array
    {
        return ['SITE_BASE_URL'];
    }

    private function mediaService(): EpisodeMediaService
    {
        return $this->mediaService ??= new EpisodeMediaService(
            $this->logger,
            $this->container,
            $this->stateCache(),
            new MediaInspector(),
            new Id3TagWriter($this->logger),
        );
    }

    private function feedService(): PodcastFeedService
    {
        return $this->feedService ??= new PodcastFeedService($this->logger, $this->container);
    }

    private function stateCache(): MediaStateCache
    {
        return $this->stateCache ??= new MediaStateCache($this->cachePath(), $this->logger);
    }

    private function cachePath(): string
    {
        return rtrim((string) $this->container->getVariable('app_root'), '/\\') . '/cache/podcast/state.json';
    }
}
