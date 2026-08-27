<?php

declare(strict_types=1);

namespace Calevans\StaticForgePodcast\Services;

use EICC\StaticForge\Core\PathGuard;
use RuntimeException;

/**
 * Containment check for paths that must already exist on disk.
 *
 * Core's PathGuard is deliberately pure string normalization so it can jail
 * write targets that do not exist yet. That leaves one gap for paths we are
 * about to READ: git stores symlinks, so a contributor PR can commit
 * `content/assets/cover.jpg -> /home/you/.ssh/id_rsa`. The string is inside
 * SOURCE_DIR, PathGuard passes it, and file_get_contents happily follows the
 * link back out. Re-assert containment on the resolved target.
 */
final class SafePath
{
    /**
     * Returns the real path of $path if it exists and genuinely lives inside
     * $root once symlinks are followed, or null if it does not.
     */
    public static function resolveExisting(string $path, string $root): ?string
    {
        try {
            $normalized = PathGuard::resolveInside($path, $root);
        } catch (RuntimeException) {
            return null;
        }

        // PathGuard passes stream-wrapper paths through unchecked and realpath()
        // does not understand them; match that established convention.
        if (str_starts_with($normalized, 'vfs://')) {
            return $normalized;
        }

        $realPath = realpath($normalized);
        $realRoot = realpath($root);

        if ($realPath === false || $realRoot === false) {
            return null;
        }

        $inside = $realPath === $realRoot
            || str_starts_with($realPath, $realRoot . DIRECTORY_SEPARATOR);

        return $inside ? $realPath : null;
    }
}
