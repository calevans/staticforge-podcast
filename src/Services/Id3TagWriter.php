<?php

declare(strict_types=1);

namespace Calevans\StaticForgePodcast\Services;

use EICC\Utils\Log;
use getID3;
use getid3_writetags;
use RuntimeException;

/**
 * The only class in this package that touches getid3_writetags. Callers must
 * pass an already-inspected mime type; getID3's write support only
 * understands MPEG audio (ID3v1/v2), so everything else (mp4/m4a/ogg/video)
 * is skipped here rather than handed to WriteTags(), which errors on them.
 */
final class Id3TagWriter
{
    private const ALLOWED_IMAGE_MIME_TYPES = ['image/jpeg', 'image/png'];

    /** Apple caps cover art at 3000x3000; 5MB is generous for that. */
    private const MAX_IMAGE_BYTES = 5 * 1024 * 1024;

    public function __construct(private readonly Log $logger)
    {
    }

    /**
     * @param array<string, mixed> $metadata
     * @throws RuntimeException If getID3 reports a write failure
     */
    public function write(
        string $filePath,
        string $mimeType,
        array $metadata,
        string $sourceDir,
        string $siteName
    ): void {
        if ($mimeType !== 'audio/mpeg') {
            $this->logger->log('INFO', "Podcast: skipping ID3 tagging for non-MPEG media ({$mimeType}): {$filePath}");
            return;
        }

        // Touching getID3 first guarantees GETID3_INCLUDEPATH is defined and
        // the write.* classes are autoloadable before constructing the writer.
        new getID3();

        $tagWriter = new getid3_writetags();
        $tagWriter->filename = $filePath;
        $tagWriter->tagformats = ['id3v2.3', 'id3v1'];
        $tagWriter->overwrite_tags = true;
        $tagWriter->tag_encoding = 'UTF-8';

        $tagData = [
            'title' => [(string) ($metadata['title'] ?? '')],
            'artist' => [(string) ($metadata['itunes_author'] ?? '')],
            'album' => [$siteName],
            'comment' => [(string) ($metadata['description'] ?? '')],
            'year' => [substr((string) ($metadata['date'] ?? ''), 0, 4)],
            'track_number' => [(string) ($metadata['itunes_episode'] ?? '')],
            'genre' => ['Podcast'],
        ];

        $image = $this->resolveImage($metadata['itunes_image'] ?? null, $sourceDir);
        if ($image !== null) {
            $tagData['attached_picture'] = [[
                'data' => $image['data'],
                'picturetypeid' => 3,
                'description' => 'Cover Art',
                'mime' => $image['mime'],
            ]];
        }

        $tagWriter->tag_data = $tagData;

        if (!$tagWriter->WriteTags()) {
            throw new RuntimeException(
                'Failed to write ID3 tags to ' . basename($filePath) . ': ' . implode(', ', $tagWriter->errors)
            );
        }
    }

    /**
     * @return array{data: string, mime: string}|null
     */
    private function resolveImage(mixed $rawImagePath, string $sourceDir): ?array
    {
        if (!is_string($rawImagePath) || $rawImagePath === '' || str_contains($rawImagePath, "\0")) {
            return null;
        }

        $imagePath = SafePath::resolveExisting(
            $sourceDir . DIRECTORY_SEPARATOR . ltrim($rawImagePath, '/\\'),
            $sourceDir
        );

        if ($imagePath === null || !is_file($imagePath)) {
            $this->logger->log('WARNING', "Podcast: itunes_image not found inside SOURCE_DIR: {$rawImagePath}");
            return null;
        }

        $mime = mime_content_type($imagePath);
        if ($mime === false || !in_array($mime, self::ALLOWED_IMAGE_MIME_TYPES, true)) {
            $this->logger->log('WARNING', "Podcast: itunes_image has disallowed mime type: {$imagePath}");
            return null;
        }

        // mime_content_type() sniffs only the leading magic bytes, so a JPEG
        // header followed by 400MB of anything else still reports image/jpeg.
        // Cap the read and make getimagesize() agree before embedding.
        $size = filesize($imagePath);
        if ($size === false || $size > self::MAX_IMAGE_BYTES) {
            $this->logger->log('WARNING', "Podcast: itunes_image is too large to embed: {$imagePath}");
            return null;
        }

        $dimensions = getimagesize($imagePath);
        if ($dimensions === false || $dimensions['mime'] !== $mime) {
            $this->logger->log('WARNING', "Podcast: itunes_image is not a valid image: {$imagePath}");
            return null;
        }

        $data = file_get_contents($imagePath);
        if ($data === false) {
            $this->logger->log('WARNING', "Podcast: could not read itunes_image: {$imagePath}");
            return null;
        }

        return ['data' => $data, 'mime' => $mime];
    }
}
