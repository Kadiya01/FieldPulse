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
     * Audit rows attached to one entity (for the agent detail panel).
     *
     * @return list<array<string,mixed>>
     */
    public function forEntity(string $entityType, int $entityId, int $limit = 20): array
    {
        return $this->all(
            'SELECT * FROM audit_logs
              WHERE entity_type = :entity_type AND entity_id = :entity_id
              ORDER BY created_at DESC, id DESC' . self::limitClause($limit, 100),
            ['entity_type' => $entityType, 'entity_id' => $entityId]
        );
    }

    /**
     * Paged audit search with the actor joined for display.
     *
     * @param  array{action?:?string,actor_agent_id?:?int,entity_type?:?string,entity_id?:?int} $filters
     * @return list<array<string,mixed>>
     */
    public function adminSearch(array $filters, int $limit = 50, int $offset = 0): array
    {
        [$where, $params] = $this->adminWhere($filters);

        return $this->all(
            "SELECT l.*, a.agent_code AS actor_agent_code, a.full_name AS actor_full_name
               FROM audit_logs l
               LEFT JOIN agents a ON a.id = l.actor_agent_id"
            . $where
            . ' ORDER BY l.created_at DESC, l.id DESC'
            . self::limitClause($limit, 200, $offset),
            $params
        );
    }

    /**
     * @param array{action?:?string,actor_agent_id?:?int,entity_type?:?string,entity_id?:?int} $filters
     */
    public function countAdminSearch(array $filters): int
    {
        [$where, $params] = $this->adminWhere($filters);

        return (int) $this->value('SELECT COUNT(*) FROM audit_logs l' . $where, $params);
    }

    /**
     * @param  array{action?:?string,actor_agent_id?:?int,entity_type?:?string,entity_id?:?int} $filters
     * @return array{0:string,1:array<string,mixed>}
     */
    private function adminWhere(array $filters): array
    {
        $where  = [];
        $params = [];

        if (($filters['action'] ?? null) !== null && $filters['action'] !== '') {
            $where[] = 'l.action = :action';
            $params['action'] = $filters['action'];
        }

        if (($filters['actor_agent_id'] ?? null) !== null) {
            $where[] = 'l.actor_agent_id = :actor_agent_id';
            $params['actor_agent_id'] = (int) $filters['actor_agent_id'];
        }

        if (($filters['entity_type'] ?? null) !== null && $filters['entity_type'] !== '') {
            $where[] = 'l.entity_type = :entity_type';
            $params['entity_type'] = $filters['entity_type'];
        }

        if (($filters['entity_id'] ?? null) !== null) {
            $where[] = 'l.entity_id = :entity_id';
            $params['entity_id'] = (int) $filters['entity_id'];
        }

        return [$where === [] ? '' : ' WHERE ' . implode(' AND ', $where), $params];
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
