<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Municipality;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MunicipalityController extends Controller
{
    public function index(): JsonResponse
    {
        $municipalities = Municipality::query()
            ->orderBy('name_en')
            ->get()
            ->map(fn (Municipality $municipality): array => [
                'id' => $municipality->id,
                'name_en' => $municipality->name_en,
                'name_ar' => $municipality->name_ar,
                'name' => $municipality->name,
                'code' => $municipality->code,
            ]);

        return response()->json([
            'data' => $municipalities,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name_en' => ['required', 'string', 'max:255'],
            'name_ar' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:20', Rule::unique('municipalities', 'code')],
        ]);

        $municipality = Municipality::create($validated);

        AuditLogger::log(
            action: 'municipalities.created',
            entityType: 'municipalities',
            entityId: $municipality->id,
            after: $municipality->toArray(),
            request: $request,
        );

        return response()->json([
            'message' => __('Municipality created successfully.'),
            'municipality' => $municipality,
        ], 201);
    }

    public function update(Request $request, Municipality $municipality): JsonResponse
    {
        $validated = $request->validate([
            'name_en' => ['sometimes', 'required', 'string', 'max:255'],
            'name_ar' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => ['sometimes', 'nullable', 'string', 'max:20', Rule::unique('municipalities', 'code')->ignore($municipality->id)],
        ]);

        $before = $municipality->toArray();

        $municipality->fill($validated);
        $municipality->save();

        AuditLogger::log(
            action: 'municipalities.updated',
            entityType: 'municipalities',
            entityId: $municipality->id,
            before: $before,
            after: $municipality->toArray(),
            request: $request,
        );

        return response()->json([
            'message' => __('Municipality updated successfully.'),
            'municipality' => $municipality,
        ]);
    }
}
