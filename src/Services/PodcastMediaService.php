<?php

declare(strict_types=1);

namespace Calevans\StaticForgePodcast\Services;

use Calevans\StaticForgePodcast\Services\MediaInspect\MediaInspector;
use RuntimeException;

class PodcastMediaService
{
    private array $cache = [];

    public function __construct(
        private MediaInspector $mediaInspector,
        private string $siteName,
        private string $cachePath
    ) {
        $this->loadCache();
    }

    /**
     * Process podcast media file (copy local files, get metadata)
     *
     * @param array<string, mixed> $fileData File data including metadata
     * @param string $sourceDir Source content directory
     * @param string $outputDir Output directory
     * @return array{url: string, length: int, type: string}|null Enclosure data or null if no media
     */
    public function processMedia(array $fileData, string $sourceDir, string $outputDir): ?array
    {
        $metadata = $fileData['metadata'] ?? [];
        $audioFile = $metadata['audio_file'] ?? $metadata['video_file'] ?? null;

        if (!$audioFile) {
            return null;
        }

        // Check if remote URL
        if (preg_match('~^https?://~i', $audioFile)) {
            return [
                'url' => $audioFile,
                'length' => (int)($metadata['audio_size'] ?? 0),
                'type' => $metadata['audio_type'] ?? 'audio/mpeg',
            ];
        }

        // Handle local file
        $relativePath = ltrim($audioFile, '/\\');
        $sourcePath = $sourceDir . DIRECTORY_SEPARATOR . $relativePath;

        if (!file_exists($sourcePath)) {
            return null;
        }

        // Create target directory based on relative path
        $targetPath = $outputDir . DIRECTORY_SEPARATOR . $relativePath;
        $targetDir = dirname($targetPath);

        if (!is_dir($targetDir)) {
            if (!mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
                throw new RuntimeException(sprintf('Directory "%s" was not created', $targetDir));
            }
        }

        // Handle ID3 Tagging on SOURCE
        // We pass sourceDir to help resolve relative artwork paths
        $this->handleId3Tags($sourcePath, $metadata, $sourceDir);

        // Copy file if it doesn't exist or is newer
        if (!file_exists($targetPath) || filemtime($sourcePath) > filemtime($targetPath)) {
            copy($sourcePath, $targetPath);
        }

        // Use MediaInspector for metadata
        try {
            $info = $this->mediaInspector->inspect($targetPath);
            $length = $info['size'];
            $mimeType = $info['type'];
            // Duration is available in $info['duration'] if needed for itunes:duration
        } catch (\Exception $e) {
            // Fallback if inspection fails
            $length = filesize($targetPath);
            $mimeType = mime_content_type($targetPath) ?: 'audio/mpeg';
        }

        return [
            'url' => '/' . str_replace('\\', '/', $relativePath),
            'length' => $length,
            'type' => $mimeType,
        ];
    }

    private function handleId3Tags(string $filePath, array $metadata, string $sourceDir): void
    {
        $hashData = [
            'title' => $metadata['title'] ?? '',
            'artist' => $metadata['itunes_author'] ?? '',
            'album' => $this->siteName,
            'year' => $metadata['date'] ?? '',
            'track' => $metadata['itunes_episode'] ?? '',
            'comment' => $metadata['description'] ?? '',
            'image_path' => $metadata['itunes_image'] ?? '',
        ];

        $currentHash = md5(json_encode($hashData));
        $cacheKey = md5($filePath);
        $storedHash = $this->cache[$cacheKey] ?? null;

        // Update if hash doesn't match (meaning metadata changed or file is new to us)
        if ($storedHash !== $currentHash) {
            $this->writeTags($filePath, $metadata, $sourceDir);
            $this->cache[$cacheKey] = $currentHash;
            $this->saveCache();
        }
    }

    private function writeTags(string $filePath, array $metadata, string $sourceDir): void
    {
        // Setup getID3 environment
        if (!defined('GETID3_INCLUDEPATH')) {
            // Assume vendor structure relative to this file
            // src/Services/PodcastMediaService.php -> vendor/calevans/staticforge-podcast/src/Services
            // We need to go up to project root vendor
            $vendorDir = dirname(__DIR__, 5) . '/vendor';
            if (!is_dir($vendorDir)) {
                 // Fallback for dev environemnt
                 $vendorDir = dirname(__DIR__, 3) . '/vendor';
            }
            define('GETID3_INCLUDEPATH', $vendorDir . '/james-heinrich/getid3/getid3/');
        }

        // Include write.php if class not exists
        if (!class_exists('getid3_writetags')) {
            require_once(GETID3_INCLUDEPATH . 'write.php');
        }
        // Explicitly include ID3v2 writer
        require_once(GETID3_INCLUDEPATH . 'write.id3v2.php');

        $tagWriter = new \getid3_writetags();
        $tagWriter->filename = $filePath;

        $tagWriter->tagformats = ['id3v2.3', 'id3v1'];
        $tagWriter->overwrite_tags = true;
        // $tagWriter->remove_other_tags = true; // CAUTION: This might remove ID3v1 if only v2 is specified in formats
        $tagWriter->tag_encoding = 'UTF-8';

        $tagData = [
            'title' => [$metadata['title'] ?? ''],
            'artist' => [$metadata['itunes_author'] ?? ''],
            'album' => [$this->siteName],
            'comment' => [$metadata['description'] ?? ''],
            'year' => [substr($metadata['date'] ?? '', 0, 4)],
            'track_number' => [$metadata['itunes_episode'] ?? ''],
            'genre' => ['Podcast'],
        ];

        // Handle Cover Art
        if (!empty($metadata['itunes_image'])) {
            $imagePath = $this->resolveImagePath($metadata['itunes_image'], $sourceDir);
            if ($imagePath && file_exists($imagePath)) {
                $tagData['attached_picture'] = [[
                    'data' => file_get_contents($imagePath),
                    'picturetypeid' => 3, // Cover (front)
                    'description' => 'Cover Art',
                    'mime' => mime_content_type($imagePath)
                ]];
            } else {
                 // echo "Warning: Could not resolve cover art image path for " . basename($filePath) . "\n";
            }
        }

        $tagWriter->tag_data = $tagData;

        if (!$tagWriter->WriteTags()) {
            throw new RuntimeException("Failed to write tags to " . basename($filePath) . ": " . implode(', ', $tagWriter->errors));
        }
    }

    private function resolveImagePath(string $relativePath, string $sourceDir): ?string
    {
        // Resolve relative to source directory (content)
        $fullPath = $sourceDir . '/' . ltrim($relativePath, '/\\');

        if (file_exists($fullPath)) {
            return $fullPath;
        }

        return null;
    }

    private function loadCache(): void
    {
        if (file_exists($this->cachePath)) {
            $content = file_get_contents($this->cachePath);
            $this->cache = json_decode($content, true) ?: [];
        }
    }

    private function saveCache(): void
    {
        $dir = dirname($this->cachePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($this->cachePath, json_encode($this->cache, JSON_PRETTY_PRINT));
    }
}
