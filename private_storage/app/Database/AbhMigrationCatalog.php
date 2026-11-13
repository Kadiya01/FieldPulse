<?php

declare(strict_types=1);

namespace FieldPulse\Database;

final class AbhMigrationCatalog
{
    public const EXPECTED_DATABASE = 'abhgloba_abhpreview';
    public const EXPECTED_DATABASE_USER = 'abhgloba_abhpreview';
    public const EXPECTED_APP_URL = 'https://monthlyperformancepreview.abhglobalinformationtechnologysolutions.com.ng';
    public const EXPECTED_PRIVATE_ROOT = '/home/abhgloba/abhpreview_private_storage';

    private readonly string $directory;

    public function __construct(string $directory)
    {
        $realDirectory = realpath($directory);
        if ($realDirectory === false || !is_dir($realDirectory) || is_link($directory)) {
            throw new \RuntimeException('ABH migration directory is missing, unreadable or linked.');
        }
        $this->directory = $realDirectory;
    }

    public static function assertPreviewConfiguration(
        string $privateRoot,
        string $appUrl,
        string $configuredDatabase,
        string $configuredDatabaseUser,
        ?string $loadedEnvironmentFile,
        string $expectedPrivateRoot = self::EXPECTED_PRIVATE_ROOT
    ): void {
        $privateRootReal = realpath($privateRoot);
        $expectedRootReal = realpath($expectedPrivateRoot);

        if (
            $privateRootReal === false
            || $expectedRootReal === false
            || $privateRootReal !== $expectedRootReal
            || $privateRootReal !== rtrim($expectedPrivateRoot, '/\\')
            || is_link($privateRoot)
        ) {
            throw new \RuntimeException('ABH migration runner is allowed only from the dedicated preview private directory.');
        }
        if (rtrim($appUrl, '/') !== self::EXPECTED_APP_URL) {
            throw new \RuntimeException('ABH migration runner requires the exact preview APP_URL.');
        }

        self::assertConfiguredDatabase($configuredDatabase);
        if ($configuredDatabaseUser !== self::EXPECTED_DATABASE_USER) {
            throw new \RuntimeException('ABH migration runner requires the dedicated preview database user.');
        }

        $expectedEnv = realpath($privateRootReal . DIRECTORY_SEPARATOR . '.env');
        $loadedEnv = $loadedEnvironmentFile !== null ? realpath($loadedEnvironmentFile) : false;
        if ($expectedEnv === false || $loadedEnv === false || $loadedEnv !== $expectedEnv) {
            throw new \RuntimeException('ABH migration runner must load the .env file inside the dedicated preview private directory.');
        }
    }

    public static function assertConfiguredDatabase(string $databaseName): void
    {
        if ($databaseName !== self::EXPECTED_DATABASE) {
            throw new \RuntimeException('Refusing ABH migrations: configured database must be exactly ' . self::EXPECTED_DATABASE . '.');
        }
    }

    public static function assertConnectedDatabase(string $databaseName): void
    {
        if ($databaseName !== self::EXPECTED_DATABASE) {
            throw new \RuntimeException('Refusing ABH migrations: the connected database is not ' . self::EXPECTED_DATABASE . '.');
        }
    }

    public static function assertConnectedUser(string $databaseUser): void
    {
        if (!str_starts_with($databaseUser, self::EXPECTED_DATABASE_USER . '@')) {
            throw new \RuntimeException('Refusing ABH migrations: the authenticated database account is not the dedicated preview user.');
        }
    }

    /** @return list<array{filename:string,path:string,checksum:string,statement_count:int}> */
    public function discover(): array
    {
        $paths = glob($this->directory . DIRECTORY_SEPARATOR . '*.sql') ?: [];
        sort($paths, SORT_STRING);
        $out = [];

        foreach ($paths as $path) {
            $filename = basename($path);
            if (!preg_match('/^[0-9]{3}_[a-z0-9]+(?:_[a-z0-9]+)*\.sql$/D', $filename)) {
                throw new \RuntimeException('Invalid ABH migration filename: ' . $filename);
            }
            if (is_link($path) || !is_file($path)) {
                throw new \RuntimeException('ABH migration files must be regular files: ' . $filename);
            }

            $realPath = realpath($path);
            if ($realPath === false || dirname($realPath) !== $this->directory) {
                throw new \RuntimeException('ABH migration file resolves outside its migration directory: ' . $filename);
            }

            $contents = file_get_contents($realPath);
            if ($contents === false || trim($contents) === '') {
                throw new \RuntimeException('ABH migration file is empty or unreadable: ' . $filename);
            }
            $statements = Migrator::splitStatements($contents);
            if ($statements === []) {
                throw new \RuntimeException('ABH migration contains no executable statements: ' . $filename);
            }

            $out[] = [
                'filename' => $filename,
                'path' => $realPath,
                'checksum' => hash('sha256', $contents),
                'statement_count' => count($statements),
            ];
        }
        return $out;
    }

    /**
     * @param list<array{filename:string,checksum:string,applied_at:string}> $appliedRows
     * @return list<array{filename:string,status:string,checksum_mismatch:bool,statement_count:int}>
     */
    public function status(array $appliedRows): array
    {
        $applied = [];
        foreach ($appliedRows as $row) {
            $filename = (string) ($row['filename'] ?? '');
            if ($filename === '' || isset($applied[$filename])) {
                throw new \RuntimeException('ABH migration ledger contains an invalid or duplicate filename.');
            }
            $applied[$filename] = (string) ($row['checksum'] ?? '');
        }

        $knownFiles = [];
        $out = [];
        foreach ($this->discover() as $file) {
            $filename = $file['filename'];
            $knownFiles[$filename] = true;
            $knownChecksum = $applied[$filename] ?? null;
            $out[] = [
                'filename' => $filename,
                'status' => $knownChecksum === null ? 'PENDING' : 'APPLIED',
                'checksum_mismatch' => $knownChecksum !== null && !hash_equals($knownChecksum, $file['checksum']),
                'statement_count' => $file['statement_count'],
            ];
        }

        foreach ($applied as $filename => $_checksum) {
            if (!isset($knownFiles[$filename])) {
                $out[] = [
                    'filename' => $filename,
                    'status' => 'MISSING_FILE',
                    'checksum_mismatch' => true,
                    'statement_count' => 0,
                ];
            }
        }

        usort($out, static fn (array $a, array $b): int => strcmp($a['filename'], $b['filename']));
        return $out;
    }
}
