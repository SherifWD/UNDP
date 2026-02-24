<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'municipality_id' => ['nullable', 'integer', 'exists:municipalities,id'],
            'status' => ['nullable', 'string', 'max:50'],
        ]);

        $query = Project::query()->with('municipality:id,name_en,name_ar');

        if ($request->user()->municipality_id && $request->user()->hasRole(UserRole::MUNICIPAL_FOCAL_POINT)) {
            $query->where('municipality_id', $request->user()->municipality_id);
        }

        if (! empty($validated['municipality_id']) && $request->user()->hasPermission('dashboards.view.system')) {
            $query->where('municipality_id', $validated['municipality_id']);
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $projects = $query
            ->orderByDesc('last_update_at')
            ->orderBy('name_en')
            ->get()
            ->map(fn (Project $project): array => [
                'id' => $project->id,
                'name_en' => $project->name_en,
                'name_ar' => $project->name_ar,
                'name' => $project->name,
                'description' => $project->description,
                'status' => $project->status,
                'latitude' => $project->latitude,
                'longitude' => $project->longitude,
                'municipality' => $project->municipality ? [
                    'id' => $project->municipality->id,
                    'name_en' => $project->municipality->name_en,
                    'name_ar' => $project->municipality->name_ar,
                    'name' => $project->municipality->name,
                ] : null,
                'last_update_at' => optional($project->last_update_at)->toIso8601String(),
            ]);

        return response()->json([
            'data' => $projects,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'municipality_id' => ['required', 'integer', 'exists:municipalities,id'],
            'name_en' => ['required', 'string', 'max:255'],
            'name_ar' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(['active', 'archived'])],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $project = Project::create([
            ...$validated,
            'status' => $validated['status'] ?? 'active',
            'last_update_at' => now(),
        ]);

        AuditLogger::log(
            action: 'projects.created',
            entityType: 'projects',
            entityId: $project->id,
            after: $project->toArray(),
            request: $request,
        );

        return response()->json([
            'message' => __('Project created successfully.'),
            'project' => $project->load('municipality'),
        ], 201);
    }

    public function update(Request $request, Project $project): JsonResponse
    {
        $validated = $request->validate([
            'municipality_id' => ['sometimes', 'integer', 'exists:municipalities,id'],
            'name_en' => ['sometimes', 'required', 'string', 'max:255'],
            'name_ar' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', Rule::in(['active', 'archived'])],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
        ]);

        $before = $project->toArray();

        $project->fill($validated);
        $project->last_update_at = now();
        $project->save();

        AuditLogger::log(
            action: 'projects.updated',
            entityType: 'projects',
            entityId: $project->id,
            before: $before,
            after: $project->toArray(),
            request: $request,
        );

        return response()->json([
            'message' => __('Project updated successfully.'),
            'project' => $project->load('municipality'),
        ]);
    }
}
