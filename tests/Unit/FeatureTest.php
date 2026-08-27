<?php

declare(strict_types=1);

namespace Calevans\StaticForgePodcast\Tests\Unit;

use Calevans\StaticForgePodcast\Feature;
use Calevans\StaticForgePodcast\Tests\TestCase;
use EICC\StaticForge\Core\Events\ConsoleInitEvent;
use EICC\StaticForge\Core\Events\Event;
use EICC\StaticForge\Core\Events\RenderEvent;
use EICC\StaticForge\Core\Events\RssBuilderInitEvent;
use EICC\StaticForge\Core\Events\RssItemBuildingEvent;
use EICC\StaticForge\Core\EventManager;
use EICC\StaticForge\Core\FeatureFactory;
use EICC\StaticForge\Features\RssFeed\Models\FeedChannel;
use EICC\StaticForge\Features\RssFeed\Models\FeedItem;
use EICC\StaticForge\Features\RssFeed\Services\RssBuilder;
use Symfony\Component\Console\Application;

class FeatureTest extends TestCase
{
    private Feature $feature;
    private EventManager $eventManager;
    private string $appRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->appRoot = sys_get_temp_dir() . '/staticforge_podcast_test_' . uniqid();
        mkdir($this->appRoot, 0755, true);

        $this->setContainerVariable('app_root', $this->appRoot);
        $this->setContainerVariable('site_config', ['site' => ['name' => 'Test Podcast']]);

        $this->eventManager = new EventManager();

        $feature = (new FeatureFactory($this->container))->make(Feature::class);
        $this->assertInstanceOf(Feature::class, $feature);
        $this->feature = $feature;
        $this->feature->register($this->eventManager);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeDirectory($this->appRoot);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = "$dir/$file";
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testRegisterRegistersAListenerForEveryLifecycleEventItUses(): void
    {
        foreach (
            [
                'CONSOLE_INIT',
                'CREATE',
                'PRE_LOOP',
                'PRE_RENDER',
                'MARKDOWN_CONVERTED',
                'RSS_BUILDER_INIT',
                'RSS_ITEM_BUILDING',
                'POST_LOOP',
                'DESTROY',
            ] as $eventName
        ) {
            $this->assertNotEmpty(
                $this->eventManager->getListeners($eventName),
                "No listener registered for {$eventName}",
            );
        }
    }

    public function testRegisterCommandsAddsOnlyMediaInspectCommand(): void
    {
        // podcast:setup was deleted in 3.1.0 - core's `feature:setup` now
        // installs the example templates/config instead.
        $app = new Application();
        $event = new ConsoleInitEvent('CONSOLE_INIT', $app);

        $this->feature->registerCommands($event);

        $this->assertTrue($app->has('media:inspect'));
        $this->assertFalse($app->has('podcast:setup'));
    }

    public function testHandleRssItemBuildingSkipsNonPodcastItems(): void
    {
        $this->setContainerVariable('SITE_BASE_URL', 'https://example.com');

        $item = new FeedItem('Title', 'https://example.com/page', 'guid-1', 'Mon, 01 Jan 2024 00:00:00 +0000');
        $event = new RssItemBuildingEvent('RSS_ITEM_BUILDING', $item, ['metadata' => []]);

        $this->feature->handleRssItemBuilding($event);

        // No audio_file/video_file in metadata, so no enclosure should be added
        $this->assertNull($item->enclosure);
    }

    public function testHandleRssBuilderInitAddsExtensionWhenCategoryOptsInWithPodcastTrue(): void
    {
        $this->setContainerVariable('SITE_BASE_URL', 'https://example.com');

        $builder = new RssBuilder();
        $event = new RssBuilderInitEvent('RSS_BUILDER_INIT', $builder, ['podcast' => true]);

        $this->feature->handleRssBuilderInit($event);

        // Confirm the podcast extension was actually registered on the builder
        // by checking its itunes namespace appears in the built feed XML.
        $channel = new FeedChannel('Test Feed', 'https://example.com/', 'A test feed', 'https://example.com/rss.xml');
        $xml = $builder->build($channel, []);

        $this->assertStringContainsString('xmlns:itunes', $xml);
    }

    public function testHandleRssBuilderInitSkipsExtensionWhenCategoryMetadataHasNoPodcastGate(): void
    {
        $this->setContainerVariable('SITE_BASE_URL', 'https://example.com');

        $builder = new RssBuilder();
        // Empty categoryMetadata: a plain blog category with no `podcast: true`
        // marker must not advertise iTunes support it doesn't have.
        $event = new RssBuilderInitEvent('RSS_BUILDER_INIT', $builder, []);

        $this->feature->handleRssBuilderInit($event);

        $channel = new FeedChannel('Test Feed', 'https://example.com/', 'A test feed', 'https://example.com/rss.xml');
        $xml = $builder->build($channel, []);

        $this->assertStringNotContainsString('xmlns:itunes', $xml);
    }

    public function testHandlePreRenderSkipsNonPodcastPages(): void
    {
        $event = new RenderEvent(
            name: 'PRE_RENDER',
            filePath: 'content/page.md',
            fileUrl: '',
            metadata: ['title' => 'Regular Page'],
        );

        $this->feature->handlePreRender($event);

        $this->assertArrayNotHasKey('audio_url', $event->metadata);
        $this->assertArrayNotHasKey('video_url', $event->metadata);
    }

    public function testHandleCreateWarnsAboutLegacyRootLevelPodcastPlatformsKey(): void
    {
        $this->setContainerVariable('site_config', ['podcast_platforms' => ['apple' => 'https://x']]);

        $this->logger->expects($this->once())
            ->method('log')
            ->with('WARNING', $this->stringContains("'podcast:' root key"));

        $this->feature->handleCreate(new Event('CREATE'));
    }

    public function testHandleCreateWarnsAboutLegacyRootLevelItunesKeys(): void
    {
        $this->setContainerVariable('site_config', ['itunes_author' => 'Someone']);

        $this->logger->expects($this->once())->method('log')->with('WARNING', $this->anything());

        $this->feature->handleCreate(new Event('CREATE'));
    }

    public function testHandleCreateDoesNotWarnWhenPodcastKeyAlreadyPresent(): void
    {
        $this->setContainerVariable('site_config', [
            'podcast' => ['itunes_author' => 'Someone'],
            'podcast_platforms' => ['apple' => 'https://x'],
        ]);

        $this->logger->expects($this->never())->method('log');

        $this->feature->handleCreate(new Event('CREATE'));
    }

    public function testHandleCreateDoesNotWarnWhenNoLegacyKeysPresent(): void
    {
        $this->setContainerVariable('site_config', ['site' => ['name' => 'Test Podcast']]);

        $this->logger->expects($this->never())->method('log');

        $this->feature->handleCreate(new Event('CREATE'));
    }
}
