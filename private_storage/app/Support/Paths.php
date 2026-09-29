<?php

declare(strict_types=1);

namespace FieldPulse\Support;

use FieldPulse\Config\Config;

/**
 * Filesystem layout for private storage.
 *
 * Every path returned here is absolute and already validated to sit inside the
 * storage root. Two invariants are enforced by resolveExisting()/resolveForWrite():
 *
 *   1. No path may escape the storage root (defeats ../ traversal).
 *   2. The 0640/0750 permission model is applied on creation, and the
 *      parent chain is checked for world-writability, because a world-writable
 *      storage root on shared hosting lets another tenant swap evidence files.
 */
final class Paths
{
    public const DIR_QUARANTINE    = 'quarantine';
    public const DIR_VERIFIED      = 'processed/verified';
    public const DIR_REVIEW        = 'processed/review';
    public const DIR_REJECTED      = 'processed/rejected';
    public const DIR_LOGS          = 'logs';
    public const DIR_TMP           = 'tmp';

    /** @var array<string,string> */
    private static array $dirs = [];

    private function __construct()
    {
    }

    public static function storageRoot(): string
    {
        return rtrim((string) Config::instance()->str('storage.root'), '/\\');
    }

    public static function quarantineDir(): string
    {
        return self::storageRoot() . '/' . self::DIR_QUARANTINE;
    }

    public static function verifiedDir(): string
    {
        return self::storageRoot() . '/' . self::DIR_VERIFIED;
    }

    public static function reviewDir(): string
    {
        return self::storageRoot() . '/' . self::DIR_REVIEW;
    }

    public static function rejectedDir(): string
    {
        return self::storageRoot() . '/' . self::DIR_REJECTED;
    }

    public static function logsDir(): string
    {
        return self::storageRoot() . '/' . self::DIR_LOGS;
    }

    public static function tmpDir(): string
    {
        return self::storageRoot() . '/' . self::DIR_TMP;
    }

    /**
     * Create every required directory with hardened permissions. Idempotent.
     *
     * @throws \RuntimeException when a directory cannot be created or is not
     *                           owned by the PHP user.
     */
    public static function ensureLayout(): void
    {
        $root = self::storageRoot();

        if ($root === '') {
            throw new \RuntimeException('storage.root is not configured.');
        }

        $dirs = [
            $root,
            self::quarantineDir(),
            self::verifiedDir(),
            self::reviewDir(),
            self::rejectedDir(),
            self::logsDir(),
            self::tmpDir(),
        ];

        self::$dirs = array_fill_keys(array_map('strtolower', $dirs), '');

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                if (!@mkdir($dir, 0750, true) && !is_dir($dir)) {
                    throw new \RuntimeException('Unable to create storage directory: ' . self::maskPath($dir));
                }
                @chmod($dir, 0750);
            }

            if (!is_writable($dir)) {
                throw new \RuntimeException('Storage directory is not writable by the PHP user: ' . self::maskPath($dir));
            }

            $perms = @fileperms($dir);
            if (PHP_OS_FAMILY !== 'Windows' && $perms !== false && ($perms & 0o002) !== 0) {
                // World-writable: another account on the box could plant files.
                // Only meaningful where POSIX mode bits exist. Windows reports
                // 0777 from fileperms() for every directory and enforces access
                // through ACLs instead, so the test there is not just noise, it
                // is permanently true and would make local development and the
                // test suite impossible to run.
                throw new \RuntimeException(
                    'Storage directory is world-writable (chmod 0750 required): ' . self::maskPath($dir)
                );
            }
        }
    }

    /**
     * The directory a file must currently live in, given the path stored in
     * submissions.file_path (which is always relative to the storage root).
     */
    public static function absoluteForStoredPath(string $relativePath): string
    {
        $normalised = str_replace('\\', '/', trim($relativePath));

        // A stored path is always relative to the storage root. Stripping the
        // leading separator and carrying on turned '/etc/passwd' into
        // <root>/etc/passwd, which is contained but still wrong: it silently
        // reinterprets a malformed value instead of refusing it, and it did so
        // in a way that hid the fact this guard was never exercised. Reject
        // anything that names a filesystem root of its own, including a Windows
        // drive or a UNC share.
        if (str_starts_with($normalised, '/') || preg_match('#^[A-Za-z]:#', $normalised) === 1) {
            throw new \InvalidArgumentException('Illegal stored file path.');
        }

        if ($normalised === '' || str_contains($normalised, '../') || str_contains($normalised, "\0")) {
            throw new \InvalidArgumentException('Illegal stored file path.');
        }

        $absolute = self::storageRoot() . '/' . $normalised;

        if (!str_starts_with(self::canonicalDir($absolute), self::canonicalDir(self::storageRoot()))) {
            throw new \InvalidArgumentException('Stored file path escapes the storage root.');
        }

        return $absolute;
    }

    /**
     * Convert an absolute path inside the storage root into the relative form
     * persisted in submissions.file_path.
     */
    public static function relativeForAbsolutePath(string $absolute): string
    {
        $root   = self::canonicalDir(self::storageRoot());
        $target = self::canonicalDir($absolute);

        if (!str_starts_with($target, $root . '/')) {
            throw new \InvalidArgumentException('Path is outside the storage root.');
        }

        return substr($target, strlen($root) + 1);
    }

    /**
     * Absolute destination for a relative path under the storage root, with the
     * parent directory created at hardened permissions.
     *
     * This is the write-side counterpart to absoluteForStoredPath(). The two are
     * deliberately separate: a stored path is read from the database and must
     * already exist, while a destination is being created and may not exist yet.
     * Sharing one method would mean either creating directories during a read or
     * accepting unvalidated paths during a write.
     *
     * @throws \InvalidArgumentException when the path escapes the storage root
     * @throws \RuntimeException         when the parent directory cannot be created
     */
    public static function storagePath(string $relative, bool $createParents = true): string
    {
        $normalised = str_replace('\\', '/', trim($relative));
        $normalised = ltrim($normalised, '/');

        if ($normalised === '' || str_contains($normalised, "\0")) {
            throw new \InvalidArgumentException('Illegal storage path.');
        }

        foreach (explode('/', $normalised) as $segment) {
            if ($segment === '..') {
                throw new \InvalidArgumentException('Path traversal is not permitted in a storage path.');
            }
        }

        $absolute = self::storageRoot() . '/' . $normalised;
        $parent   = dirname($absolute);

        if (!str_starts_with(self::canonicalDir($parent), self::canonicalDir(self::storageRoot()))) {
            throw new \InvalidArgumentException('Storage path escapes the storage root.');
        }

        if ($createParents && !is_dir($parent) && !@mkdir($parent, 0750, true) && !is_dir($parent)) {
            throw new \RuntimeException('Unable to create storage directory: ' . self::maskPath($parent));
        }

        return $absolute;
    }

    /**
     * Realpath() of the deepest existing ancestor, used to validate a target
     * before the file itself exists.
     */
    public static function canonicalDir(string $path): string
    {
        $real = realpath($path);
        if ($real !== false) {
            return rtrim(str_replace('\\', '/', $real), '/');
        }

        $parent = dirname($path);
        if ($parent === $path) {
            return rtrim(str_replace('\\', '/', $path), '/');
        }

        return self::canonicalDir($parent) . '/' . basename($path);
    }

    /**
     * Random, non-guessable name for a quarantined upload.
     *
     * The extension is derived from the server-detected MIME type, never from
     * user input, so a crafted filename cannot influence what lands on disk.
     */
    public static function randomUploadName(string $detectedMime): string
    {
        $ext = match ($detectedMime) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            default      => 'bin',
        };

        return date('Ymd') . '-' . bin2hex(random_bytes(16)) . '.' . $ext;
    }

    private static function maskPath(string $path): string
    {
        return str_replace(FIELDPULSE_BASE_ROOT, '<root>', $path);
    }
}
