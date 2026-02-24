<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ValidationReason;
use App\Models\WorkflowStatus;
use Illuminate\Http\JsonResponse;

class WorkflowController extends Controller
{
    public function statuses(): JsonResponse
    {
        $statuses = WorkflowStatus::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (WorkflowStatus $status): array => [
                'id' => $status->id,
                'code' => $status->code,
                'label_en' => $status->label_en,
                'label_ar' => $status->label_ar,
                'label' => $status->label,
                'is_terminal' => $status->is_terminal,
            ]);

        return response()->json(['data' => $statuses]);
    }

    public function reasons(): JsonResponse
    {
        $reasons = ValidationReason::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (ValidationReason $reason): array => [
                'id' => $reason->id,
                'code' => $reason->code,
                'action' => $reason->action,
                'label_en' => $reason->label_en,
                'label_ar' => $reason->label_ar,
                'label' => $reason->label,
            ]);

        return response()->json(['data' => $reasons]);
    }
}
