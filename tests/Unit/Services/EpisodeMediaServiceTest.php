<?php

declare(strict_types=1);

namespace Calevans\StaticForgePodcast\Tests\Unit\Services;

use Calevans\StaticForgePodcast\Services\EpisodeMediaService;
use Calevans\StaticForgePodcast\Services\Id3TagWriter;
use Calevans\StaticForgePodcast\Services\MediaInspector;
use Calevans\StaticForgePodcast\Services\MediaStateCache;
use Calevans\StaticForgePodcast\Tests\TestCase;
use EICC\StaticForge\Core\Events\RenderEvent;

/**
 * Real temp directories throughout: PathGuard passes vfs:// paths through
 * unchecked, so containment assertions made against vfsStream would pass even
 * with the guards removed. Media fixtures are synthesised as real MPEG frames
 * rather than committed as binaries.
 */
class EpisodeMediaServiceTest extends TestCase
{
    private string $base;
    private string $sourceDir;
    private string $outputDir;
    private string $outside;

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = sys_get_temp_dir() . '/sf_episode_' . uniqid();
        $this->sourceDir = $this->base . '/content';
        $this->outputDir = $this->base . '/public';
        $this->outside = $this->base . '/private';

        mkdir($this->sourceDir . '/audio', 0755, true);
        mkdir($this->outputDir, 0755, true);
        mkdir($this->outside, 0755, true);

        $this->setContainerVariable('SOURCE_DIR', $this->sourceDir);
        $this->setContainerVariable('OUTPUT_DIR', $this->outputDir);
        $this->setContainerVariable('app_root', $this->base);
        $this->setContainerVariable('site_config', ['site' => ['name' => 'Test Show']]);
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

    /**
     * A minimal but genuinely parseable MPEG-1 Layer III stream: 128kbps,
     * 44.1kHz, $frames silent frames. getID3 reports audio/mpeg for this.
     */
    private function writeMp3(string $path, int $frames = 40): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, str_repeat("\xFF\xFB\x90\x00" . str_repeat("\x00", 413), $frames));
    }

    private function makeService(): EpisodeMediaService
    {
        return new EpisodeMediaService(
            $this->logger,
            $this->container,
            new MediaStateCache($this->base . '/cache/podcast/state.json', $this->logger),
            new MediaInspector(),
            new Id3TagWriter($this->logger),
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function preRender(EpisodeMediaService $service, array $metadata): RenderEvent
    {
        $event = new RenderEvent(
            name: 'PRE_RENDER',
            filePath: $this->sourceDir . '/episodes/ep1.md',
            fileUrl: '/episodes/ep1/',
            metadata: $metadata,
        );

        $service->onPreRender($event);

        return $event;
    }

    public function testResolvesLocalAudioAndPopulatesMediaMetadata(): void
    {
        $this->writeMp3($this->sourceDir . '/audio/ep1.mp3');

        $event = $this->preRender($this->makeService(), [
            'title' => 'Episode 1',
            'audio_file' => '/audio/ep1.mp3',
        ]);

        $this->assertSame('/audio/ep1.mp3', $event->metadata['audio_url']);
        $this->assertSame('audio/mpeg', $event->metadata['media_type']);
        $this->assertGreaterThan(0, $event->metadata['media_length']);
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $event->metadata['itunes_duration']);
    }

    public function testDoesNotOverwriteAnAuthorSuppliedDuration(): void
    {
        $this->writeMp3($this->sourceDir . '/audio/ep1.mp3');

        $event = $this->preRender($this->makeService(), [
            'audio_file' => '/audio/ep1.mp3',
            'itunes_duration' => '42:00',
        ]);

        $this->assertSame('42:00', $event->metadata['itunes_duration']);
    }

    public function testNeverWritesToTheSourceFile(): void
    {
        $path = $this->sourceDir . '/audio/ep1.mp3';
        $this->writeMp3($path);
        $before = md5_file($path);

        $this->preRender($this->makeService(), [
            'title' => 'Episode 1',
            'itunes_author' => 'Jane Doe',
            'audio_file' => '/audio/ep1.mp3',
        ]);

        $this->assertSame($before, md5_file($path), 'the build must treat content/ as read-only');
    }

    public function testTagsTheStagedCopyAndAdvertisesItsLengthNotTheMasters(): void
    {
        $path = $this->sourceDir . '/audio/ep1.mp3';
        $this->writeMp3($path);
        $masterSize = filesize($path);

        $event = $this->preRender($this->makeService(), [
            'title' => 'Episode 1',
            'itunes_author' => 'Jane Doe',
            'audio_file' => '/audio/ep1.mp3',
        ]);

        $staged = $this->base . '/cache/podcast/media/audio/ep1.mp3';
        $this->assertFileExists($staged);
        $this->assertGreaterThan($masterSize, filesize($staged), 'staged copy should carry ID3 tags');
        $this->assertSame(filesize($staged), $event->metadata['media_length']);
    }

    public function testRejectsDotDotTraversalInAudioFile(): void
    {
        $this->writeMp3($this->outside . '/secret.mp3');

        $event = $this->preRender($this->makeService(), [
            'audio_file' => '../private/secret.mp3',
        ]);

        $this->assertArrayNotHasKey('audio_url', $event->metadata);
        $this->assertArrayNotHasKey('media_length', $event->metadata);
    }

    public function testRejectsAnAbsolutePathOutsideTheSourceDirectory(): void
    {
        $this->writeMp3($this->outside . '/secret.mp3');

        $event = $this->preRender($this->makeService(), [
            'audio_file' => $this->outside . '/secret.mp3',
        ]);

        $this->assertArrayNotHasKey('audio_url', $event->metadata);
    }

    public function testRejectsASymlinkedMediaFileEscapingTheSourceDirectory(): void
    {
        $this->writeMp3($this->outside . '/secret.mp3');
        $link = $this->sourceDir . '/audio/ep1.mp3';

        if (!@symlink($this->outside . '/secret.mp3', $link)) {
            $this->markTestSkipped('Filesystem does not support symlinks.');
        }

        $event = $this->preRender($this->makeService(), ['audio_file' => '/audio/ep1.mp3']);

        $this->assertArrayNotHasKey('audio_url', $event->metadata);
    }

    public function testRejectsANullByteInTheMediaPath(): void
    {
        $event = $this->preRender($this->makeService(), [
            'audio_file' => "/audio/ep1.mp3\0.txt",
        ]);

        $this->assertArrayNotHasKey('audio_url', $event->metadata);
    }

    public function testRemoteUrlIsPassedThroughWithoutTouchingTheFilesystem(): void
    {
        $event = $this->preRender($this->makeService(), [
            'audio_file' => 'https://cdn.example.com/ep1.mp3',
            'audio_size' => 12345,
            'audio_type' => 'audio/mpeg',
        ]);

        $this->assertSame('https://cdn.example.com/ep1.mp3', $event->metadata['audio_url']);
        $this->assertSame(12345, $event->metadata['media_length']);
        $this->assertDirectoryDoesNotExist($this->base . '/cache/podcast/media');
    }

    public function testVideoFileUsesVideoUrlKey(): void
    {
        $this->writeMp3($this->sourceDir . '/audio/ep1.mp3');

        $event = $this->preRender($this->makeService(), ['video_file' => '/audio/ep1.mp3']);

        $this->assertArrayHasKey('video_url', $event->metadata);
        $this->assertArrayNotHasKey('audio_url', $event->metadata);
    }

    /**
     * A '#' or '?' in a filename truncates the enclosure URL at the client and
     * 404s the episode for every subscriber.
     */
    public function testEnclosureUrlPercentEncodesEachPathSegment(): void
    {
        $this->writeMp3($this->sourceDir . '/audio/ep 12 #final.mp3');

        $event = $this->preRender($this->makeService(), [
            'audio_file' => '/audio/ep 12 #final.mp3',
        ]);

        $this->assertSame('/audio/ep%2012%20%23final.mp3', $event->metadata['audio_url']);
    }

    public function testPublishesStagedMediaIntoTheOutputDirectory(): void
    {
        $this->writeMp3($this->sourceDir . '/audio/ep1.mp3');

        $service = $this->makeService();
        $this->preRender($service, ['title' => 'Episode 1', 'audio_file' => '/audio/ep1.mp3']);
        $service->publishPending();

        $published = $this->outputDir . '/audio/ep1.mp3';
        $this->assertFileExists($published);
        $this->assertSame(
            md5_file($this->base . '/cache/podcast/media/audio/ep1.mp3'),
            md5_file($published),
            'published file must be the tagged staged copy'
        );
        $this->assertFileDoesNotExist($published . '.tmp');
    }

    /**
     * `site:render --clean` wipes OUTPUT_DIR but not cache/, and none of the
     * source-derived cache keys change - so a naive cache hit would skip the
     * publish and leave the feed pointing at files that no longer exist.
     */
    public function testCleanBuildRepublishesEvenThoughTheStateCacheStillHits(): void
    {
        $this->writeMp3($this->sourceDir . '/audio/ep1.mp3');
        $metadata = ['title' => 'Episode 1', 'audio_file' => '/audio/ep1.mp3'];

        $first = $this->makeService();
        $this->preRender($first, $metadata);
        $first->publishPending();
        $this->assertFileExists($this->outputDir . '/audio/ep1.mp3');

        $this->removeDirectory($this->outputDir);
        mkdir($this->outputDir, 0755, true);

        $second = $this->makeService();
        $event = $this->preRender($second, $metadata);
        $second->publishPending();

        $this->assertFileExists(
            $this->outputDir . '/audio/ep1.mp3',
            'a --clean build must republish rather than trust the state cache'
        );
        $this->assertSame(filesize($this->outputDir . '/audio/ep1.mp3'), $event->metadata['media_length']);
    }

    public function testRestagesWhenTheStagedArtifactIsDeletedButTheCacheStillHits(): void
    {
        $this->writeMp3($this->sourceDir . '/audio/ep1.mp3');
        $metadata = ['title' => 'Episode 1', 'audio_file' => '/audio/ep1.mp3'];

        $first = $this->makeService();
        $this->preRender($first, $metadata);

        $staged = $this->base . '/cache/podcast/media/audio/ep1.mp3';
        $taggedSize = filesize($staged);
        unlink($staged);

        $second = $this->makeService();
        $event = $this->preRender($second, $metadata);

        $this->assertFileExists($staged);
        $this->assertSame($taggedSize, $event->metadata['media_length']);
    }

    public function testProcessesTheSameSourceFileOnlyOncePerBuild(): void
    {
        $this->writeMp3($this->sourceDir . '/audio/ep1.mp3');
        $service = $this->makeService();
        $metadata = ['title' => 'Episode 1', 'audio_file' => '/audio/ep1.mp3'];

        $this->preRender($service, $metadata);
        $staged = $this->base . '/cache/podcast/media/audio/ep1.mp3';
        $firstMtime = filemtime($staged);

        $second = $this->preRender($service, $metadata);

        $this->assertSame($firstMtime, filemtime($staged));
        $this->assertArrayHasKey('audio_url', $second->metadata);
    }

    public function testMissingMediaFileIsSkippedWithoutMetadata(): void
    {
        $event = $this->preRender($this->makeService(), ['audio_file' => '/audio/nope.mp3']);

        $this->assertArrayNotHasKey('audio_url', $event->metadata);
    }

    public function testPageWithNoMediaIsLeftAlone(): void
    {
        $event = $this->preRender($this->makeService(), ['title' => 'Just a page']);

        $this->assertSame(['title' => 'Just a page'], $event->metadata);
    }

    public function testShowNotesAreCapturedFromConvertedMarkdown(): void
    {
        $event = new RenderEvent(
            name: 'MARKDOWN_CONVERTED',
            filePath: $this->sourceDir . '/episodes/ep1.md',
            fileUrl: '/episodes/ep1/',
            metadata: ['audio_file' => '/audio/ep1.mp3'],
            renderedContent: '<p>Show notes</p>',
        );

        $this->makeService()->onMarkdownConverted($event);

        $this->assertSame('<p>Show notes</p>', $event->metadata['podcast_show_notes_html']);
    }

    public function testShowNotesAreNotCapturedForNonEpisodePages(): void
    {
        $event = new RenderEvent(
            name: 'MARKDOWN_CONVERTED',
            filePath: $this->sourceDir . '/about.md',
            fileUrl: '/about/',
            metadata: ['title' => 'About'],
            renderedContent: '<p>About us</p>',
        );

        $this->makeService()->onMarkdownConverted($event);

        $this->assertArrayNotHasKey('podcast_show_notes_html', $event->metadata);
    }
}
