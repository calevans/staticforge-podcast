<?php

declare(strict_types=1);

namespace Calevans\StaticForgePodcast\Tests\Unit\Services;

use Calevans\StaticForgePodcast\Services\PodcastFeedService;
use Calevans\StaticForgePodcast\Tests\TestCase;
use EICC\StaticForge\Core\Events\RssBuilderInitEvent;
use EICC\StaticForge\Core\Events\RssItemBuildingEvent;
use EICC\StaticForge\Features\RssFeed\Models\FeedChannel;
use EICC\StaticForge\Features\RssFeed\Models\FeedItem;
use EICC\StaticForge\Features\RssFeed\Services\RssBuilder;

/**
 * PodcastFeedService is pure: RSS_BUILDER_INIT gates the whole iTunes
 * namespace on an explicit `podcast: true` marker (and warns, loudly and
 * only when there is evidence of intent, when that marker is missing);
 * RSS_ITEM_BUILDING is unconditional (a plain <enclosure> is valid RSS
 * anywhere) and does no filesystem I/O.
 */
final class PodcastFeedServiceTest extends TestCase
{
    private function makeService(): PodcastFeedService
    {
        return new PodcastFeedService($this->logger, $this->container);
    }

    /**
     * @return array{url: string, length: int, type: string}
     */
    private function enclosureOf(FeedItem $item): array
    {
        self::assertNotNull($item->enclosure);

        return $item->enclosure;
    }

    // --- RSS_BUILDER_INIT gate ------------------------------------------------------

    public function testExtensionIsAddedWhenPodcastTrueIsPresent(): void
    {
        $builder = new RssBuilder();
        $event = new RssBuilderInitEvent('RSS_BUILDER_INIT', $builder, ['podcast' => true]);

        $this->makeService()->handleRssBuilderInit($event);

        self::assertStringContainsString('xmlns:itunes', $this->renderEmptyChannel($builder));
    }

    public function testExtensionIsNotAddedWhenPodcastKeyIsAbsent(): void
    {
        $builder = new RssBuilder();
        $event = new RssBuilderInitEvent('RSS_BUILDER_INIT', $builder, []);

        $this->makeService()->handleRssBuilderInit($event);

        self::assertStringNotContainsString('xmlns:itunes', $this->renderEmptyChannel($builder));
    }

    public function testExtensionIsNotAddedWhenPodcastKeyIsFalse(): void
    {
        $builder = new RssBuilder();
        $event = new RssBuilderInitEvent('RSS_BUILDER_INIT', $builder, ['podcast' => false]);

        $this->makeService()->handleRssBuilderInit($event);

        self::assertStringNotContainsString('xmlns:itunes', $this->renderEmptyChannel($builder));
    }

    public function testExtensionIsNotAddedWhenPodcastKeyIsTruthyStringNotStrictlyTrue(): void
    {
        $builder = new RssBuilder();
        $event = new RssBuilderInitEvent('RSS_BUILDER_INIT', $builder, ['podcast' => 'true']);

        $this->makeService()->handleRssBuilderInit($event);

        self::assertStringNotContainsString('xmlns:itunes', $this->renderEmptyChannel($builder));
    }

    private function renderEmptyChannel(RssBuilder $builder): string
    {
        $channel = new FeedChannel(
            'Test Feed',
            'https://example.com/',
            'A test feed',
            'https://example.com/rss.xml',
        );

        return $builder->build($channel, []);
    }

    // --- warning only fires on evidence of podcast intent ----------------------------

    public function testNoWarningForAnOrdinaryBlogCategoryWithNoPodcastEvidence(): void
    {
        $this->logger->expects(self::never())->method('log');

        $builder = new RssBuilder();
        $event = new RssBuilderInitEvent(
            'RSS_BUILDER_INIT',
            $builder,
            ['title' => 'Blog', 'type' => 'category'],
        );

        $this->makeService()->handleRssBuilderInit($event);
    }

    public function testWarnsWhenCategoryMetadataHasItunesKeysButNoPodcastTrue(): void
    {
        $this->logger->expects(self::once())
            ->method('log')
            ->with('WARNING', self::stringContains("no 'podcast: true'"));

        $builder = new RssBuilder();
        $event = new RssBuilderInitEvent(
            'RSS_BUILDER_INIT',
            $builder,
            ['itunes_author' => 'Someone'],
        );

        $this->makeService()->handleRssBuilderInit($event);
    }

    public function testWarnsWhenCategoryMetadataEmptyAndSiteConfigHasPodcastKey(): void
    {
        $this->setContainerVariable('site_config', ['podcast' => ['itunes_author' => 'Site Author']]);
        $this->logger->expects(self::once())
            ->method('log')
            ->with('WARNING', self::stringContains("no 'podcast: true'"));

        $builder = new RssBuilder();
        $event = new RssBuilderInitEvent('RSS_BUILDER_INIT', $builder, []);

        $this->makeService()->handleRssBuilderInit($event);
    }

    public function testNoWarningWhenCategoryMetadataEmptyAndSiteConfigHasNoPodcastKey(): void
    {
        $this->logger->expects(self::never())->method('log');

        $builder = new RssBuilder();
        $event = new RssBuilderInitEvent('RSS_BUILDER_INIT', $builder, []);

        $this->makeService()->handleRssBuilderInit($event);
    }

    public function testNoWarningWhenGateIsSatisfied(): void
    {
        $this->logger->expects(self::never())->method('log');

        $builder = new RssBuilder();
        $event = new RssBuilderInitEvent(
            'RSS_BUILDER_INIT',
            $builder,
            ['podcast' => true, 'itunes_author' => 'Someone'],
        );

        $this->makeService()->handleRssBuilderInit($event);
    }

    // --- RSS_ITEM_BUILDING: enclosure absolutization ---------------------------------

    public function testAbsoluteEnclosureUrlIsLeftAlone(): void
    {
        $item = new FeedItem('Ep', 'https://example.com/ep', 'guid-1', 'Mon, 01 Jan 2024 00:00:00 +0000', [
            'audio_url' => 'https://cdn.example.com/ep1.mp3',
        ]);
        $event = new RssItemBuildingEvent('RSS_ITEM_BUILDING', $item, ['metadata' => $item->metadata]);

        $this->makeService()->handleRssItemBuilding($event);

        self::assertSame('https://cdn.example.com/ep1.mp3', $this->enclosureOf($item)['url']);
    }

    public function testRelativeEnclosureUrlIsJoinedToSiteBaseUrlWithoutTrailingSlash(): void
    {
        $this->setContainerVariable('SITE_BASE_URL', 'https://example.com');

        $item = new FeedItem('Ep', 'https://example.com/ep', 'guid-1', 'Mon, 01 Jan 2024 00:00:00 +0000', [
            'audio_url' => '/media/ep1.mp3',
        ]);
        $event = new RssItemBuildingEvent('RSS_ITEM_BUILDING', $item, ['metadata' => $item->metadata]);

        $this->makeService()->handleRssItemBuilding($event);

        self::assertSame('https://example.com/media/ep1.mp3', $this->enclosureOf($item)['url']);
    }

    public function testRelativeEnclosureUrlIsJoinedToSiteBaseUrlWithTrailingSlash(): void
    {
        $this->setContainerVariable('SITE_BASE_URL', 'https://example.com/');

        $item = new FeedItem('Ep', 'https://example.com/ep', 'guid-1', 'Mon, 01 Jan 2024 00:00:00 +0000', [
            'audio_url' => '/media/ep1.mp3',
        ]);
        $event = new RssItemBuildingEvent('RSS_ITEM_BUILDING', $item, ['metadata' => $item->metadata]);

        $this->makeService()->handleRssItemBuilding($event);

        self::assertSame('https://example.com/media/ep1.mp3', $this->enclosureOf($item)['url']);
    }

    public function testEnclosureLengthAndTypeDefaultsWhenMissing(): void
    {
        $item = new FeedItem('Ep', 'https://example.com/ep', 'guid-1', 'Mon, 01 Jan 2024 00:00:00 +0000', [
            'audio_url' => 'https://cdn.example.com/ep1.mp3',
        ]);
        $event = new RssItemBuildingEvent('RSS_ITEM_BUILDING', $item, ['metadata' => $item->metadata]);

        $this->makeService()->handleRssItemBuilding($event);

        self::assertSame(0, $this->enclosureOf($item)['length']);
        self::assertSame('application/octet-stream', $this->enclosureOf($item)['type']);
    }

    public function testEnclosureUsesSuppliedLengthAndType(): void
    {
        $item = new FeedItem('Ep', 'https://example.com/ep', 'guid-1', 'Mon, 01 Jan 2024 00:00:00 +0000', [
            'audio_url' => 'https://cdn.example.com/ep1.mp3',
            'media_length' => 123456,
            'media_type' => 'audio/mpeg',
        ]);
        $event = new RssItemBuildingEvent('RSS_ITEM_BUILDING', $item, ['metadata' => $item->metadata]);

        $this->makeService()->handleRssItemBuilding($event);

        self::assertSame(123456, $this->enclosureOf($item)['length']);
        self::assertSame('audio/mpeg', $this->enclosureOf($item)['type']);
    }

    public function testVideoUrlIsUsedWhenAudioUrlAbsent(): void
    {
        $item = new FeedItem('Ep', 'https://example.com/ep', 'guid-1', 'Mon, 01 Jan 2024 00:00:00 +0000', [
            'video_url' => 'https://cdn.example.com/ep1.mp4',
        ]);
        $event = new RssItemBuildingEvent('RSS_ITEM_BUILDING', $item, ['metadata' => $item->metadata]);

        $this->makeService()->handleRssItemBuilding($event);

        self::assertSame('https://cdn.example.com/ep1.mp4', $this->enclosureOf($item)['url']);
    }

    public function testNoEnclosureWhenNoMediaUrlPresent(): void
    {
        $item = new FeedItem('Ep', 'https://example.com/ep', 'guid-1', 'Mon, 01 Jan 2024 00:00:00 +0000', []);
        $event = new RssItemBuildingEvent('RSS_ITEM_BUILDING', $item, ['metadata' => []]);

        $this->makeService()->handleRssItemBuilding($event);

        self::assertNull($item->enclosure);
    }

    public function testNoEnclosureWhenFileMetadataIsNotAnArray(): void
    {
        $item = new FeedItem('Ep', 'https://example.com/ep', 'guid-1', 'Mon, 01 Jan 2024 00:00:00 +0000', []);
        $event = new RssItemBuildingEvent('RSS_ITEM_BUILDING', $item, ['metadata' => 'not-an-array']);

        $this->makeService()->handleRssItemBuilding($event);

        self::assertNull($item->enclosure);
    }

    // --- RSS_ITEM_BUILDING: show notes -----------------------------------------------

    public function testShowNotesHtmlBecomesItemContentWhenPresent(): void
    {
        $item = new FeedItem('Ep', 'https://example.com/ep', 'guid-1', 'Mon, 01 Jan 2024 00:00:00 +0000', [
            'audio_url' => 'https://cdn.example.com/ep1.mp3',
            'podcast_show_notes_html' => '<p>Show notes</p>',
        ]);
        $event = new RssItemBuildingEvent('RSS_ITEM_BUILDING', $item, ['metadata' => $item->metadata]);

        $this->makeService()->handleRssItemBuilding($event);

        self::assertSame('<p>Show notes</p>', $item->content);
    }

    public function testItemContentIsNullWhenShowNotesHtmlAbsent(): void
    {
        $item = new FeedItem('Ep', 'https://example.com/ep', 'guid-1', 'Mon, 01 Jan 2024 00:00:00 +0000', [
            'audio_url' => 'https://cdn.example.com/ep1.mp3',
        ]);
        $item->content = 'stale value that must never survive';
        $event = new RssItemBuildingEvent('RSS_ITEM_BUILDING', $item, ['metadata' => $item->metadata]);

        $this->makeService()->handleRssItemBuilding($event);

        self::assertNull($item->content);
    }

    public function testItemContentIsNeverTheFullRenderedPage(): void
    {
        // Guards against the historical regression: falling back to the
        // entire rendered page (head/nav/footer) inside content:encoded.
        $item = new FeedItem('Ep', 'https://example.com/ep', 'guid-1', 'Mon, 01 Jan 2024 00:00:00 +0000', [
            'audio_url' => 'https://cdn.example.com/ep1.mp3',
        ]);
        $event = new RssItemBuildingEvent(
            'RSS_ITEM_BUILDING',
            $item,
            ['metadata' => $item->metadata, 'renderedContent' => '<html><body>whole page</body></html>'],
        );

        $this->makeService()->handleRssItemBuilding($event);

        self::assertNull($item->content);
    }
}
