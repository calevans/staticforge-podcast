<?php

declare(strict_types=1);

namespace Calevans\StaticForgePodcast\Tests\Unit\Services;

use Calevans\StaticForgePodcast\Services\Id3TagWriter;
use Calevans\StaticForgePodcast\Tests\TestCase;
use getID3;

/**
 * The cover-art path was the original critical finding: an arbitrary file was
 * read and embedded as an ID3v2 APIC frame in an MP3 that then got published,
 * turning frontmatter into a read-any-file-and-publish-it primitive. These
 * tests assert the bytes never make it into the artifact.
 */
class Id3TagWriterTest extends TestCase
{
    private string $base;
    private string $sourceDir;
    private string $outside;
    private Id3TagWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = sys_get_temp_dir() . '/sf_id3_' . uniqid();
        $this->sourceDir = $this->base . '/content';
        $this->outside = $this->base . '/private';

        mkdir($this->sourceDir . '/assets', 0755, true);
        mkdir($this->outside, 0755, true);

        $this->writer = new Id3TagWriter($this->logger);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeDirectory($this->base);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
            $path = $dir . '/' . $entry;
            if (is_link($path) || is_file($path)) {
                unlink($path);
                continue;
            }
            $this->removeDirectory($path);
        }

        rmdir($dir);
    }

    private function makeMp3(): string
    {
        $path = $this->base . '/staged.mp3';
        file_put_contents($path, str_repeat("\xFF\xFB\x90\x00" . str_repeat("\x00", 413), 40));

        return $path;
    }

    /**
     * A real, complete 1x1 JPEG. Hardcoded rather than generated so the suite
     * carries no dependency on ext-gd and no binary fixture in the repo.
     */
    private function makeJpeg(string $path): void
    {
        $jpeg = base64_decode(
            '/9j/4AAQSkZJRgABAQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcg'
            . 'SlBFRyB2ODApLCBxdWFsaXR5ID0gODAK/9sAQwAGBAUGBQQGBgUGBwcGCAoQCgoJCQoUDg8MEBcU'
            . 'GBgXFBYWGh0lHxobIxwWFiAsICMmJykqKRkfLTAtKDAlKCko/9sAQwEHBwcKCAoTCgoTKBoWGigo'
            . 'KCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgo/8AAEQgAAQAB'
            . 'AwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMF'
            . 'BQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkq'
            . 'NDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqi'
            . 'o6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/E'
            . 'AB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMR'
            . 'BAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVG'
            . 'R0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKz'
            . 'tLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A'
            . '+VKKKKAP/9k='
        );

        file_put_contents($path, (string) $jpeg);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function tag(string $mp3, array $metadata): void
    {
        $this->writer->write($mp3, 'audio/mpeg', $metadata, $this->sourceDir, 'Test Show');
    }

    private function embeddedPictureBytes(string $mp3): ?string
    {
        $info = (new getID3())->analyze($mp3);

        return $info['id3v2']['APIC'][0]['data'] ?? null;
    }

    public function testWritesBasicTags(): void
    {
        $mp3 = $this->makeMp3();

        $this->tag($mp3, [
            'title' => 'Episode 1',
            'itunes_author' => 'Jane Doe',
            'date' => '2026-08-26',
            'itunes_episode' => 1,
        ]);

        $info = (new getID3())->analyze($mp3);

        $this->assertSame('Episode 1', $info['tags']['id3v2']['title'][0]);
        $this->assertSame('Jane Doe', $info['tags']['id3v2']['artist'][0]);
        $this->assertSame('Test Show', $info['tags']['id3v2']['album'][0]);
    }

    public function testEmbedsCoverArtFromInsideTheSourceDirectory(): void
    {
        $mp3 = $this->makeMp3();
        $this->makeJpeg($this->sourceDir . '/assets/cover.jpg');

        $this->tag($mp3, ['title' => 'Episode 1', 'itunes_image' => '/assets/cover.jpg']);

        $this->assertNotNull($this->embeddedPictureBytes($mp3));
    }

    public function testRefusesCoverArtThatTraversesOutOfTheSourceDirectory(): void
    {
        $mp3 = $this->makeMp3();
        $this->makeJpeg($this->outside . '/secret.jpg');

        $this->tag($mp3, ['title' => 'Episode 1', 'itunes_image' => '../private/secret.jpg']);

        $this->assertNull($this->embeddedPictureBytes($mp3));
    }

    /**
     * The variant PathGuard alone cannot catch: the path string is inside
     * content/, only the symlink target is not.
     */
    public function testRefusesCoverArtReachedThroughAnEscapingSymlink(): void
    {
        $mp3 = $this->makeMp3();
        $this->makeJpeg($this->outside . '/secret.jpg');
        $link = $this->sourceDir . '/assets/cover.jpg';

        if (!@symlink($this->outside . '/secret.jpg', $link)) {
            $this->markTestSkipped('Filesystem does not support symlinks.');
        }

        $this->tag($mp3, ['title' => 'Episode 1', 'itunes_image' => '/assets/cover.jpg']);

        $this->assertNull($this->embeddedPictureBytes($mp3));
    }

    public function testRefusesANonImageFileEvenWithAnImageExtension(): void
    {
        $mp3 = $this->makeMp3();
        file_put_contents($this->sourceDir . '/assets/cover.jpg', "PRIVATE KEY MATERIAL\n");

        $this->tag($mp3, ['title' => 'Episode 1', 'itunes_image' => '/assets/cover.jpg']);

        $this->assertNull($this->embeddedPictureBytes($mp3));
    }

    /**
     * mime_content_type() sniffs only the leading magic bytes, so a valid JPEG
     * header followed by arbitrary data still reports image/jpeg.
     */
    public function testRefusesAnOversizedFileWearingAJpegHeader(): void
    {
        $mp3 = $this->makeMp3();
        $path = $this->sourceDir . '/assets/cover.jpg';

        $this->makeJpeg($path);
        file_put_contents($path, str_repeat("\x00", 6 * 1024 * 1024), FILE_APPEND);

        $this->tag($mp3, ['title' => 'Episode 1', 'itunes_image' => '/assets/cover.jpg']);

        $this->assertNull($this->embeddedPictureBytes($mp3));
    }

    public function testRefusesANullByteInTheImagePath(): void
    {
        $mp3 = $this->makeMp3();
        $this->makeJpeg($this->sourceDir . '/assets/cover.jpg');

        $this->tag($mp3, ['title' => 'Episode 1', 'itunes_image' => "/assets/cover.jpg\0.txt"]);

        $this->assertNull($this->embeddedPictureBytes($mp3));
    }

    public function testMissingCoverArtIsSkippedWithoutFailingTheTagWrite(): void
    {
        $mp3 = $this->makeMp3();

        $this->tag($mp3, ['title' => 'Episode 1', 'itunes_image' => '/assets/nope.jpg']);

        $info = (new getID3())->analyze($mp3);
        $this->assertSame('Episode 1', $info['tags']['id3v2']['title'][0]);
        $this->assertNull($this->embeddedPictureBytes($mp3));
    }

    public function testNonMpegMediaIsLeftUntouched(): void
    {
        $path = $this->base . '/clip.m4a';
        file_put_contents($path, 'not really an m4a');
        $before = md5_file($path);

        $this->writer->write($path, 'audio/mp4', ['title' => 'Episode 1'], $this->sourceDir, 'Test Show');

        $this->assertSame($before, md5_file($path), 'only MPEG audio is taggable');
    }
}
