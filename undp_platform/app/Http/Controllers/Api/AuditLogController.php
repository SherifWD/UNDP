<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['nullable', 'string', 'max:255'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'role' => ['nullable', 'string', 'max:255'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'status' => ['nullable', 'string', 'max:100'],
            'municipality_id' => ['nullable', 'integer'],
            'project_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        $query = AuditLog::query()->with('actor:id,name,role');

        if (! empty($validated['action'])) {
            $query->where('action', 'like', '%'.$validated['action'].'%');
        }

        if (! empty($validated['user_id'])) {
            $query->where('actor_id', $validated['user_id']);
        }

        if (! empty($validated['role'])) {
            $query->whereHas('actor', fn ($builder) => $builder->where('role', $validated['role']));
        }

        if (! empty($validated['date_from'])) {
            $query->whereDate('created_at', '>=', $validated['date_from']);
        }

        if (! empty($validated['date_to'])) {
            $query->whereDate('created_at', '<=', $validated['date_to']);
        }

        if (! empty($validated['status'])) {
            $query->where('metadata->status', $validated['status']);
        }

        if (! empty($validated['municipality_id'])) {
            $query->where(function ($builder) use ($validated): void {
                $builder
                    ->where('metadata->municipality_id', (int) $validated['municipality_id'])
                    ->orWhereHas('actor', fn ($actorQuery) => $actorQuery->where('municipality_id', $validated['municipality_id']));
            });
        }

        if (! empty($validated['project_id'])) {
            $query->where('metadata->project_id', (int) $validated['project_id']);
        }

        $logs = $query
            ->latest('created_at')
            ->paginate($validated['per_page'] ?? 25)
            ->through(fn (AuditLog $log): array => $this->serializeLog($log));

        return response()->json($logs);
    }

    public function show(AuditLog $auditLog): JsonResponse
    {
        $auditLog->load('actor:id,name,role');

        return response()->json([
            'data' => $this->serializeLog($auditLog),
        ]);
    }

    private function serializeLog(AuditLog $log): array
    {
        return [
            'id' => $log->id,
            'timestamp' => optional($log->created_at)->toIso8601String(),
            'action' => $log->action,
            'entity_type' => $log->entity_type,
            'entity_id' => $log->entity_id,
            'actor' => $log->actor ? [
                'id' => $log->actor->id,
                'name' => $log->actor->name,
                'role' => $log->actor->role,
            ] : null,
            'before' => $log->before,
            'after' => $log->after,
            'metadata' => $log->metadata,
            'ip_address' => $log->ip_address,
            'user_agent' => $log->user_agent,
        ];
    }
}
