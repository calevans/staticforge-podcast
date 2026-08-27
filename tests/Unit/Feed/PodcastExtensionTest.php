<?php

declare(strict_types=1);

namespace Calevans\StaticForgePodcast\Tests\Unit\Feed;

use Calevans\StaticForgePodcast\Feed\PodcastExtension;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use EICC\StaticForge\Features\RssFeed\Models\FeedChannel;
use EICC\StaticForge\Features\RssFeed\Models\FeedItem;
use EICC\StaticForge\Features\RssFeed\Services\RssBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for the historical `createElement($name, $value)`
 * XML-corruption bug (a bare "&" silently drops the whole value; an HTML
 * entity like "&ndash;" makes the entire feed fail to re-parse), the
 * config-vs-metadata source of channel tags, and the itunes:explicit /
 * itunes:category value handling.
 */
final class PodcastExtensionTest extends TestCase
{
    /**
     * @param array<string, mixed> $config
     */
    private function buildChannelXPath(array $config, string $siteBaseUrl = 'https://example.com'): DOMXPath
    {
        return $this->parse($this->buildChannelXml($config, $siteBaseUrl));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function buildChannelXml(array $config, string $siteBaseUrl = 'https://example.com'): string
    {
        $builder = new RssBuilder();
        $builder->addExtension(new PodcastExtension($siteBaseUrl, $config));

        $channel = new FeedChannel(
            'Test Feed',
            'https://example.com/',
            'A test feed',
            'https://example.com/rss.xml',
            ['itunes_author' => 'FromMetadataShouldNeverAppear'],
        );

        return $builder->build($channel, []);
    }

    /**
     * @param array<string, mixed> $itemMetadata
     */
    private function buildItemXPath(
        array $itemMetadata,
        string $siteBaseUrl = 'https://example.com',
        ?callable $configureItem = null,
    ): DOMXPath {
        $builder = new RssBuilder();
        $builder->addExtension(new PodcastExtension($siteBaseUrl, ['itunes_type' => 'episodic']));

        $channel = new FeedChannel('Test Feed', 'https://example.com/', 'A test feed', 'https://example.com/rss.xml');

        $item = new FeedItem(
            'Episode 1',
            'https://example.com/e1',
            'guid-1',
            'Mon, 01 Jan 2024 00:00:00 +0000',
            $itemMetadata,
        );
        if ($configureItem !== null) {
            $configureItem($item);
        }

        return $this->parse($builder->build($channel, [$item]));
    }

    private function parse(string $xml): DOMXPath
    {
        if ($xml === '') {
            self::fail('RssBuilder produced empty XML');
        }

        $doc = new DOMDocument();
        $loaded = $doc->loadXML($xml);
        self::assertTrue($loaded, 'Generated feed XML failed to re-parse');

        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('itunes', 'http://www.itunes.com/dtds/podcast-1.0.dtd');

        return $xpath;
    }

    private function nodeCount(DOMXPath $xpath, string $expr, ?DOMNode $context = null): int
    {
        $nodes = $xpath->query($expr, $context);
        self::assertNotFalse($nodes, "Invalid XPath expression: {$expr}");

        return $nodes->count();
    }

    private function firstElement(DOMXPath $xpath, string $expr, ?DOMNode $context = null): DOMElement
    {
        $nodes = $xpath->query($expr, $context);
        self::assertNotFalse($nodes, "Invalid XPath expression: {$expr}");

        $node = $nodes->item(0);
        self::assertInstanceOf(DOMElement::class, $node, "No element matched: {$expr}");

        return $node;
    }

    private function textOf(DOMXPath $xpath, string $expr, ?DOMNode $context = null): string
    {
        return $this->firstElement($xpath, $expr, $context)->textContent;
    }

    // --- XML escaping regressions --------------------------------------------------

    public function testAmpersandSurvivesRoundTripInATextNode(): void
    {
        $xpath = $this->buildChannelXPath(['itunes_author' => 'Tom & Jerry']);

        self::assertSame(1, $this->nodeCount($xpath, '//itunes:author'));
        self::assertSame('Tom & Jerry', $this->textOf($xpath, '//itunes:author'));
    }

    public function testAmpersandSurvivesRoundTripInAnAttribute(): void
    {
        $xpath = $this->buildChannelXPath(['itunes_image' => 'https://example.com/art.png?a=1&b=2']);

        self::assertSame(
            'https://example.com/art.png?a=1&b=2',
            $this->firstElement($xpath, '//itunes:image')->getAttribute('href'),
        );
    }

    public function testHtmlEntityLikeTextReparsesSuccessfully(): void
    {
        // The historical bug: createElement() serialized "&ndash;" literally,
        // producing an undefined entity reference that made loadXML() fail
        // for the WHOLE feed, not just this one field.
        $xml = $this->buildChannelXml(['itunes_summary' => 'Fun for ages 50&ndash;60']);
        if ($xml === '') {
            self::fail('RssBuilder produced empty XML');
        }

        $doc = new DOMDocument();
        self::assertTrue($doc->loadXML($xml), 'Feed containing "&ndash;" failed to re-parse');

        $xpath = $this->parse($xml);
        self::assertSame('Fun for ages 50&ndash;60', $this->textOf($xpath, '//itunes:summary'));
    }

    public function testItemLevelAmpersandSurvivesRoundTrip(): void
    {
        $xpath = $this->buildItemXPath(['itunes_subtitle' => 'Salt & Pepper']);

        self::assertSame('Salt & Pepper', $this->textOf($xpath, '//item/itunes:subtitle'));
    }

    // --- Channel tags come from injected config, never $data->metadata -------------

    public function testChannelTagsComeFromInjectedConfigNotItemMetadata(): void
    {
        // buildChannelXPath() always sets FeedChannel::$metadata to
        // ['itunes_author' => 'FromMetadataShouldNeverAppear']; the config
        // array below carries a different value for the same key.
        $xpath = $this->buildChannelXPath(['itunes_author' => 'FromConfig']);

        self::assertSame(1, $this->nodeCount($xpath, '//itunes:author'));
        self::assertSame('FromConfig', $this->textOf($xpath, '//itunes:author'));
    }

    public function testKeyPresentOnlyInConfigReachesChannelXml(): void
    {
        $xpath = $this->buildChannelXPath(['itunes_owner_email' => 'owner@example.com']);

        self::assertSame(1, $this->nodeCount($xpath, '//itunes:owner/itunes:email'));
        self::assertSame('owner@example.com', $this->textOf($xpath, '//itunes:owner/itunes:email'));
    }

    // --- itunes:explicit --------------------------------------------------

    public function testItunesExplicitIsAlwaysEmittedAtChannelLevelDefaultingFalse(): void
    {
        $xpath = $this->buildChannelXPath([]);

        self::assertSame(1, $this->nodeCount($xpath, '//itunes:explicit'));
        self::assertSame('false', $this->textOf($xpath, '//itunes:explicit'));
    }

    /**
     * @dataProvider truthyExplicitValues
     */
    public function testNormalizeExplicitAcceptsKnownTruthyValues(mixed $value): void
    {
        $xpath = $this->buildChannelXPath(['itunes_explicit' => $value]);

        self::assertSame('true', $this->textOf($xpath, '//itunes:explicit'));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function truthyExplicitValues(): array
    {
        return [
            'boolean true' => [true],
            "string 'true'" => ['true'],
            "string 'yes'" => ['yes'],
            'integer 1' => [1],
        ];
    }

    /**
     * @dataProvider falsyExplicitValues
     */
    public function testNormalizeExplicitRejectsEverythingElse(mixed $value): void
    {
        $xpath = $this->buildChannelXPath(['itunes_explicit' => $value]);

        self::assertSame('false', $this->textOf($xpath, '//itunes:explicit'));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function falsyExplicitValues(): array
    {
        return [
            'boolean false' => [false],
            "string 'false'" => ['false'],
            "string 'no'" => ['no'],
            'integer 0' => [0],
            'integer 2' => [2],
            "string 'YES' (wrong case)" => ['YES'],
            "string 'True' (wrong case)" => ['True'],
            "string '1'" => ['1'],
            'null' => [null],
        ];
    }

    // --- itunes_category ----------------------------------------------------------

    public function testItunesCategoryAsPlainString(): void
    {
        $xpath = $this->buildChannelXPath(['itunes_category' => 'Arts']);

        self::assertSame(1, $this->nodeCount($xpath, '//channel/itunes:category'));
        $category = $this->firstElement($xpath, '//channel/itunes:category');
        self::assertSame('Arts', $category->getAttribute('text'));
        self::assertSame(0, $this->nodeCount($xpath, 'itunes:category', $category));
    }

    public function testItunesCategoryAsNestedString(): void
    {
        $xpath = $this->buildChannelXPath(['itunes_category' => 'Arts > Design']);

        $top = $this->firstElement($xpath, '//channel/itunes:category');
        self::assertSame(1, $this->nodeCount($xpath, '//channel/itunes:category'));
        self::assertSame('Arts', $top->getAttribute('text'));

        self::assertSame(1, $this->nodeCount($xpath, 'itunes:category', $top));
        $child = $this->firstElement($xpath, 'itunes:category', $top);
        self::assertSame('Design', $child->getAttribute('text'));
    }

    public function testItunesCategoryAsList(): void
    {
        $xpath = $this->buildChannelXPath(['itunes_category' => ['Arts', 'Technology']]);

        $nodes = $xpath->query('//channel/itunes:category');
        self::assertNotFalse($nodes);
        self::assertSame(2, $nodes->count());

        $first = $nodes->item(0);
        $second = $nodes->item(1);
        self::assertInstanceOf(DOMElement::class, $first);
        self::assertInstanceOf(DOMElement::class, $second);
        self::assertSame('Arts', $first->getAttribute('text'));
        self::assertSame('Technology', $second->getAttribute('text'));
    }

    public function testItunesCategoryListSkipsNonStringEntryInsteadOfThrowing(): void
    {
        $xpath = $this->buildChannelXPath([
            'itunes_category' => ['Arts', 123, ['nested', 'list'], '', 'Health'],
        ]);

        $nodes = $xpath->query('//channel/itunes:category');
        self::assertNotFalse($nodes);
        self::assertSame(2, $nodes->count());

        $first = $nodes->item(0);
        $second = $nodes->item(1);
        self::assertInstanceOf(DOMElement::class, $first);
        self::assertInstanceOf(DOMElement::class, $second);
        self::assertSame('Arts', $first->getAttribute('text'));
        self::assertSame('Health', $second->getAttribute('text'));
    }

    public function testNoItunesCategoryConfiguredEmitsNoCategoryElements(): void
    {
        $xpath = $this->buildChannelXPath([]);

        self::assertSame(0, $this->nodeCount($xpath, '//itunes:category'));
    }

    // --- podcast (podcastindex) namespace must never be declared --------------------

    public function testGetNamespacesDeclaresOnlyItunes(): void
    {
        $extension = new PodcastExtension('https://example.com');

        self::assertSame(
            ['itunes' => 'http://www.itunes.com/dtds/podcast-1.0.dtd'],
            $extension->getNamespaces(),
        );
    }

    public function testBuiltFeedNeverDeclaresThePodcastNamespace(): void
    {
        $xml = $this->buildChannelXml(['itunes_category' => 'Arts', 'itunes_explicit' => true]);

        self::assertStringNotContainsString('xmlns:podcast', $xml);
    }

    // --- other channel-level fields --------------------------------------------------

    public function testCopyrightIsGeneratedFromOwnerNameOnlyWhenChannelCopyrightIsMissing(): void
    {
        $xpath = $this->buildChannelXPath(['itunes_owner_name' => 'Acme Media']);

        self::assertSame(1, $this->nodeCount($xpath, '//channel/copyright'));
        self::assertSame('© ' . date('Y') . ' Acme Media', $this->textOf($xpath, '//channel/copyright'));
    }

    public function testItunesTypeDefaultsToEpisodic(): void
    {
        $xpath = $this->buildChannelXPath([]);

        self::assertSame('episodic', $this->textOf($xpath, '//itunes:type'));
    }

    public function testItunesTypeUsesConfiguredValue(): void
    {
        $xpath = $this->buildChannelXPath(['itunes_type' => 'serial']);

        self::assertSame('serial', $this->textOf($xpath, '//itunes:type'));
    }

    public function testShortChannelDescriptionIsReplacedByItunesSummary(): void
    {
        // FeedChannel description in these tests is "A test feed" (11 chars, < 50).
        $longSummary = 'A much longer and more descriptive summary of the show.';
        $xpath = $this->buildChannelXPath(['itunes_summary' => $longSummary]);

        self::assertSame($longSummary, $this->textOf($xpath, '//channel/description'));
        self::assertSame($longSummary, $this->textOf($xpath, '//itunes:summary'));
    }

    public function testOwnerElementOmittedWhenNeitherNameNorEmailConfigured(): void
    {
        $xpath = $this->buildChannelXPath([]);

        self::assertSame(0, $this->nodeCount($xpath, '//itunes:owner'));
    }

    public function testChannelImageHrefIsAbsolutizedWhenRelative(): void
    {
        $xpath = $this->buildChannelXPath(['itunes_image' => 'art/cover.png'], 'https://example.com/show');

        self::assertSame(
            'https://example.com/show/art/cover.png',
            $this->firstElement($xpath, '//itunes:image')->getAttribute('href'),
        );
    }

    public function testChannelImageHrefLeftAloneWhenAlreadyAbsolute(): void
    {
        $xpath = $this->buildChannelXPath(['itunes_image' => 'https://cdn.example.com/cover.png']);

        self::assertSame(
            'https://cdn.example.com/cover.png',
            $this->firstElement($xpath, '//itunes:image')->getAttribute('href'),
        );
    }

    // --- item-level fields ------------------------------------------------------------

    public function testApplyToItemUsesFileMetadataFields(): void
    {
        $xpath = $this->buildItemXPath([
            'itunes_title' => 'Episode Title',
            'itunes_episode_type' => 'full',
            'itunes_author' => 'Episode Author',
            'itunes_duration' => '00:12:34',
            'itunes_explicit' => 'yes',
            'itunes_episode' => '3',
            'itunes_season' => '1',
        ]);

        self::assertSame('Episode Title', $this->textOf($xpath, '//item/itunes:title'));
        self::assertSame('full', $this->textOf($xpath, '//item/itunes:episodeType'));
        self::assertSame('Episode Author', $this->textOf($xpath, '//item/itunes:author'));
        self::assertSame('00:12:34', $this->textOf($xpath, '//item/itunes:duration'));
        self::assertSame('true', $this->textOf($xpath, '//item/itunes:explicit'));
        self::assertSame('3', $this->textOf($xpath, '//item/itunes:episode'));
        self::assertSame('1', $this->textOf($xpath, '//item/itunes:season'));
    }

    public function testApplyToItemOmitsExplicitWhenNotSetInMetadata(): void
    {
        $xpath = $this->buildItemXPath([]);

        self::assertSame(0, $this->nodeCount($xpath, '//item/itunes:explicit'));
    }

    public function testApplyToItemCastsIntegerDuration(): void
    {
        $xpath = $this->buildItemXPath(['itunes_duration' => 754]);

        self::assertSame('754', $this->textOf($xpath, '//item/itunes:duration'));
    }

    public function testApplyToItemFallsBackToItemAuthorAndDescriptionWhenMetadataAbsent(): void
    {
        $xpath = $this->buildItemXPath([], configureItem: static function (FeedItem $item): void {
            $item->author = 'Fallback Author';
            $item->description = 'Fallback description text.';
        });

        self::assertSame('Fallback Author', $this->textOf($xpath, '//item/itunes:author'));
        self::assertSame('Fallback description text.', $this->textOf($xpath, '//item/itunes:summary'));
    }

    public function testApplyToItemPrefersMetadataOverItemFallbacks(): void
    {
        $xpath = $this->buildItemXPath(
            ['itunes_author' => 'Metadata Author', 'itunes_summary' => 'Metadata summary.'],
            configureItem: static function (FeedItem $item): void {
                $item->author = 'Fallback Author';
                $item->description = 'Fallback description text.';
            },
        );

        self::assertSame('Metadata Author', $this->textOf($xpath, '//item/itunes:author'));
        self::assertSame('Metadata summary.', $this->textOf($xpath, '//item/itunes:summary'));
    }

    public function testApplyToItemImageHrefIsAbsolutizedWhenRelative(): void
    {
        $xpath = $this->buildItemXPath(['itunes_image' => 'ep1.png'], 'https://example.com/show/');

        self::assertSame(
            'https://example.com/show/ep1.png',
            $this->firstElement($xpath, '//item/itunes:image')->getAttribute('href'),
        );
    }
}
