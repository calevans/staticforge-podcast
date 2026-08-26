<?php

declare(strict_types=1);

namespace Calevans\StaticForgePodcast\Tests\Unit;

use Calevans\StaticForgePodcast\Feature;
use Calevans\StaticForgePodcast\Tests\TestCase;
use EICC\StaticForge\Core\Events\ConsoleInitEvent;
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

    public function testRegisterRegistersAllFourListeners(): void
    {
        $this->assertNotEmpty($this->eventManager->getListeners('CONSOLE_INIT'));
        $this->assertNotEmpty($this->eventManager->getListeners('RSS_ITEM_BUILDING'));
        $this->assertNotEmpty($this->eventManager->getListeners('RSS_BUILDER_INIT'));
        $this->assertNotEmpty($this->eventManager->getListeners('PRE_RENDER'));
    }

    public function testRegisterCommandsAddsBothCommands(): void
    {
        $app = new Application();
        $event = new ConsoleInitEvent('CONSOLE_INIT', $app);

        $this->feature->registerCommands($event);

        $this->assertTrue($app->has('media:inspect'));
        $this->assertTrue($app->has('podcast:setup'));
    }

    public function testHandleRssItemBuildingThrowsWhenBaseUrlMissing(): void
    {
        $item = new FeedItem('Title', 'https://example.com/page', 'guid-1', 'Mon, 01 Jan 2024 00:00:00 +0000');
        $event = new RssItemBuildingEvent('RSS_ITEM_BUILDING', $item, ['metadata' => []]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SITE_BASE_URL not set in container');

        $this->feature->handleRssItemBuilding($event);
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

    public function testHandleRssBuilderInitThrowsWhenBaseUrlMissing(): void
    {
        $builder = new RssBuilder();
        $event = new RssBuilderInitEvent('RSS_BUILDER_INIT', $builder, []);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SITE_BASE_URL not set in container');

        $this->feature->handleRssBuilderInit($event);
    }

    public function testHandleRssBuilderInitAddsExtension(): void
    {
        $this->setContainerVariable('SITE_BASE_URL', 'https://example.com');

        $builder = new RssBuilder();
        $event = new RssBuilderInitEvent('RSS_BUILDER_INIT', $builder, []);

        $this->feature->handleRssBuilderInit($event);

        // Confirm the podcast extension was actually registered on the builder
        // by checking its itunes namespace appears in the built feed XML.
        $channel = new FeedChannel('Test Feed', 'https://example.com/', 'A test feed', 'https://example.com/rss.xml');
        $xml = $builder->build($channel, []);

        $this->assertStringContainsString('xmlns:itunes', $xml);
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
}
