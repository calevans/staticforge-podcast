<?php

declare(strict_types=1);

namespace Calevans\StaticForgePodcast\Services;

use Calevans\StaticForgePodcast\Models\MediaInfo;
use getID3;
use RuntimeException;

final class MediaInspector
{
    private readonly getID3 $getID3;

    public function __construct()
    {
        $this->getID3 = new getID3();
    }

    /**
     * @throws RuntimeException If the file cannot be analyzed
     */
    public function inspect(string $filePath): MediaInfo
    {
        if (!is_file($filePath)) {
            throw new RuntimeException("File not found: {$filePath}");
        }

        $fileInfo = $this->getID3->analyze($filePath);

        if (isset($fileInfo['error'])) {
            throw new RuntimeException('Failed to analyze file: ' . implode(', ', (array) $fileInfo['error']));
        }

        $durationSeconds = (float) ($fileInfo['playtime_seconds'] ?? 0.0);

        return new MediaInfo(
            size: (int) ($fileInfo['filesize'] ?? 0),
            mimeType: (string) ($fileInfo['mime_type'] ?? 'application/octet-stream'),
            durationSeconds: $durationSeconds,
            formattedDuration: self::formatDuration($durationSeconds),
        );
    }

    /**
     * Format seconds into iTunes-compatible HH:MM:SS or MM:SS. Public/static so
     * a MediaStateCache hit can rebuild the display string from the cached
     * duration_seconds without re-running getID3::analyze().
     */
    public static function formatDuration(float $seconds): string
    {
        $totalSeconds = (int) round($seconds);

        $hours = intdiv($totalSeconds, 3600);
        $minutes = intdiv($totalSeconds % 3600, 60);
        $secs = $totalSeconds % 60;

        if ($hours > 0) {
            return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
        }

        return sprintf('%02d:%02d', $minutes, $secs);
    }
}
