<?php

declare(strict_types=1);

namespace Calevans\StaticForgePodcast;

use EICC\StaticForge\Core\BaseFeature;
use EICC\StaticForge\Core\FeatureInterface;
use EICC\StaticForge\Core\ConfigurableFeatureInterface;
use EICC\StaticForge\Core\EventManager;
use Calevans\StaticForgePodcast\Commands\InspectMediaCommand;
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
        $siteBaseUrl = $siteConfig['url'] ?? 'http://localhost';

        // Initialize services
        $mediaInspector = new MediaInspector();
        $mediaService = new PodcastMediaService($mediaInspector);

        $this->rssListener = new RssItemListener(
            $mediaService,
            $this->logger,
            $outputDir,
            $sourceDir,
            $siteBaseUrl
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
        return $parameters;
    }

    public function handleRssItemBuilding(Container $container, array $parameters): array
    {
        $this->rssListener->handle($parameters);
        return $parameters;
    }

    public function handleRssBuilderInit(Container $container, array $parameters): array
    {
        $builder = $parameters['builder'];
        $siteBaseUrl = $container->getVariable('site_config')['url'] ?? 'http://localhost';
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
