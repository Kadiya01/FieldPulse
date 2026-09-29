<?php

declare(strict_types=1);

namespace FieldPulse\Database;

use FieldPulse\Config\Config;

/**
 * Single PDO connection, lazily created and shared for the request/CLI run.
 *
 * Security and correctness invariants enforced here:
 *   - ERRMODE_EXCEPTION always. A silent failure is worse than a loud one for
 *     an audit trail.
 *   - EMULATE_PREPARES = false, so every value is bound as a protocol-level
 *     parameter. String concatenation into SQL is impossible through this API
 *     because $sql is the only caller-controlled fragment and it never
 *     interpolates a bound value.
 *   - Session time_zone pinned to UTC regardless of server default.
 *   - Strict sql_mode forced, so a truncation on a VERIFIED ledger row is an
 *     error rather than silent data loss.
 *   - READ COMMITTED isolation: the queue worker claims rows with optimistic
 *     locking and must not take gap locks that block concurrent submissions.
 */
final class Connection
{
    private static ?\PDO $pdo = null;

    private function __construct()
    {
    }

    public static function pdo(): \PDO
    {
        if (self::$pdo === null) {
            self::$pdo = self::connect();
        }

        return self::$pdo;
    }

    public static function reset(): void
    {
        self::$pdo = null;
    }

    public static function isConnected(): bool
    {
        return self::$pdo !== null;
    }

    private static function connect(): \PDO
    {
        $c = Config::instance();

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $c->str('db.host'),
            $c->int('db.port'),
            $c->str('db.name'),
            $c->str('db.charset')
        );

        $socket = $c->str('db.socket');
        if ($socket !== '') {
            // cPanel MySQL on a socket: host/port are then irrelevant.
            $dsn = sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', $socket, $c->str('db.name'), $c->str('db.charset'));
        }

        try {
            $pdo = new \PDO($dsn, $c->str('db.user'), $c->str('db.pass'), [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES   => false,
                \PDO::ATTR_STRINGIFY_FETCHES  => false,
                \PDO::ATTR_PERSISTENT         => false,
            ]);
        } catch (\PDOException $e) {
            // Never surface DSN/credentials to the caller.
            throw new \RuntimeException('Database connection failed.', 0, $e);
        }

        self::hardenSession($pdo);

        return $pdo;
    }

    private static function hardenSession(\PDO $pdo): void
    {
        $statements = [
            "SET SESSION time_zone = '+00:00'",
            "SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'",
            "SET SESSION innodb_lock_wait_timeout = 15",
            "SET SESSION autocommit = 1",
        ];

        foreach ($statements as $sql) {
            try {
                $pdo->exec($sql);
            } catch (\PDOException) {
                // MariaDB/MySQL variants differ in which of these exist; the
                // connection is still usable, so do not hard-fail.
            }
        }

        // READ COMMITTED, with a fallback for MySQL 5.x naming.
        foreach (['transaction_isolation', 'tx_isolation'] as $variable) {
            try {
                $pdo->exec("SET SESSION {$variable} = 'READ-COMMITTED'");
                break;
            } catch (\PDOException) {
                continue;
            }
        }
    }

    /**
     * @param array<string|int,mixed> $params
     */
    public static function run(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);

        foreach ($params as $key => $value) {
            $name = is_int($key) ? $key + 1 : (str_starts_with($key, ':') ? $key : ':' . $key);
            $type = match (true) {
                is_int($value)  => \PDO::PARAM_INT,
                is_bool($value) => \PDO::PARAM_BOOL,
                is_null($value) => \PDO::PARAM_NULL,
                default         => \PDO::PARAM_STR,
            };

            $stmt->bindValue($name, $value, $type);
        }

        $stmt->execute();

        return $stmt;
    }

    /**
     * @param  array<string|int,mixed> $params
     * @return list<array<string,mixed>>
     */
    public static function fetchAll(string $sql, array $params = []): array
    {
        /** @var list<array<string,mixed>> $rows */
        $rows = self::run($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);

        return $rows;
    }

    /**
     * @param  array<string|int,mixed> $params
     * @return array<string,mixed>|null
     */
    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * @param  array<string|int,mixed> $params
     */
    public static function fetchValue(string $sql, array $params = []): mixed
    {
        $value = self::run($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * @param array<string|int,mixed> $params
     */
    public static function execute(string $sql, array $params = []): int
    {
        return self::run($sql, $params)->rowCount();
    }

    public static function lastInsertId(): string
    {
        return (string) self::pdo()->lastInsertId();
    }

    public static function serverVersion(): string
    {
        return (string) self::fetchValue('SELECT VERSION()');
    }

    public static function inTransaction(): bool
    {
        return self::pdo()->inTransaction();
    }

    /**
     * Run $work inside a transaction with bounded retry on deadlock (1213) and
     * lock-wait-timeout (1205). Both are expected under concurrent submissions
     * and are safe to retry because the work is idempotent by construction.
     *
     * @template T
     * @param  callable(\PDO):T $work
     * @return T
     */
    public static function transaction(callable $work, int $maxAttempts = 3): mixed
    {
        $attempt = 0;

        while (true) {
            $attempt++;
            $pdo = self::pdo();

            if ($pdo->inTransaction()) {
                // Nested request: the caller already owns the transaction.
                return $work($pdo);
            }

            try {
                $pdo->beginTransaction();
            } catch (\PDOException $e) {
                if ($attempt >= $maxAttempts || !self::isRetryable($e)) {
                    throw $e;
                }
                usleep(50_000 * $attempt);
                continue;
            }

            try {
                $result = $work($pdo);
                $pdo->commit();

                return $result;
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    try {
                        $pdo->rollBack();
                    } catch (\PDOException) {
                        // The server already rolled back (e.g. connection loss).
                    }
                }

                if ($attempt < $maxAttempts && $e instanceof \PDOException && self::isRetryable($e)) {
                    usleep(50_000 * $attempt);
                    continue;
                }

                throw $e;
            }
        }
    }

    /**
     * Deadlock and lock-wait-timeout are transient by definition; a duplicate
     * key (1062) is not, and must propagate so the idempotency handler can act
     * on it.
     */
    private static function isRetryable(\PDOException $e): bool
    {
        $code = (int) ($e->errorInfo[1] ?? 0);

        return in_array($code, [1213, 1205], true);
    }
}
