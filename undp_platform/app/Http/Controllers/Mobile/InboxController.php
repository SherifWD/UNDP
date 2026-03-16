<?php

namespace App\Http\Controllers\Mobile;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Validator;

class InboxController extends MobileController
{
    public function index(Request $request): JsonResponse
    {
        $input = $request->all();

        if (array_key_exists('unread_only', $input)) {
            $input['unread_only'] = $request->boolean('unread_only');
        }

        $validator = Validator::make($input, [
            'unread_only' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return $this->returnValidationError($validator);
        }

        $validated = $validator->validated();
        $query = $request->user()->notifications()->latest();
        $unreadOnly = $request->boolean('unread_only');

        if ($unreadOnly) {
            $query->whereNull('read_at');
        }

        $perPage = (int) ($validated['per_page'] ?? $validated['limit'] ?? 25);
        $page = (int) ($validated['page'] ?? 1);
        $notifications = $query->paginate($perPage, ['*'], 'page', $page);

        return $this->successResponse([
            'items' => $notifications->getCollection()
                ->map(fn (DatabaseNotification $notification): array => $this->serializeNotification($notification))
                ->values(),
            'meta' => [
                'unread_count' => $request->user()->unreadNotifications()->count(),
                'returned' => $notifications->count(),
            ],
            'pagination' => [
                'page' => $notifications->currentPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
                'total_pages' => $notifications->lastPage(),
                'has_previous' => $notifications->currentPage() > 1,
                'has_more' => $notifications->hasMorePages(),
            ],
            'filters' => [
                'unread_only' => $unreadOnly,
            ],
        ]);
    }

    public function markRead(Request $request, DatabaseNotification $notification): JsonResponse
    {
        if ((int) $notification->notifiable_id !== (int) $request->user()->id
            || $notification->notifiable_type !== $request->user()::class) {
            return $this->errorResponse('Notification not found.', 404);
        }

        if (! $notification->read_at) {
            $notification->markAsRead();
        }

        return $this->successResponse([
            'notification' => $this->serializeNotification($notification->fresh()),
        ], 'Notification marked as read.');
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update([
            'read_at' => now(),
        ]);

        return $this->successResponse([], 'All notifications marked as read.');
    }

    private function serializeNotification(DatabaseNotification $notification): array
    {
        $data = (array) $notification->data;
        $status = is_string($data['status'] ?? null) ? $data['status'] : null;

        return [
            'id' => $notification->id,
            'type' => class_basename($notification->type),
            'title' => $data['title'] ?? 'Update',
            'message' => $status
                ? sprintf('%s is now %s', $data['title'] ?? 'Submission', $this->mobileSubmissionStatusLabel($status))
                : ($data['title'] ?? 'Notification'),
            'submission_id' => $data['submission_id'] ?? null,
            'project_name' => $data['project_name'] ?? null,
            'status' => $status,
            'status_label' => $status ? $this->mobileSubmissionStatusLabel($status) : null,
            'is_read' => $notification->read_at !== null,
            'read_at' => optional($notification->read_at)->toIso8601String(),
            'created_at' => optional($notification->created_at)->toIso8601String(),
        ];
    }
}
