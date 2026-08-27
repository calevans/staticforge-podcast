<?php

declare(strict_types=1);

namespace Calevans\StaticForgePodcast\Tests\Unit\Services;

use Calevans\StaticForgePodcast\Services\SafePath;
use Calevans\StaticForgePodcast\Tests\TestCase;

/**
 * Deliberately uses real temp directories rather than vfsStream: PathGuard
 * short-circuits and returns unvalidated for any vfs:// path, so a containment
 * test written against a virtual filesystem proves nothing about the guard.
 * Symlink behaviour needs a real filesystem regardless.
 */
class SafePathTest extends TestCase
{
    private string $root;
    private string $outside;

    protected function setUp(): void
    {
        parent::setUp();

        $base = sys_get_temp_dir() . '/sf_safepath_' . uniqid();
        $this->root = $base . '/content';
        $this->outside = $base . '/secrets';

        mkdir($this->root . '/assets', 0755, true);
        mkdir($this->outside, 0755, true);

        file_put_contents($this->root . '/assets/ok.jpg', 'inside');
        file_put_contents($this->outside . '/id_rsa', 'PRIVATE KEY');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeDirectory(dirname($this->root));
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

    public function testResolvesAPathInsideTheRoot(): void
    {
        $resolved = SafePath::resolveExisting($this->root . '/assets/ok.jpg', $this->root);

        $this->assertSame(realpath($this->root . '/assets/ok.jpg'), $resolved);
    }

    public function testRejectsDotDotTraversal(): void
    {
        $this->assertNull(
            SafePath::resolveExisting($this->root . '/../secrets/id_rsa', $this->root)
        );
    }

    public function testRejectsAnAbsolutePathOutsideTheRoot(): void
    {
        $this->assertNull(SafePath::resolveExisting($this->outside . '/id_rsa', $this->root));
    }

    /**
     * The gap PathGuard alone leaves open: git stores symlinks, so a
     * contributor PR can commit one pointing anywhere the build user can read.
     * The path STRING is inside the root; only the resolved target is not.
     */
    public function testRejectsASymlinkWhoseTargetEscapesTheRoot(): void
    {
        $link = $this->root . '/assets/cover.jpg';
        if (!@symlink($this->outside . '/id_rsa', $link)) {
            $this->markTestSkipped('Filesystem does not support symlinks.');
        }

        $this->assertFileExists($link, 'sanity: the symlink itself resolves');
        $this->assertNull(SafePath::resolveExisting($link, $this->root));
    }

    public function testAcceptsASymlinkWhoseTargetStaysInsideTheRoot(): void
    {
        $link = $this->root . '/assets/alias.jpg';
        if (!@symlink($this->root . '/assets/ok.jpg', $link)) {
            $this->markTestSkipped('Filesystem does not support symlinks.');
        }

        $this->assertSame(realpath($this->root . '/assets/ok.jpg'), SafePath::resolveExisting($link, $this->root));
    }

    public function testReturnsNullForAPathThatDoesNotExist(): void
    {
        $this->assertNull(SafePath::resolveExisting($this->root . '/assets/missing.jpg', $this->root));
    }

    public function testRootItselfResolves(): void
    {
        $this->assertSame(realpath($this->root), SafePath::resolveExisting($this->root, $this->root));
    }

    /**
     * A sibling directory sharing the root's name prefix must not pass - this
     * is the bug the hand-rolled strpos() checks PathGuard replaced all had.
     */
    public function testRejectsASiblingDirectoryWithTheSameNamePrefix(): void
    {
        $sibling = $this->root . '-evil';
        mkdir($sibling, 0755, true);
        file_put_contents($sibling . '/loot.txt', 'x');

        $this->assertNull(SafePath::resolveExisting($sibling . '/loot.txt', $this->root));
    }
}
