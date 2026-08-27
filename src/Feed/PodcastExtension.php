<?php

declare(strict_types=1);

namespace Calevans\StaticForgePodcast\Feed;

use DOMDocument;
use DOMElement;
use EICC\StaticForge\Features\RssFeed\Models\FeedChannel;
use EICC\StaticForge\Features\RssFeed\Models\FeedItem;
use EICC\StaticForge\Features\RssFeed\Services\Extensions\FeedExtensionInterface;

/**
 * Channel-level iTunes tags come exclusively from the PodcastConfig::resolve()
 * result passed in here, NEVER from $data->metadata: RssFeedService builds
 * FeedChannel straight from the (possibly definition-less) category
 * frontmatter and never sees the site-config-merged result, so reading
 * $data->metadata here would silently reproduce the exact "siteconfig
 * itunes_* keys ignored" bug this release exists to fix.
 *
 * applyToItem correctly keeps reading $data->metadata - that IS the
 * per-episode file frontmatter and is not subject to the same merge.
 */
final class PodcastExtension implements FeedExtensionInterface
{
    private readonly string $siteBaseUrl;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(string $siteBaseUrl, private readonly array $config = [])
    {
        $this->siteBaseUrl = rtrim($siteBaseUrl, '/') . '/';
    }

    public function getNamespaces(): array
    {
        return [
            'itunes' => 'http://www.itunes.com/dtds/podcast-1.0.dtd',
        ];
    }

    public function applyToChannel(DOMElement $channel, FeedChannel $data, DOMDocument $dom): void
    {
        $config = $this->config;

        $ownerName = $config['itunes_owner_name'] ?? null;
        $ownerEmail = $config['itunes_owner_email'] ?? null;

        if ($data->copyright === null && is_string($ownerName) && $ownerName !== '') {
            $this->addText($dom, $channel, 'copyright', '© ' . date('Y') . ' ' . $ownerName);
        }

        $type = $config['itunes_type'] ?? null;
        $this->addText($dom, $channel, 'itunes:type', is_string($type) && $type !== '' ? $type : 'episodic');

        $author = $config['itunes_author'] ?? null;
        if (is_string($author) && $author !== '') {
            $this->addText($dom, $channel, 'itunes:author', $author);
        }

        $summary = $config['itunes_summary'] ?? null;
        if (is_string($summary) && $summary !== '') {
            $descNode = $channel->getElementsByTagName('description')->item(0);
            if ($descNode instanceof DOMElement && strlen($descNode->textContent) < 50) {
                $descNode->textContent = $summary;
            }
            $this->addText($dom, $channel, 'itunes:summary', $summary);
        }

        if ((is_string($ownerName) && $ownerName !== '') || (is_string($ownerEmail) && $ownerEmail !== '')) {
            $owner = $dom->createElement('itunes:owner');
            if (is_string($ownerName) && $ownerName !== '') {
                $this->addText($dom, $owner, 'itunes:name', $ownerName);
            }
            if (is_string($ownerEmail) && $ownerEmail !== '') {
                $this->addText($dom, $owner, 'itunes:email', $ownerEmail);
            }
            $channel->appendChild($owner);
        }

        $image = $config['itunes_image'] ?? null;
        if (is_string($image) && $image !== '') {
            $imageEl = $dom->createElement('itunes:image');
            $imageEl->setAttribute('href', $this->resolveUrl($image));
            $channel->appendChild($imageEl);
        }

        $this->applyCategories($channel, $dom, $config['itunes_category'] ?? null);

        $explicit = $this->normalizeExplicit($config['itunes_explicit'] ?? false);
        $this->addText($dom, $channel, 'itunes:explicit', $explicit);
    }

    public function applyToItem(DOMElement $item, FeedItem $data, DOMDocument $dom): void
    {
        $metadata = $data->metadata;

        $title = $metadata['itunes_title'] ?? null;
        if (is_string($title) && $title !== '') {
            $this->addText($dom, $item, 'itunes:title', $title);
        }

        $episodeType = $metadata['itunes_episode_type'] ?? null;
        if (is_string($episodeType) && $episodeType !== '') {
            $this->addText($dom, $item, 'itunes:episodeType', $episodeType);
        }

        $author = $metadata['itunes_author'] ?? $data->author;
        if (is_string($author) && $author !== '') {
            $this->addText($dom, $item, 'itunes:author', $author);
        }

        $subtitle = $metadata['itunes_subtitle'] ?? null;
        if (is_string($subtitle) && $subtitle !== '') {
            $this->addText($dom, $item, 'itunes:subtitle', $subtitle);
        }

        $summary = $metadata['itunes_summary'] ?? $data->description;
        if (is_string($summary) && $summary !== '') {
            $this->addText($dom, $item, 'itunes:summary', $summary);
        }

        $duration = $metadata['itunes_duration'] ?? null;
        if (is_string($duration) && $duration !== '') {
            $this->addText($dom, $item, 'itunes:duration', $duration);
        } elseif (is_int($duration)) {
            $this->addText($dom, $item, 'itunes:duration', (string) $duration);
        }

        if (isset($metadata['itunes_explicit'])) {
            $this->addText($dom, $item, 'itunes:explicit', $this->normalizeExplicit($metadata['itunes_explicit']));
        }

        $episode = $metadata['itunes_episode'] ?? null;
        if (is_numeric($episode)) {
            $this->addText($dom, $item, 'itunes:episode', (string) (int) $episode);
        }

        $season = $metadata['itunes_season'] ?? null;
        if (is_numeric($season)) {
            $this->addText($dom, $item, 'itunes:season', (string) (int) $season);
        }

        $image = $metadata['itunes_image'] ?? null;
        if (is_string($image) && $image !== '') {
            $imageEl = $dom->createElement('itunes:image');
            $imageEl->setAttribute('href', $this->resolveUrl($image));
            $item->appendChild($imageEl);
        }
    }

    private function applyCategories(DOMElement $channel, DOMDocument $dom, mixed $categories): void
    {
        if ($categories === null) {
            return;
        }

        if (!is_array($categories)) {
            $categories = [$categories];
        }

        foreach ($categories as $catValue) {
            if (!is_string($catValue) || $catValue === '') {
                continue;
            }

            if (str_contains($catValue, '>')) {
                [$primary, $secondary] = array_map('trim', explode('>', $catValue, 2));
                $catNode = $dom->createElement('itunes:category');
                $catNode->setAttribute('text', $primary);

                $subCatNode = $dom->createElement('itunes:category');
                $subCatNode->setAttribute('text', $secondary);
                $catNode->appendChild($subCatNode);

                $channel->appendChild($catNode);
                continue;
            }

            $catNode = $dom->createElement('itunes:category');
            $catNode->setAttribute('text', $catValue);
            $channel->appendChild($catNode);
        }
    }

    /**
     * Accepts true, 'true', 'yes', and 1 as explicit; everything else is 'false'.
     */
    private function normalizeExplicit(mixed $value): string
    {
        return in_array($value, [true, 'true', 'yes', 1], true) ? 'true' : 'false';
    }

    private function resolveUrl(string $url): string
    {
        if (preg_match('~^https?://~i', $url)) {
            return $url;
        }

        return $this->siteBaseUrl . ltrim($url, '/');
    }

    /**
     * DOMDocument::createElement($name, $value) does NOT escape entities in
     * $value - a bare "&" silently drops the whole value, and an HTML entity
     * like "&ndash;" serializes literally and makes the ENTIRE feed fail to
     * re-parse. Route every text element through createTextNode instead.
     */
    private function addText(DOMDocument $dom, DOMElement $parent, string $name, string $value): void
    {
        $element = $dom->createElement($name);
        $element->appendChild($dom->createTextNode($value));
        $parent->appendChild($element);
    }
}
