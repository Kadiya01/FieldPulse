<?php

declare(strict_types=1);

namespace FieldPulse\Database;

use FieldPulse\Support\Json;

/**
 * audit_logs access.
 *
 * Append-only by design: there is no update or delete method on this class
 * outside of the retention pruner, and the pruner is the only place that
 * removes rows.
 */
final class AuditRepository extends Repository
{
    /**
     * @param array{actor_agent_id?:int|null,action:string,entity_type?:string|null,entity_id?:int|null,metadata?:array<string,mixed>|null,ip_address?:string|null} $entry
     */
    public function record(array $entry): void
    {
        $metadata = $entry['metadata'] ?? null;

        $this->exec(
            'INSERT INTO audit_logs (actor_agent_id, action, entity_type, entity_id, metadata_json, ip_address, created_at)
             VALUES (:actor_agent_id, :action, :entity_type, :entity_id, :metadata, :ip, UTC_TIMESTAMP())',
            [
                'actor_agent_id' => $entry['actor_agent_id'] ?? null,
                'action'         => $entry['action'],
                'entity_type'    => $entry['entity_type'] ?? null,
                'entity_id'      => $entry['entity_id'] ?? null,
                'metadata'       => $metadata === null ? null : Json::encode($metadata),
                'ip'             => $entry['ip_address'] ?? null,
            ]
        );
    }

    /**
     * Audit writes must never break the operation being audited, so failures
     * are swallowed after being logged. Called from request paths.
     *
     * @param array{actor_agent_id?:int|null,action:string,entity_type?:string|null,entity_id?:int|null,metadata?:array<string,mixed>|null,ip_address?:string|null} $entry
     */
    public function recordSafe(array $entry): void
    {
        try {
            $this->record($entry);
        } catch (\Throwable $e) {
            \FieldPulse\Support\Logger::warning('audit.write_failed', [
                'action' => $entry['action'] ?? 'unknown',
                'error'  => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function recentForAgent(int $agentId, int $limit = 50): array
    {
        return $this->all(
            'SELECT * FROM audit_logs WHERE actor_agent_id = :a ORDER BY created_at DESC, id DESC' . self::limitClause($limit),
            ['a' => $agentId]
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function search(?int $actorAgentId, ?string $action, int $limit = 100): array
    {
        $where  = [];
        $params = [];

        if ($actorAgentId !== null) {
            $where[] = 'actor_agent_id = :actor';
            $params['actor'] = $actorAgentId;
        }

        if ($action !== null && $action !== '') {
            $where[] = 'action = :action';
            $params['action'] = $action;
        }

        $sql = 'SELECT * FROM audit_logs'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY created_at DESC, id DESC' . self::limitClause($limit, 500);

        return $this->all($sql, $params);
    }

    /**
     * Retention pruning. Audit history is kept for a long window by default
     * because it is the only independent record of a disposition decision.
     */
    public function pruneOlderThan(int $days, int $limit = 5000): int
    {
        $cutoff = \FieldPulse\Support\Clock::sql(\FieldPulse\Support\Clock::shift(-$days * 86400));

        return $this->exec(
            'DELETE FROM audit_logs WHERE created_at < :cutoff ORDER BY created_at ASC' . self::limitOnly($limit, 10000),
            ['cutoff' => $cutoff]
        );
    }
}
