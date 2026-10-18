<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Auth;
use Waimart\Core\Logger;

/**
 * Append-only audit trail for administrative actions, payment decisions
 * and other sensitive changes (A6, E).
 */
final class AuditLogger
{
    /**
     * @param array<string,mixed>|null $before
     * @param array<string,mixed>|null $after
     */
    public static function log(
        string $action,
        string $entityType,
        int|string|null $entityId,
        ?array $before = null,
        ?array $after = null,
        ?int $actorId = null,
    ): void {
        $actorId ??= Auth::id();
        App::db()->insert('audit_logs', [
            'actor_user_id' => $actorId,
            'action'        => $action,
            'entity_type'   => $entityType,
            'entity_id'     => $entityId === null ? null : (string) $entityId,
            'before_json'   => $before === null ? null : json_encode(Logger::redact($before), JSON_UNESCAPED_UNICODE),
            'after_json'    => $after === null ? null : json_encode(Logger::redact($after), JSON_UNESCAPED_UNICODE),
            'ip_address'    => PHP_SAPI === 'cli' ? 'cli' : ($_SERVER['REMOTE_ADDR'] ?? null),
            'user_agent'    => PHP_SAPI === 'cli' ? 'cli' : mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
    }
}
