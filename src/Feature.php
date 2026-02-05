<?php

declare(strict_types=1);

namespace Calevans\StaticForgePodcast;

use EICC\StaticForge\Core\BaseFeature;
use EICC\StaticForge\Core\FeatureInterface;
use EICC\StaticForge\Core\ConfigurableFeatureInterface;
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
use Symfony\Component\Console\Application;

class Feature extends BaseFeature implements FeatureInterface, ConfigurableFeatureInterface
{
    protected string $name = 'Podcast';
    protected Log $logger;
    private RssItemListener $rssListener;
    private PageRenderListener $pageListener;

    protected array $eventListeners = [
        'CONSOLE_INIT' => ['method' => 'registerCommands', 'priority' => 100],
        'RSS_ITEM_BUILDING' => ['method' => 'handleRssItemBuilding', 'priority' => 100],
        'RSS_BUILDER_INIT' => ['method' => 'handleRssBuilderInit', 'priority' => 100],
        'PRE_RENDER' => ['method' => 'handlePreRender', 'priority' => 50]
    ];

    public function register(EventManager $eventManager, Container $container): void
    {
        parent::register($eventManager, $container);
        $this->logger = $container->get('logger');

        $appRoot = $container->getVariable('app_root');
        $siteConfig = $container->getVariable('site_config');
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

    public function registerCommands(Container $container, array $parameters): array
    {
        /** @var Application $app */
        $app = $parameters['application'];
        $app->add(new InspectMediaCommand());
        $app->add(new SetupCommand());
        return $parameters;
    }

    public function handleRssItemBuilding(Container $container, array $parameters): array
    {
        $siteBaseUrl = $container->getVariable('SITE_BASE_URL');
        if ($siteBaseUrl === null) {
            throw new \RuntimeException('SITE_BASE_URL not set in container');
        }

        $this->rssListener->handle($parameters, $siteBaseUrl);
        return $parameters;
    }

    public function handleRssBuilderInit(Container $container, array $parameters): array
    {
        $builder = $parameters['builder'];

        $siteBaseUrl = $container->getVariable('SITE_BASE_URL');
        if ($siteBaseUrl === null) {
            throw new \RuntimeException('SITE_BASE_URL not set in container');
        }

        $extension = new PodcastExtension($siteBaseUrl);
        $builder->addExtension($extension);
        return $parameters;
    }

    public function handlePreRender(Container $container, array $parameters): array
    {
        return $this->pageListener->handle($parameters);
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
