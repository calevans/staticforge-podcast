<?php

declare(strict_types=1);

namespace Calevans\StaticForgePodcast\Tests\Unit\Services;

use Calevans\StaticForgePodcast\Services\MediaInspector;
use PHPUnit\Framework\TestCase;

/**
 * MediaInspector::formatDuration() is public/static specifically so a
 * MediaStateCache hit can rebuild the display string from cached
 * duration_seconds without re-running getID3::analyze() - it needs no
 * getID3 instance and no file on disk.
 */
final class MediaInspectorTest extends TestCase
{
    public function testZeroSecondsFormatsAsMinutesSeconds(): void
    {
        self::assertSame('00:00', MediaInspector::formatDuration(0.0));
    }

    public function testDurationBelowAnHourFormatsAsMinutesSeconds(): void
    {
        self::assertSame('02:05', MediaInspector::formatDuration(125.0));
    }

    public function testDurationAboveAnHourFormatsAsHoursMinutesSeconds(): void
    {
        self::assertSame('01:02:05', MediaInspector::formatDuration(3725.0));
    }

    public function testDurationRoundsDownJustBelowTheNextSecondBoundary(): void
    {
        self::assertSame('00:59', MediaInspector::formatDuration(59.4));
    }

    public function testDurationRoundsUpAtTheHalfSecondBoundary(): void
    {
        self::assertSame('01:00', MediaInspector::formatDuration(59.5));
    }

    public function testDurationJustBelowOneHourStaysInMinutesSecondsFormat(): void
    {
        self::assertSame('59:59', MediaInspector::formatDuration(3599.4));
    }

    public function testDurationRoundingUpCrossesIntoHoursFormat(): void
    {
        self::assertSame('01:00:00', MediaInspector::formatDuration(3599.5));
    }

    public function testExactlyOneHourFormatsWithHours(): void
    {
        self::assertSame('01:00:00', MediaInspector::formatDuration(3600.0));
    }

    public function testMultiHourDurationFormatsCorrectly(): void
    {
        self::assertSame('02:15:30', MediaInspector::formatDuration(8130.0));
    }
}
