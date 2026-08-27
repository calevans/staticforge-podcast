<?php

declare(strict_types=1);

namespace Calevans\StaticForgePodcast\Tests\Unit\Config;

use Calevans\StaticForgePodcast\Config\PodcastConfig;
use PHPUnit\Framework\TestCase;

/**
 * PodcastConfig::resolve() is a pure key-by-key merge: category-definition
 * frontmatter overrides siteconfig.yaml's `podcast:` defaults, but only for
 * the fixed set of channel-level keys - `platforms` is deliberately excluded
 * because it is a site-wide, badge-template-only concern.
 */
final class PodcastConfigTest extends TestCase
{
    public function testCategoryFrontmatterOverridesSiteConfigDefaultForSameKey(): void
    {
        $resolved = PodcastConfig::resolve(
            ['itunes_author' => 'Category Author'],
            ['podcast' => ['itunes_author' => 'SiteWide Author']],
        );

        self::assertSame('Category Author', $resolved['itunes_author']);
    }

    public function testKeyPresentOnlyInSiteConfigStillLands(): void
    {
        $resolved = PodcastConfig::resolve(
            [],
            ['podcast' => ['itunes_owner_email' => 'owner@example.com']],
        );

        self::assertSame('owner@example.com', $resolved['itunes_owner_email']);
    }

    public function testKeyPresentOnlyInCategoryMetadataStillLands(): void
    {
        $resolved = PodcastConfig::resolve(
            ['itunes_explicit' => true],
            ['podcast' => []],
        );

        self::assertTrue($resolved['itunes_explicit']);
    }

    public function testKeyAbsentFromBothSourcesIsNotPresentInResult(): void
    {
        $resolved = PodcastConfig::resolve([], []);

        self::assertArrayNotHasKey('itunes_author', $resolved);
        self::assertSame([], $resolved);
    }

    public function testPlatformsKeyIsNeverIncludedEvenWhenPresentInBothSources(): void
    {
        $resolved = PodcastConfig::resolve(
            ['platforms' => ['apple' => 'https://podcasts.apple.com/x']],
            ['podcast' => ['platforms' => ['apple' => 'https://podcasts.apple.com/site-default']]],
        );

        self::assertArrayNotHasKey('platforms', $resolved);
    }

    public function testAllChannelKeysCanBeResolvedIndependently(): void
    {
        $categoryMetadata = [
            'itunes_owner_name' => 'Owner Name',
            'itunes_owner_email' => 'owner@example.com',
            'itunes_author' => 'Author',
            'itunes_category' => 'Arts',
            'itunes_image' => 'https://example.com/art.png',
            'itunes_type' => 'serial',
            'itunes_summary' => 'Summary text',
            'itunes_explicit' => true,
        ];

        $resolved = PodcastConfig::resolve($categoryMetadata, []);

        self::assertSame($categoryMetadata, $resolved);
    }

    public function testMissingPodcastKeyInSiteConfigIsTreatedAsNoDefaults(): void
    {
        $resolved = PodcastConfig::resolve(['itunes_author' => 'Category Author'], []);

        self::assertSame(['itunes_author' => 'Category Author'], $resolved);
    }

    public function testNonArrayPodcastValueInSiteConfigIsTreatedAsNoDefaults(): void
    {
        $resolved = PodcastConfig::resolve(
            ['itunes_author' => 'Category Author'],
            ['podcast' => 'not-an-array'],
        );

        self::assertSame(['itunes_author' => 'Category Author'], $resolved);
    }
}
