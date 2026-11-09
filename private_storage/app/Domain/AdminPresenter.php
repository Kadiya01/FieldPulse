<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Support\Json;

/**
 * Small response-shaping helpers shared by the admin controllers.
 *
 * Keeping these in one place matters because three endpoints return audit
 * rows and a target row in two more. If the shape drifts between them the UI
 * has to special-case which screen it is on, which is exactly the kind of
 * inconsistency the admin surface exists to avoid.
 */
final class AdminPresenter
{
    private function __construct()
    {
    }

    /**
     * @param  array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function audit(array $row): array
    {
        return [
            'id'               => (int) $row['id'],
            'actor_agent_id'   => $row['actor_agent_id'] === null ? null : (int) $row['actor_agent_id'],
            'actor_agent_code' => $row['actor_agent_code'] ?? null,
            'actor_full_name'  => $row['actor_full_name'] ?? null,
            'action'           => (string) $row['action'],
            'entity_type'      => $row['entity_type'] ?? null,
            'entity_id'        => $row['entity_id'] === null ? null : (int) $row['entity_id'],
            'metadata'         => self::metadata($row['metadata_json'] ?? null),
            'ip_address'       => $row['ip_address'] ?? null,
            'created_at'       => (string) $row['created_at'],
        ];
    }

    /**
     * @param  list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public static function auditRows(array $rows): array
    {
        return array_map([self::class, 'audit'], $rows);
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function metadata(mixed $raw): ?array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            return Json::decodeArray($raw, 'audit metadata');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function target(array $row): array
    {
        $scopeType = (string) $row['scope_type'];
        $scopeKey  = (string) $row['scope_key'];
        $label     = $scopeType === 'ORGANIZATION'
            ? 'Organization'
            : ($scopeType === 'AGENT'
                ? trim(((string) ($row['agent_code'] ?? '')) . ' ' . ((string) ($row['agent_full_name'] ?? '')))
                : $scopeKey);

        return [
            'id'                => (int) $row['id'],
            'period_start_date' => (string) $row['period_start_date'],
            'scope_type'        => $scopeType,
            'scope_key'         => $scopeKey,
            'scope_label'       => $label === '' ? $scopeKey : $label,
            'target'            => (int) $row['target'],
            'configured'        => (int) $row['target'] > 0,
            'created_by'        => $row['created_by'] === null ? null : (int) $row['created_by'],
            'created_at'        => (string) $row['created_at'],
            'updated_at'        => (string) $row['updated_at'],
        ];
    }

    /**
     * Achievement is null, not 0, when no target exists. A zero target is
     * "not configured" throughout this application.
     *
     * @return array{target:int,configured:bool,achievement_percent:?float,remaining:?int}
     */
    public static function achievement(int $target, int $approved): array
    {
        return [
            'target'              => $target,
            'configured'          => $target > 0,
            'achievement_percent' => $target > 0 ? round(($approved / $target) * 100, 2) : null,
            'remaining'           => $target > 0 ? max(0, $target - $approved) : null,
        ];
    }
}
