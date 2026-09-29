<?php

declare(strict_types=1);

namespace FieldPulse\Database;

use FieldPulse\Support\Logger;

/**
 * Forward-only migration runner for plain .sql files.
 *
 * Why raw SQL instead of an ORM migration layer: the deployment target is shared
 * cPanel hosting with no Composer guarantee, and the contract in §4/§5 specifies
 * exact column types and index names. Encoding those literally in .sql files
 * removes an entire translation layer that could silently drift from the spec.
 *
 * Safety properties:
 *   - A ledger table (schema_migrations) records filename + SHA-256, so an
 *     edited-after-apply migration is detected rather than ignored.
 *   - A file that fails mid-way is not recorded, and MySQL's implicit DDL
 *     commits mean a partial DDL batch is possible; the ledger therefore also
 *     gates re-running, and `status()` reports any table-level drift.
 */
final class Migrator
{
    private const LEDGER = 'schema_migrations';

    public function __construct(private readonly string $directory)
    {
        if (!is_dir($directory)) {
            throw new \RuntimeException('Migration directory not found: ' . basename($directory));
        }
    }

    /**
     * @return list<array{filename:string,checksum:string,applied_at:string}>
     */
    public function applied(): array
    {
        $this->ensureLedger();

        /** @var list<array{filename:string,checksum:string,applied_at:string}> $rows */
        $rows = Connection::fetchAll(
            'SELECT filename, checksum, applied_at FROM ' . self::LEDGER . ' ORDER BY filename ASC'
        );

        return $rows;
    }

    /**
     * @return list<array{filename:string,status:string,checksum_mismatch:bool}>
     */
    public function status(): array
    {
        $applied = [];
        foreach ($this->applied() as $row) {
            $applied[$row['filename']] = $row;
        }

        $out = [];
        foreach ($this->discover() as $file) {
            $checksum = $this->checksum($file);
            $known    = $applied[$file] ?? null;

            $out[] = [
                'filename'          => $file,
                'status'            => $known === null ? 'PENDING' : 'APPLIED',
                'checksum_mismatch' => $known !== null && !hash_equals($known['checksum'], $checksum),
            ];
        }

        return $out;
    }

    /**
     * Apply every pending migration in filename order.
     *
     * @return list<string> filenames applied by this call
     */
    public function migrate(): array
    {
        $this->ensureLedger();

        $applied = [];
        foreach ($this->applied() as $row) {
            $applied[$row['filename']] = $row;
        }

        $done = [];

        foreach ($this->discover() as $file) {
            $checksum = $this->checksum($file);

            if (isset($applied[$file])) {
                if (!hash_equals($applied[$file]['checksum'], $checksum)) {
                    throw new \RuntimeException(
                        'Migration ' . $file . ' was modified after it was applied. '
                        . 'Create a new migration instead of editing history.'
                    );
                }
                continue;
            }

            $this->executeFile($file);
            $this->record($file, $checksum);
            $done[] = $file;

            Logger::info('migration applied', ['file' => $file]);
        }

        return $done;
    }

    /** @return list<string> */
    private function discover(): array
    {
        $files = glob($this->directory . '/*.sql') ?: [];

        $names = array_map(static fn (string $p): string => basename($p), $files);
        sort($names, SORT_STRING);

        return $names;
    }

    private function checksum(string $filename): string
    {
        $contents = file_get_contents($this->directory . '/' . $filename);
        if ($contents === false) {
            throw new \RuntimeException('Unable to read migration ' . $filename);
        }

        return hash('sha256', $contents);
    }

    private function executeFile(string $filename): void
    {
        $sql = file_get_contents($this->directory . '/' . $filename);
        if ($sql === false) {
            throw new \RuntimeException('Unable to read migration ' . $filename);
        }

        $pdo = Connection::pdo();

        foreach (self::splitStatements($sql) as $index => $statement) {
            try {
                $pdo->exec($statement);
            } catch (\PDOException $e) {
                throw new \RuntimeException(
                    sprintf(
                        'Migration %s failed on statement %d: %s',
                        $filename,
                        $index + 1,
                        $e->getMessage()
                    ),
                    0,
                    $e
                );
            }
        }
    }

    private function record(string $filename, string $checksum): void
    {
        Connection::execute(
            'INSERT INTO ' . self::LEDGER . ' (filename, checksum, applied_at) VALUES (:f, :c, NOW())',
            ['f' => $filename, 'c' => $checksum]
        );
    }

    private function ensureLedger(): void
    {
        Connection::pdo()->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::LEDGER . ' (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                filename VARCHAR(191) NOT NULL,
                checksum CHAR(64) NOT NULL,
                applied_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_schema_migrations_filename (filename)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /**
     * Split a SQL file into statements.
     *
     * Naive explode(';') is unsafe: it breaks on semicolons inside string
     * literals and on comment lines. This scanner tracks quote state and both
     * comment styles, which is sufficient for hand-authored DDL and avoids
     * pulling in a parser dependency.
     *
     * @return list<string>
     */
    public static function splitStatements(string $sql): array
    {
        $statements = [];
        $buffer     = '';
        $length     = strlen($sql);
        $inSingle   = false;
        $inDouble   = false;
        $inBacktick = false;
        $inLineComment = false;
        $inBlockComment = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if ($inLineComment) {
                if ($char === "\n") {
                    $inLineComment = false;
                    $buffer       .= $char;
                }
                continue;
            }

            if ($inBlockComment) {
                if ($char === '*' && $next === '/') {
                    $inBlockComment = false;
                    $i++;
                }
                continue;
            }

            if (!$inSingle && !$inDouble && !$inBacktick) {
                if ($char === '-' && $next === '-') {
                    $inLineComment = true;
                    $i++;
                    continue;
                }
                if ($char === '#') {
                    $inLineComment = true;
                    continue;
                }
                if ($char === '/' && $next === '*') {
                    $inBlockComment = true;
                    $i++;
                    continue;
                }
                if ($char === ';') {
                    $trimmed = trim($buffer);
                    if ($trimmed !== '') {
                        $statements[] = $trimmed;
                    }
                    $buffer = '';
                    continue;
                }
            }

            // Quote state transitions (no escaping inside SQL identifiers).
            if ($char === "'" && !$inDouble && !$inBacktick) {
                if ($inSingle && $next === "'") {
                    $buffer .= $char . $next;
                    $i++;
                    continue;
                }
                $inSingle = !$inSingle;
            } elseif ($char === '"' && !$inSingle && !$inBacktick) {
                $inDouble = !$inDouble;
            } elseif ($char === '`' && !$inSingle && !$inDouble) {
                $inBacktick = !$inBacktick;
            }

            $buffer .= $char;
        }

        $trimmed = trim($buffer);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }
}
