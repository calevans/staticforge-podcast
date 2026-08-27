<?php

declare(strict_types=1);

namespace Calevans\StaticForgePodcast\Tests\Unit\Services;

use Calevans\StaticForgePodcast\Services\MediaStateCache;
use Calevans\StaticForgePodcast\Tests\TestCase;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;

/**
 * A corrupt cache file, or one that decodes to a scalar rather than an
 * array, must degrade to an empty cache with a WARNING - never throw. A
 * TypeError here used to hard-fail every build from inside Feature::register().
 *
 * The read path (load()) only ever calls is_file()/file_get_contents(), so
 * it is covered here with vfsstream. The write path (flush()) additionally
 * calls tempnam(), which vfsstream does not honour - PHP silently creates
 * the temp file on the REAL filesystem and rename() then fails crossing
 * wrapper types - so flush()-exercising tests use a real temp directory
 * instead, cleaned up in tearDown().
 */
final class MediaStateCacheTest extends TestCase
{
    private vfsStreamDirectory $root;
    private string $realTempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = vfsStream::setup('root');
        $this->realTempDir = sys_get_temp_dir() . '/podcast_state_cache_test_' . uniqid();
        mkdir($this->realTempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeDirectory($this->realTempDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir), ['.', '..']) as $file) {
            $path = "$dir/$file";
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function vfsCachePath(): string
    {
        return vfsStream::url('root/cache/podcast/state.json');
    }

    private function realCachePath(): string
    {
        return $this->realTempDir . '/cache/podcast/state.json';
    }

    private function makeVfsCache(): MediaStateCache
    {
        return new MediaStateCache($this->vfsCachePath(), $this->logger);
    }

    private function makeRealCache(): MediaStateCache
    {
        return new MediaStateCache($this->realCachePath(), $this->logger);
    }

    /**
     * @return array{
     *     source_mtime: int, source_size: int, metadata_hash: string, published_path: string,
     *     published_size: int, mime: string, duration_seconds: float
     * }
     */
    private function sampleEntry(): array
    {
        return [
            'source_mtime' => 1700000000,
            'source_size' => 4096,
            'metadata_hash' => 'abc123',
            'published_path' => 'episodes/ep1.mp3',
            'published_size' => 4200,
            'mime' => 'audio/mpeg',
            'duration_seconds' => 754.3,
        ];
    }

    // --- no I/O in the constructor ---------------------------------------------------

    public function testConstructorPerformsNoIo(): void
    {
        $this->makeVfsCache();

        self::assertFalse($this->root->hasChild('cache'));
    }

    public function testGetOnMissingFileReturnsNullWithoutLogging(): void
    {
        $this->logger->expects(self::never())->method('log');

        self::assertNull($this->makeVfsCache()->get('episodes/ep1.mp3'));
    }

    // --- flush() writes atomically ----------------------------------------------------

    public function testSetThenFlushPersistsAcrossInstances(): void
    {
        $cache = $this->makeRealCache();
        $cache->set('episodes/ep1.mp3', $this->sampleEntry());
        $cache->flush();

        self::assertFileExists($this->realCachePath());

        $reloaded = $this->makeRealCache()->get('episodes/ep1.mp3');
        self::assertSame($this->sampleEntry(), $reloaded);
    }

    public function testFlushIsANoOpWhenNothingWasSet(): void
    {
        $this->makeRealCache()->flush();

        self::assertFileDoesNotExist($this->realCachePath());
    }

    public function testFlushCreatesCacheDirectoryWhenMissing(): void
    {
        $cache = $this->makeRealCache();
        $cache->set('a.mp3', $this->sampleEntry());
        $cache->flush();

        self::assertDirectoryExists(dirname($this->realCachePath()));
    }

    public function testFlushDoesNotLeaveATempFileBehind(): void
    {
        $cache = $this->makeRealCache();
        $cache->set('a.mp3', $this->sampleEntry());
        $cache->flush();

        $entries = array_diff(scandir(dirname($this->realCachePath())), ['.', '..']);
        self::assertSame(['state.json'], array_values($entries));
    }

    public function testFlushLogsWarningWhenCacheDirectoryCannotBeCreated(): void
    {
        // Read-only parent prevents mkdir() from creating cache/podcast under it.
        chmod($this->realTempDir, 0500);

        $cache = new MediaStateCache($this->realTempDir . '/cache/podcast/state.json', $this->logger);
        $cache->set('a.mp3', $this->sampleEntry());

        $this->logger->expects(self::once())
            ->method('log')
            ->with('WARNING', self::stringContains('cache directory'));

        // mkdir() itself emits a native E_WARNING on permission failure before
        // the production code's own return-value check logs and returns -
        // suppressed here because that PHP-level warning is the expected,
        // already-handled outcome being tested, not a defect.
        @$cache->flush();

        chmod($this->realTempDir, 0755);
    }

    // --- load() degrades corrupt/malformed cache files instead of throwing -----------

    public function testCorruptJsonDegradesToEmptyCacheWithWarning(): void
    {
        vfsStream::newFile('cache/podcast/state.json')
            ->at($this->root)
            ->setContent('{not valid json');

        $this->logger->expects(self::once())
            ->method('log')
            ->with('WARNING', self::stringContains('corrupt'));

        self::assertNull($this->makeVfsCache()->get('a.mp3'));
    }

    public function testJsonDecodingToANumericScalarDegradesToEmptyCacheWithWarning(): void
    {
        vfsStream::newFile('cache/podcast/state.json')
            ->at($this->root)
            ->setContent('42');

        $this->logger->expects(self::once())
            ->method('log')
            ->with('WARNING', self::anything());

        self::assertNull($this->makeVfsCache()->get('a.mp3'));
    }

    public function testJsonDecodingToAStringScalarDegradesToEmptyCacheWithWarning(): void
    {
        vfsStream::newFile('cache/podcast/state.json')
            ->at($this->root)
            ->setContent('"just a string"');

        $this->logger->expects(self::once())
            ->method('log')
            ->with('WARNING', self::anything());

        self::assertNull($this->makeVfsCache()->get('a.mp3'));
    }

    public function testUnknownSchemaVersionIsDiscarded(): void
    {
        vfsStream::newFile('cache/podcast/state.json')
            ->at($this->root)
            ->setContent((string) json_encode(['version' => 99, 'entries' => ['a.mp3' => $this->sampleEntry()]]));

        $this->logger->expects(self::once())->method('log')->with('WARNING', self::anything());

        self::assertNull($this->makeVfsCache()->get('a.mp3'));
    }

    public function testEntriesNotAnArrayIsDiscarded(): void
    {
        vfsStream::newFile('cache/podcast/state.json')
            ->at($this->root)
            ->setContent((string) json_encode(['version' => 1, 'entries' => 'oops']));

        $this->logger->expects(self::once())->method('log')->with('WARNING', self::anything());

        self::assertNull($this->makeVfsCache()->get('a.mp3'));
    }

    public function testNonArrayEntryValueIsFilteredOutSilently(): void
    {
        vfsStream::newFile('cache/podcast/state.json')
            ->at($this->root)
            ->setContent((string) json_encode(['version' => 1, 'entries' => ['a.mp3' => 'not-an-array']]));

        self::assertNull($this->makeVfsCache()->get('a.mp3'));
    }

    public function testIncompleteEntryMissingARequiredKeyReturnsNull(): void
    {
        $incomplete = $this->sampleEntry();
        unset($incomplete['duration_seconds']);

        vfsStream::newFile('cache/podcast/state.json')
            ->at($this->root)
            ->setContent((string) json_encode(['version' => 1, 'entries' => ['a.mp3' => $incomplete]]));

        self::assertNull($this->makeVfsCache()->get('a.mp3'));
    }

    public function testValidCacheFileRoundTripsAllFieldsWithCorrectTypes(): void
    {
        vfsStream::newFile('cache/podcast/state.json')
            ->at($this->root)
            ->setContent((string) json_encode(['version' => 1, 'entries' => ['a.mp3' => $this->sampleEntry()]]));

        $entry = $this->makeVfsCache()->get('a.mp3');

        self::assertSame($this->sampleEntry(), $entry);
    }

    public function testGetCastsStoredValuesToTheirDeclaredTypesEvenWhenJsonStoredThemAsStrings(): void
    {
        $stringified = [
            'source_mtime' => '1700000000',
            'source_size' => '4096',
            'metadata_hash' => 'abc123',
            'published_path' => 'episodes/ep1.mp3',
            'published_size' => '4200',
            'mime' => 'audio/mpeg',
            'duration_seconds' => '754.3',
        ];

        vfsStream::newFile('cache/podcast/state.json')
            ->at($this->root)
            ->setContent((string) json_encode(['version' => 1, 'entries' => ['a.mp3' => $stringified]]));

        $entry = $this->makeVfsCache()->get('a.mp3');

        self::assertSame($this->sampleEntry(), $entry);
    }
}
