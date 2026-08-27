<?php

declare(strict_types=1);

namespace Calevans\StaticForgePodcast\Services;

use Calevans\StaticForgePodcast\Models\EpisodeMedia;
use Calevans\StaticForgePodcast\Models\MediaInfo;
use EICC\StaticForge\Core\Events\RenderEvent;
use EICC\StaticForge\Core\PathGuard;
use EICC\Utils\Container;
use EICC\Utils\Log;
use RuntimeException;

/**
 * Resolves episode media at PRE_RENDER, but only as far as
 * cache/podcast/media/ (stage + tag + inspect). The actual copy into
 * OUTPUT_DIR is deferred to POST_LOOP (publishPending(), priority 110)
 * because core's TemplateAssets feature unconditionally overwrites
 * OUTPUT_DIR/assets/** from the untagged SOURCE_DIR/assets/** copy at its own
 * POST_LOOP priority 100 - publishing any earlier would let that clobber the
 * tagged artifact after the feed already advertised its (tagged) byte length.
 */
final class EpisodeMediaService
{
    /** Mime types a cached entry is allowed to claim; anything else forces a re-inspect. */
    private const CACHEABLE_MIME_TYPES = [
        'audio/mpeg',
        'audio/mp4',
        'audio/x-m4a',
        'audio/aac',
        'audio/ogg',
        'audio/wav',
        'audio/x-wav',
        'audio/flac',
        'video/mp4',
        'video/quicktime',
        'video/x-m4v',
        'video/webm',
    ];

    /** @var array<string, array<string, mixed>|null> already-resolved metadata additions, keyed by filePath */
    private array $processed = [];

    /** @var array<string, string> relative path => absolute artifact path to publish */
    private array $pendingPublishes = [];

    public function __construct(
        private readonly Log $logger,
        private readonly Container $container,
        private readonly MediaStateCache $stateCache,
        private readonly MediaInspector $mediaInspector,
        private readonly Id3TagWriter $tagWriter,
    ) {
    }

    /**
     * PRE_RENDER can fire more than once for the same filePath in one build
     * (deferred category pages re-enter once per pagination page, and a
     * later PRE_RENDER listener may discard the file entirely) - clear the
     * per-build memo and pending-publish list before a fresh build starts.
     */
    public function resetForNewBuild(): void
    {
        $this->processed = [];
        $this->pendingPublishes = [];
    }

    public function onPreRender(RenderEvent $event): void
    {
        if (array_key_exists($event->filePath, $this->processed)) {
            $this->applyAdditions($event, $this->processed[$event->filePath]);
            return;
        }

        $additions = $this->resolve($event);
        $this->processed[$event->filePath] = $additions;
        $this->applyAdditions($event, $additions);
    }

    public function onMarkdownConverted(RenderEvent $event): void
    {
        $metadata = $event->metadata;
        if (!isset($metadata['audio_file']) && !isset($metadata['video_file'])) {
            return;
        }

        $event->metadata['podcast_show_notes_html'] = $event->renderedContent ?? '';
    }

    public function publishPending(): void
    {
        $outputDir = $this->outputDir();

        foreach ($this->pendingPublishes as $relativePath => $artifactPath) {
            try {
                $targetPath = PathGuard::resolveInside(
                    $outputDir . DIRECTORY_SEPARATOR . $relativePath,
                    $outputDir
                );
            } catch (RuntimeException $e) {
                $this->logger->log('WARNING', "Podcast: publish path escapes OUTPUT_DIR: {$e->getMessage()}");
                continue;
            }

            $this->publishOne($artifactPath, $targetPath);
        }
    }

    private function publishOne(string $artifactPath, string $targetPath): void
    {
        if (!is_file($artifactPath)) {
            $this->logger->log('WARNING', "Podcast: staged/master artifact missing at publish time: {$artifactPath}");
            return;
        }

        $targetDir = dirname($targetPath);
        if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            $this->logger->log('WARNING', "Podcast: could not create output directory: {$targetDir}");
            return;
        }

        $tempPath = $targetPath . '.tmp';

        if (!copy($artifactPath, $tempPath)) {
            $this->logger->log('WARNING', "Podcast: could not stage publish copy: {$tempPath}");
            return;
        }

        if (!rename($tempPath, $targetPath)) {
            $this->logger->log('WARNING', "Podcast: could not finalize publish to: {$targetPath}");
            if (is_file($tempPath)) {
                unlink($tempPath);
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolve(RenderEvent $event): ?array
    {
        $metadata = $event->metadata;
        $rawMedia = $metadata['audio_file'] ?? $metadata['video_file'] ?? null;

        if (!is_string($rawMedia) || $rawMedia === '') {
            return null;
        }

        if (str_contains($rawMedia, "\0")) {
            $this->logger->log('WARNING', "Podcast: rejected null byte in media path for {$event->filePath}");
            return null;
        }

        $isVideo = isset($metadata['video_file']);

        $media = preg_match('~^https?://~i', $rawMedia) === 1
            ? $this->resolveRemote($rawMedia, $metadata, $isVideo)
            : $this->resolveLocal($rawMedia, $metadata, $isVideo, $event->filePath);

        if ($media === null) {
            return null;
        }

        $additions = [
            $media->isVideo ? 'video_url' : 'audio_url' => $media->url,
            'media_type' => $media->type,
            'media_length' => $media->length,
        ];

        if (!isset($metadata['itunes_duration']) && $media->duration !== null) {
            $additions['itunes_duration'] = $media->duration;
        }

        return $additions;
    }

    /**
     * @param array<string, mixed>|null $additions
     */
    private function applyAdditions(RenderEvent $event, ?array $additions): void
    {
        if ($additions === null) {
            return;
        }

        foreach ($additions as $key => $value) {
            $event->metadata[$key] = $value;
        }
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function resolveRemote(string $url, array $metadata, bool $isVideo): EpisodeMedia
    {
        $defaultType = $isVideo ? 'video/mp4' : 'audio/mpeg';
        $type = $metadata['audio_type'] ?? $metadata['video_type'] ?? $defaultType;

        return new EpisodeMedia(
            url: $url,
            length: (int) ($metadata['audio_size'] ?? $metadata['video_size'] ?? 0),
            type: is_string($type) && $type !== '' ? $type : $defaultType,
            duration: null,
            isVideo: $isVideo,
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function resolveLocal(string $rawMedia, array $metadata, bool $isVideo, string $filePath): ?EpisodeMedia
    {
        $sourceDir = $this->sourceDir();

        $sourcePath = SafePath::resolveExisting(
            $sourceDir . DIRECTORY_SEPARATOR . ltrim($rawMedia, '/\\'),
            $sourceDir
        );

        if ($sourcePath === null || !is_file($sourcePath)) {
            $this->logger->log(
                'WARNING',
                "Podcast: media file not found inside SOURCE_DIR for {$filePath}: {$rawMedia}"
            );
            return null;
        }

        // $sourcePath came back from realpath(), so compare it against a
        // realpath'd root too - otherwise a symlinked SOURCE_DIR makes every
        // path look outside the root and collapses them all to basename().
        $realSourceDir = realpath($sourceDir);
        $relativePath = PathGuard::relativeTo(
            $sourcePath,
            $realSourceDir === false ? $sourceDir : $realSourceDir
        ) ?? basename($sourcePath);

        $sourceMtime = filemtime($sourcePath);
        $sourceSize = filesize($sourcePath);
        if ($sourceMtime === false || $sourceSize === false) {
            $this->logger->log('WARNING', "Podcast: could not stat media file: {$sourcePath}");
            return null;
        }

        $metadataHash = md5((string) json_encode([
            'title' => $metadata['title'] ?? '',
            'author' => $metadata['itunes_author'] ?? '',
            'episode' => $metadata['itunes_episode'] ?? '',
            'description' => $metadata['description'] ?? '',
            'image' => $metadata['itunes_image'] ?? '',
            'date' => $metadata['date'] ?? '',
        ]));

        try {
            $stagedPath = PathGuard::resolveInside(
                $this->cacheMediaRoot() . DIRECTORY_SEPARATOR . $relativePath,
                $this->cacheMediaRoot()
            );
        } catch (RuntimeException $e) {
            $this->logger->log(
                'WARNING',
                "Podcast: staged path escapes cache root for {$filePath}: {$e->getMessage()}"
            );
            return null;
        }

        [$mediaInfo, $artifactPath] = $this->mediaInfoFor(
            $sourcePath,
            $stagedPath,
            $relativePath,
            $sourceMtime,
            $sourceSize,
            $metadataHash,
            $metadata,
            $sourceDir,
            $filePath
        );

        if ($mediaInfo === null) {
            return null;
        }

        $this->pendingPublishes[$relativePath] = $artifactPath;

        return new EpisodeMedia(
            url: self::toUrlPath($relativePath),
            length: $mediaInfo->size,
            type: $mediaInfo->mimeType,
            duration: $mediaInfo->formattedDuration,
            isVideo: $isVideo,
        );
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array{0: MediaInfo|null, 1: string}
     */
    private function mediaInfoFor(
        string $sourcePath,
        string $stagedPath,
        string $relativePath,
        int $sourceMtime,
        int $sourceSize,
        string $metadataHash,
        array $metadata,
        string $sourceDir,
        string $filePath
    ): array {
        $cached = $this->stateCache->get($relativePath);
        $artifactPath = is_file($stagedPath) ? $stagedPath : $sourcePath;
        $artifactSize = $sourceSize;

        if ($artifactPath === $stagedPath) {
            $stagedSize = filesize($stagedPath);
            $artifactSize = $stagedSize === false ? -1 : $stagedSize;
        }

        // cache/ is not necessarily trustworthy - if it ever gets committed, a
        // crafted state.json could otherwise put an arbitrary string into
        // <enclosure type>. Size is already cross-checked against a live
        // filesize() below; mime gets an allowlist.
        $cacheHit = $cached !== null
            && $cached['source_mtime'] === $sourceMtime
            && $cached['source_size'] === $sourceSize
            && $cached['metadata_hash'] === $metadataHash
            && $artifactSize === $cached['published_size']
            && in_array($cached['mime'], self::CACHEABLE_MIME_TYPES, true);

        if ($cacheHit) {
            /** @var array{source_mtime: int, source_size: int, metadata_hash: string, published_path: string, published_size: int, mime: string, duration_seconds: float} $cached */
            return [
                new MediaInfo(
                    size: $cached['published_size'],
                    mimeType: $cached['mime'],
                    durationSeconds: $cached['duration_seconds'],
                    formattedDuration: MediaInspector::formatDuration($cached['duration_seconds']),
                ),
                $artifactPath,
            ];
        }

        $result = $this->stageTagAndInspect($sourcePath, $stagedPath, $metadata, $sourceDir, $filePath);
        if ($result === null) {
            return [null, $artifactPath];
        }

        [$mediaInfo, $freshArtifactPath] = $result;

        $this->stateCache->set($relativePath, [
            'source_mtime' => $sourceMtime,
            'source_size' => $sourceSize,
            'metadata_hash' => $metadataHash,
            'published_path' => $relativePath,
            'published_size' => $mediaInfo->size,
            'mime' => $mediaInfo->mimeType,
            'duration_seconds' => $mediaInfo->durationSeconds,
        ]);

        return [$mediaInfo, $freshArtifactPath];
    }

    /**
     * Stage (copy source -> cache/podcast/media/) and tag the staged copy
     * only when it's MPEG audio - that is the only format getid3_writetags
     * understands. Everything else is inspected in place on the master and
     * left there for publishPending() to copy straight to OUTPUT_DIR: a
     * staged copy of media that will never be tagged is just a second full
     * copy of every episode for no benefit. The returned artifact path is
     * always the correct one for THIS build's mime type, regardless of
     * whatever stale file may or may not already sit at $stagedPath.
     *
     * @param array<string, mixed> $metadata
     * @return array{0: MediaInfo, 1: string}|null
     */
    private function stageTagAndInspect(
        string $sourcePath,
        string $stagedPath,
        array $metadata,
        string $sourceDir,
        string $filePath
    ): ?array {
        try {
            $masterInfo = $this->mediaInspector->inspect($sourcePath);
        } catch (RuntimeException $e) {
            $this->logger->log('WARNING', "Podcast: could not inspect media for {$filePath}: {$e->getMessage()}");
            return null;
        }

        if ($masterInfo->mimeType !== 'audio/mpeg') {
            return [$masterInfo, $sourcePath];
        }

        $stagedDir = dirname($stagedPath);
        if (!is_dir($stagedDir) && !mkdir($stagedDir, 0755, true) && !is_dir($stagedDir)) {
            $this->logger->log('WARNING', "Podcast: could not create staging directory: {$stagedDir}");
            return null;
        }

        if (!copy($sourcePath, $stagedPath)) {
            $this->logger->log('WARNING', "Podcast: could not stage media file to: {$stagedPath}");
            return null;
        }

        $this->tagWriter->write($stagedPath, $masterInfo->mimeType, $metadata, $sourceDir, $this->siteName());

        $taggedSize = filesize($stagedPath);
        if ($taggedSize === false) {
            $this->logger->log('WARNING', "Podcast: could not stat staged media file: {$stagedPath}");
            return null;
        }

        return [
            new MediaInfo(
                size: $taggedSize,
                mimeType: $masterInfo->mimeType,
                durationSeconds: $masterInfo->durationSeconds,
                formattedDuration: $masterInfo->formattedDuration,
            ),
            $stagedPath,
        ];
    }

    /**
     * A filename is not a URL: an unencoded '#' or '?' truncates the enclosure
     * at the client and the episode 404s for every subscriber.
     */
    private static function toUrlPath(string $relativePath): string
    {
        $segments = explode('/', str_replace('\\', '/', $relativePath));

        return '/' . implode('/', array_map(rawurlencode(...), $segments));
    }

    private function sourceDir(): string
    {
        return rtrim((string) $this->container->getVariable('SOURCE_DIR'), '/\\');
    }

    private function outputDir(): string
    {
        return rtrim((string) $this->container->getVariable('OUTPUT_DIR'), '/\\');
    }

    private function cacheMediaRoot(): string
    {
        return rtrim((string) $this->container->getVariable('app_root'), '/\\') . '/cache/podcast/media';
    }

    private function siteName(): string
    {
        $siteConfig = $this->container->getVariable('site_config');
        $siteConfig = is_array($siteConfig) ? $siteConfig : [];
        $site = is_array($siteConfig['site'] ?? null) ? $siteConfig['site'] : [];
        $name = $site['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : 'Podcast';
    }
}
