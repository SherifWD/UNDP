<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

class AuditLogger
{
    public static function log(
        string $action,
        ?string $entityType = null,
        string|int|null $entityId = null,
        ?array $before = null,
        ?array $after = null,
        array $metadata = [],
        ?Request $request = null,
        ?User $actor = null,
    ): AuditLog {
        $request ??= request();
        $requestContext = [];

        if ($request) {
            $requestContext = array_filter([
                'request_path' => $request->path(),
                'request_method' => $request->method(),
                'route_name' => $request->route()?->getName(),
            ], static fn (mixed $value): bool => $value !== null && $value !== '');
        }

        return AuditLog::create([
            'actor_id' => $actor?->id ?? $request?->user()?->id,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId === null ? null : (string) $entityId,
            'before' => $before,
            'after' => $after,
            'metadata' => array_merge($requestContext, $metadata),
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }

    public static function blocked(Request $request, string $permission, ?User $actor = null): void
    {
        self::log(
            action: 'auth.blocked_permission',
            entityType: 'permission',
            entityId: $permission,
            metadata: [
                'permission' => $permission,
            ],
            request: $request,
            actor: $actor,
        );
    }
}
