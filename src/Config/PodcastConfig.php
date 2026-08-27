<?php

declare(strict_types=1);

namespace Calevans\StaticForgePodcast\Config;

/**
 * Resolves channel-level iTunes metadata: the podcast-wide defaults in
 * siteconfig.yaml's `podcast:` root key, overridden KEY BY KEY by whatever
 * the category definition file's frontmatter sets directly. `platforms` is
 * deliberately excluded - it is a site-wide, badge-template-only concern and
 * is never overridden per category.
 */
final class PodcastConfig
{
    private const CHANNEL_KEYS = [
        'itunes_owner_name',
        'itunes_owner_email',
        'itunes_author',
        'itunes_category',
        'itunes_image',
        'itunes_type',
        'itunes_summary',
        'itunes_explicit',
    ];

    /**
     * @param array<string, mixed> $categoryMetadata
     * @param array<string, mixed> $siteConfig
     * @return array<string, mixed>
     */
    public static function resolve(array $categoryMetadata, array $siteConfig): array
    {
        $defaults = $siteConfig['podcast'] ?? [];
        $defaults = is_array($defaults) ? $defaults : [];

        $resolved = [];
        foreach (self::CHANNEL_KEYS as $key) {
            if (array_key_exists($key, $categoryMetadata)) {
                $resolved[$key] = $categoryMetadata[$key];
            } elseif (array_key_exists($key, $defaults)) {
                $resolved[$key] = $defaults[$key];
            }
        }

        return $resolved;
    }
}
