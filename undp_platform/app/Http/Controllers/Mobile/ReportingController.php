<?php

namespace App\Http\Controllers\Mobile;

use App\Enums\UserRole;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ReportingController extends MobileController
{
    public function options(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
        ]);

        if ($validator->fails()) {
            return $this->returnValidationError($validator);
        }

        $validated = $validator->validated();
        $projectPayload = null;

        if (! empty($validated['project_id'])) {
            $project = Project::query()->with('municipality')->findOrFail($validated['project_id']);
            $user = $request->user();

            if ($user->municipality_id && $user->hasRole([
                UserRole::REPORTER->value,
                UserRole::MUNICIPAL_FOCAL_POINT->value,
            ]) && (int) $project->municipality_id !== (int) $user->municipality_id) {
                return $this->errorResponse('Access denied.', 403);
            }

            $projectPayload = $this->serializeProject($project, $user);
        }

        return $this->successResponse([
            'report_type' => config('mobile.reporting.report_type'),
            'available_options' => [
                'component_categories' => $this->toOptionList(config('mobile.reporting.component_categories', [])),
                'project_statuses' => $this->toOptionList(config('mobile.reporting.project_statuses', [])),
                'delay_reasons' => $this->toOptionList(config('mobile.reporting.delay_reasons', [])),
                'progress_impressions' => $this->toOptionList(config('mobile.reporting.progress_impressions', [])),
                'functional_statuses' => $this->toOptionList(config('mobile.reporting.functional_statuses', [])),
                'user_categories' => $this->toOptionList(config('mobile.reporting.user_categories', [])),
                'constraint_types' => $this->toOptionList(config('mobile.reporting.constraint_types', [])),
            ],
            'defaults' => [
                'reporting_period_label' => sprintf('Week %s - %s', now()->format('W'), now()->format('F Y')),
                'project_status' => 'in_progress',
                'confirm_accuracy' => false,
            ],
            'project' => $projectPayload,
        ]);
    }

    private function toOptionList(array $items): array
    {
        return collect($items)
            ->map(fn (string $label, string $value): array => [
                'value' => $value,
                'label' => $label,
            ])
            ->values()
            ->all();
    }
}
