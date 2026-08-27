<?php

declare(strict_types=1);

namespace Calevans\StaticForgePodcast\Services;

use EICC\Utils\Log;

/**
 * Persists, across builds, enough state to skip re-staging/re-tagging/
 * re-analyzing an unchanged episode. NO I/O in the constructor - the cache
 * file is only read on first get()/set() and only written by flush(), so
 * merely registering the Feature (which happens on every CLI invocation)
 * never touches disk.
 *
 * published_size/mime/duration_seconds are cached alongside the source
 * fingerprint specifically so a cache hit never has to re-run
 * getID3::analyze() (the expensive part) - without them a "hit" would still
 * cost a full analyze and the cache would save almost nothing.
 */
final class MediaStateCache
{
    private const SCHEMA_VERSION = 1;

    /** @var array<string, array<string, mixed>> */
    private array $entries = [];
    private bool $loaded = false;
    private bool $dirty = false;

    public function __construct(
        private readonly string $cachePath,
        private readonly Log $logger,
    ) {
    }

    /**
     * @return array{
     *     source_mtime: int,
     *     source_size: int,
     *     metadata_hash: string,
     *     published_path: string,
     *     published_size: int,
     *     mime: string,
     *     duration_seconds: float
     * }|null
     */
    public function get(string $relativePath): ?array
    {
        $this->load();

        $entry = $this->entries[$relativePath] ?? null;
        if (!is_array($entry) || !$this->isCompleteEntry($entry)) {
            return null;
        }

        return [
            'source_mtime' => (int) $entry['source_mtime'],
            'source_size' => (int) $entry['source_size'],
            'metadata_hash' => (string) $entry['metadata_hash'],
            'published_path' => (string) $entry['published_path'],
            'published_size' => (int) $entry['published_size'],
            'mime' => (string) $entry['mime'],
            'duration_seconds' => (float) $entry['duration_seconds'],
        ];
    }

    /**
     * @param array{
     *     source_mtime: int,
     *     source_size: int,
     *     metadata_hash: string,
     *     published_path: string,
     *     published_size: int,
     *     mime: string,
     *     duration_seconds: float
     * } $entry
     */
    public function set(string $relativePath, array $entry): void
    {
        $this->load();
        $this->entries[$relativePath] = $entry;
        $this->dirty = true;
    }

    public function flush(): void
    {
        if (!$this->dirty) {
            return;
        }

        $dir = dirname($this->cachePath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            $this->logger->log('WARNING', "Podcast: could not create cache directory: {$dir}");
            return;
        }

        $payload = json_encode(
            ['version' => self::SCHEMA_VERSION, 'entries' => $this->entries],
            JSON_PRETTY_PRINT
        );
        if ($payload === false) {
            $this->logger->log('WARNING', 'Podcast: could not encode media state cache');
            return;
        }

        $tempFile = null;

        try {
            $created = tempnam($dir, 'podcast_state_');
            if ($created === false) {
                $this->logger->log('WARNING', 'Podcast: could not create temp file for media state cache');
                return;
            }

            $tempFile = $created;

            if (file_put_contents($tempFile, $payload) === false) {
                $this->logger->log('WARNING', 'Podcast: could not write media state cache');
                return;
            }

            if (!rename($tempFile, $this->cachePath)) {
                $this->logger->log('WARNING', 'Podcast: could not finalize media state cache');
                return;
            }

            $this->dirty = false;
            $tempFile = null;
        } finally {
            if ($tempFile !== null && is_file($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function isCompleteEntry(array $entry): bool
    {
        return isset(
            $entry['source_mtime'],
            $entry['source_size'],
            $entry['metadata_hash'],
            $entry['published_path'],
            $entry['published_size'],
            $entry['mime'],
            $entry['duration_seconds'],
        );
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;

        if (!is_file($this->cachePath)) {
            return;
        }

        $raw = file_get_contents($this->cachePath);
        if ($raw === false) {
            $this->logger->log('WARNING', "Podcast: could not read media state cache: {$this->cachePath}");
            return;
        }

        $decoded = json_decode($raw, true);
        if (
            !is_array($decoded)
            || ($decoded['version'] ?? null) !== self::SCHEMA_VERSION
            || !is_array($decoded['entries'] ?? null)
        ) {
            $this->logger->log(
                'WARNING',
                "Podcast: discarding corrupt or unsupported media state cache: {$this->cachePath}"
            );
            return;
        }

        $this->entries = array_filter($decoded['entries'], 'is_array');
    }
}
