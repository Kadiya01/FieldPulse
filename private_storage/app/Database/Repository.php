<?php

declare(strict_types=1);

namespace FieldPulse\Database;

/**
 * Thin base for repositories.
 *
 * Repositories own SQL and nothing else: no hashing, no policy, no HTTP. That
 * split is what lets the verification engine be unit-testable against a
 * database, and lets the policy layer be reasoned about without reading a
 * single query string.
 */
abstract class Repository
{
    /** @param array<string|int,mixed> $params */
    protected function all(string $sql, array $params = []): array
    {
        return Connection::fetchAll($sql, $params);
    }

    /** @param array<string|int,mixed> $params @return array<string,mixed>|null */
    protected function one(string $sql, array $params = []): ?array
    {
        return Connection::fetchOne($sql, $params);
    }

    /** @param array<string|int,mixed> $params */
    protected function value(string $sql, array $params = []): mixed
    {
        return Connection::fetchValue($sql, $params);
    }

    /** @param array<string|int,mixed> $params */
    protected function exec(string $sql, array $params = []): int
    {
        return Connection::execute($sql, $params);
    }

    protected function now(): string
    {
        return \FieldPulse\Support\Clock::sql();
    }

    protected function isDuplicateKey(\PDOException $e): bool
    {
        return (int) ($e->errorInfo[1] ?? 0) === 1062
            || (string) ($e->errorInfo[0] ?? '') === '23000';
    }

    /**
     * A clamped LIMIT/OFFSET fragment.
     *
     * This is the single sanctioned place where SQL is built by concatenation,
     * and it is safe because both components are coerced to int and bounded
     * before formatting: no caller-supplied string can reach the statement.
     * MySQL will not accept a bound parameter in LIMIT, which is why this exists
     * at all rather than being a binding.
     *
     * SELECT only. UPDATE and DELETE take no OFFSET, see limitOnly().
     */
    public static function limitClause(int $limit, int $max = 200, int $offset = 0): string
    {
        return ' LIMIT ' . max(1, min($limit, $max)) . ' OFFSET ' . max(0, $offset);
    }

    /**
     * A clamped LIMIT fragment for UPDATE and DELETE.
     *
     * MySQL and MariaDB both reject an OFFSET on UPDATE and DELETE statements
     * ("LIMIT n OFFSET m" is a syntax error there), so a row-limited write
     * cannot use limitClause(). JobRepository::claimBatch(),
     * JobRepository::purgeCompleted() and AuditRepository::pruneOlderThan()
     * all pass through here; they were emitting `LIMIT 10000 OFFSET 0` and
     * failing with ER_PARSE_ERROR.
     */
    public static function limitOnly(int $limit, int $max = 200): string
    {
        return ' LIMIT ' . max(1, min($limit, $max));
    }
}
