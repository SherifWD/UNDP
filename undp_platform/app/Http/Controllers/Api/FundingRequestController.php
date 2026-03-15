<?php

namespace App\Http\Controllers\Api;

use App\Enums\FundingRequestStatus;
use App\Http\Controllers\Controller;
use App\Models\FundingRequest;
use App\Models\Project;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FundingRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasPermission('funding_requests.view.all')
            && ! $user->hasPermission('funding_requests.view.own')) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $validated = $request->validate([
            'status' => ['nullable', Rule::in(FundingRequestStatus::values())],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'municipality_id' => ['nullable', 'integer', 'exists:municipalities,id'],
            'donor_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'sort_by' => ['nullable', Rule::in(['created_at', 'amount', 'status', 'reviewed_at'])],
            'sort_dir' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $query = FundingRequest::query()->with([
            'project:id,municipality_id,name_en,name_ar,status',
            'project.municipality:id,name_en,name_ar',
            'donor:id,name,email,role',
            'reviewer:id,name,role',
        ]);

        if ($user->hasPermission('funding_requests.view.all')) {
            if (! empty($validated['donor_user_id'])) {
                $query->where('donor_user_id', $validated['donor_user_id']);
            }
        } else {
            $query->where('donor_user_id', $user->id);
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['project_id'])) {
            $query->where('project_id', $validated['project_id']);
        }

        if (! empty($validated['municipality_id'])) {
            $query->whereHas('project', fn ($builder) => $builder->where('municipality_id', $validated['municipality_id']));
        }

        if (! empty($validated['date_from'])) {
            $query->whereDate('created_at', '>=', $validated['date_from']);
        }

        if (! empty($validated['date_to'])) {
            $query->whereDate('created_at', '<=', $validated['date_to']);
        }

        $sortBy = $validated['sort_by'] ?? 'created_at';
        $sortDir = $validated['sort_dir'] ?? 'desc';

        $rows = $query
            ->orderBy($sortBy, $sortDir)
            ->paginate($validated['per_page'] ?? 15)
            ->through(fn (FundingRequest $fundingRequest): array => $this->serializeFundingRequest($fundingRequest));

        return response()->json($rows);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->hasPermission('funding_requests.create')) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $validated = $request->validate([
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'amount' => ['required', 'numeric', 'min:1', 'max:999999999999.99'],
            'currency' => ['nullable', 'string', 'size:3'],
            'reason' => ['nullable', 'string', 'max:5000'],
        ]);

        $project = Project::query()->findOrFail($validated['project_id']);

        if ($project->status !== 'active') {
            return response()->json([
                'message' => 'Funding requests can only be made for active projects.',
            ], 422);
        }

        $fundingRequest = FundingRequest::query()->create([
            'project_id' => $project->id,
            'donor_user_id' => $request->user()->id,
            'amount' => $validated['amount'],
            'currency' => strtoupper($validated['currency'] ?? 'USD'),
            'reason' => $validated['reason'] ?? null,
            'status' => FundingRequestStatus::PENDING->value,
        ]);

        $fundingRequest->load([
            'project:id,municipality_id,name_en,name_ar,status',
            'project.municipality:id,name_en,name_ar',
            'donor:id,name,email,role',
            'reviewer:id,name,role',
        ]);

        AuditLogger::log(
            action: 'funding_requests.created',
            entityType: 'funding_requests',
            entityId: $fundingRequest->id,
            after: $fundingRequest->only(['project_id', 'donor_user_id', 'amount', 'currency', 'status']),
            metadata: [
                'project_id' => $fundingRequest->project_id,
                'donor_user_id' => $fundingRequest->donor_user_id,
                'amount' => $fundingRequest->amount,
                'currency' => $fundingRequest->currency,
                'status' => $fundingRequest->status,
            ],
            request: $request,
        );

        return response()->json([
            'message' => 'Funding request submitted successfully.',
            'funding_request' => $this->serializeFundingRequest($fundingRequest),
        ], 201);
    }

    public function approve(Request $request, FundingRequest $fundingRequest): JsonResponse
    {
        return $this->review($request, $fundingRequest, FundingRequestStatus::APPROVED);
    }

    public function decline(Request $request, FundingRequest $fundingRequest): JsonResponse
    {
        return $this->review($request, $fundingRequest, FundingRequestStatus::DECLINED);
    }

    private function review(Request $request, FundingRequest $fundingRequest, FundingRequestStatus $status): JsonResponse
    {
        if (! $request->user()->hasPermission('funding_requests.review')) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $validated = $request->validate([
            'review_comment' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($fundingRequest->status !== FundingRequestStatus::PENDING->value) {
            return response()->json([
                'message' => 'Only pending funding requests can be reviewed.',
            ], 422);
        }

        $before = $fundingRequest->only(['status', 'review_comment', 'reviewed_by', 'reviewed_at']);

        $fundingRequest->forceFill([
            'status' => $status->value,
            'review_comment' => $validated['review_comment'] ?? null,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ])->save();

        $fundingRequest->load([
            'project:id,municipality_id,name_en,name_ar,status',
            'project.municipality:id,name_en,name_ar',
            'donor:id,name,email,role',
            'reviewer:id,name,role',
        ]);

        AuditLogger::log(
            action: $status === FundingRequestStatus::APPROVED ? 'funding_requests.approved' : 'funding_requests.declined',
            entityType: 'funding_requests',
            entityId: $fundingRequest->id,
            before: $before,
            after: $fundingRequest->only(['status', 'review_comment', 'reviewed_by', 'reviewed_at']),
            metadata: [
                'project_id' => $fundingRequest->project_id,
                'donor_user_id' => $fundingRequest->donor_user_id,
                'status' => $fundingRequest->status,
            ],
            request: $request,
        );

        return response()->json([
            'message' => $status === FundingRequestStatus::APPROVED
                ? 'Funding request approved.'
                : 'Funding request declined.',
            'funding_request' => $this->serializeFundingRequest($fundingRequest),
        ]);
    }

    private function serializeFundingRequest(FundingRequest $fundingRequest): array
    {
        $status = FundingRequestStatus::tryFrom((string) $fundingRequest->status);

        return [
            'id' => $fundingRequest->id,
            'amount' => (float) $fundingRequest->amount,
            'currency' => $fundingRequest->currency,
            'status' => $fundingRequest->status,
            'status_label' => $status?->label() ?? ucfirst(str_replace('_', ' ', (string) $fundingRequest->status)),
            'reason' => $fundingRequest->reason,
            'review_comment' => $fundingRequest->review_comment,
            'created_at' => optional($fundingRequest->created_at)->toIso8601String(),
            'updated_at' => optional($fundingRequest->updated_at)->toIso8601String(),
            'reviewed_at' => optional($fundingRequest->reviewed_at)->toIso8601String(),
            'project' => $fundingRequest->project ? [
                'id' => $fundingRequest->project->id,
                'name_en' => $fundingRequest->project->name_en,
                'name_ar' => $fundingRequest->project->name_ar,
                'name' => $fundingRequest->project->name,
                'status' => $fundingRequest->project->status,
                'municipality' => $fundingRequest->project->municipality ? [
                    'id' => $fundingRequest->project->municipality->id,
                    'name_en' => $fundingRequest->project->municipality->name_en,
                    'name_ar' => $fundingRequest->project->municipality->name_ar,
                    'name' => $fundingRequest->project->municipality->name,
                ] : null,
            ] : null,
            'donor' => $fundingRequest->donor ? [
                'id' => $fundingRequest->donor->id,
                'name' => $fundingRequest->donor->name,
                'email' => $fundingRequest->donor->email,
                'role' => $fundingRequest->donor->role,
            ] : null,
            'reviewer' => $fundingRequest->reviewer ? [
                'id' => $fundingRequest->reviewer->id,
                'name' => $fundingRequest->reviewer->name,
                'role' => $fundingRequest->reviewer->role,
            ] : null,
        ];
    }
}

