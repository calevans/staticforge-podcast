<?php

declare(strict_types=1);

namespace Calevans\StaticForgePodcast;

use EICC\StaticForge\Core\BaseFeature;
use EICC\StaticForge\Core\FeatureInterface;
use EICC\StaticForge\Core\ConfigurableFeatureInterface;
use EICC\StaticForge\Core\Events\ConsoleInitEvent;
use EICC\StaticForge\Core\Events\EventListener;
use EICC\StaticForge\Core\Events\RenderEvent;
use EICC\StaticForge\Core\Events\RssBuilderInitEvent;
use EICC\StaticForge\Core\Events\RssItemBuildingEvent;
use EICC\StaticForge\Core\EventManager;
use Calevans\StaticForgePodcast\Commands\InspectMediaCommand;
use Calevans\StaticForgePodcast\Commands\SetupCommand;
use Calevans\StaticForgePodcast\Listeners\PageRenderListener;
use Calevans\StaticForgePodcast\Listeners\RssItemListener;
use Calevans\StaticForgePodcast\Services\MediaInspect\MediaInspector;
use Calevans\StaticForgePodcast\Services\PodcastMediaService;
use Calevans\StaticForgePodcast\Services\PodcastExtension;
use EICC\Utils\Container;
use EICC\Utils\Log;

class Feature extends BaseFeature implements FeatureInterface, ConfigurableFeatureInterface
{
    protected string $name = 'Podcast';
    protected Log $logger;
    private RssItemListener $rssListener;
    private PageRenderListener $pageListener;

    public function __construct(Container $container, Log $logger)
    {
        $this->container = $container;
        $this->logger = $logger;
    }

    public function register(EventManager $eventManager): void
    {
        parent::register($eventManager);

        $appRoot = $this->container->getVariable('app_root');
        $siteConfig = $this->container->getVariable('site_config');
        $outputDir = $appRoot . '/public';
        $sourceDir = $appRoot . '/content';

        $siteName = $siteConfig['site']['name'] ?? 'Podcast';
        $cachePath = $appRoot . '/cache/podcast_media_state.json';

        // Initialize services
        $mediaInspector = new MediaInspector();
        $mediaService = new PodcastMediaService($mediaInspector, $siteName, $cachePath);

        $this->rssListener = new RssItemListener(
            $mediaService,
            $this->logger,
            $outputDir,
            $sourceDir
        );

        $this->pageListener = new PageRenderListener(
            $mediaService,
            $this->logger,
            $outputDir,
            $sourceDir
        );
    }

    #[EventListener('CONSOLE_INIT', priority: 100)]
    public function registerCommands(ConsoleInitEvent $event): void
    {
        $event->application->addCommand(new InspectMediaCommand());
        $event->application->addCommand(new SetupCommand());
    }

    #[EventListener('RSS_ITEM_BUILDING', priority: 100)]
    public function handleRssItemBuilding(RssItemBuildingEvent $event): void
    {
        $siteBaseUrl = $this->container->getVariable('SITE_BASE_URL');
        if ($siteBaseUrl === null) {
            throw new \RuntimeException('SITE_BASE_URL not set in container');
        }

        $this->rssListener->handle($event, $siteBaseUrl);
    }

    #[EventListener('RSS_BUILDER_INIT', priority: 100)]
    public function handleRssBuilderInit(RssBuilderInitEvent $event): void
    {
        $siteBaseUrl = $this->container->getVariable('SITE_BASE_URL');
        if ($siteBaseUrl === null) {
            throw new \RuntimeException('SITE_BASE_URL not set in container');
        }

        $extension = new PodcastExtension($siteBaseUrl);
        $event->builder->addExtension($extension);
    }

    #[EventListener('PRE_RENDER', priority: 50)]
    public function handlePreRender(RenderEvent $event): void
    {
        $this->pageListener->handle($event);
    }

    public function getRequiredConfig(): array
    {
        return [];
    }

    public function getRequiredEnv(): array
    {
        return [];
    }
}
