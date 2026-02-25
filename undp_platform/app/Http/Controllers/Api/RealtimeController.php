<?php

namespace App\Http\Controllers\Api;

use App\Enums\SubmissionStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Submission;
use App\Models\User;
use App\Services\SubmissionAccessService;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RealtimeController extends Controller
{
    public function stream(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'channel' => ['required', 'in:audit,worklist,dashboard'],
            'token' => ['required', 'string', 'min:20'],
        ]);

        $accessToken = PersonalAccessToken::findToken($validated['token']);

        if (! $accessToken || ! $accessToken->tokenable instanceof User) {
            abort(401, 'Unauthenticated.');
        }

        $user = $accessToken->tokenable;
        $channel = $validated['channel'];

        if (! $user->isActive()) {
            abort(403, 'Your account is disabled.');
        }

        if (! $this->canAccessChannel($user, $channel)) {
            abort(403, 'Access denied.');
        }

        return response()->stream(function () use ($channel, $user): void {
            @ini_set('output_buffering', 'off');
            @ini_set('zlib.output_compression', '0');

            echo ": connected\n\n";
            @ob_flush();
            @flush();

            $lastSignature = '';
            $endAt = now()->addSeconds(25);

            while (now()->lt($endAt)) {
                $payload = $this->buildChannelPayload($channel, $user);
                $signature = md5(json_encode($payload) ?: '');

                if ($signature !== $lastSignature) {
                    echo "event: update\n";
                    echo 'data: '.json_encode($payload)."\n\n";
                    @ob_flush();
                    @flush();
                    $lastSignature = $signature;
                }

                sleep(2);
            }

            echo "event: end\n";
            echo "data: {}\n\n";
            @ob_flush();
            @flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function canAccessChannel(User $user, string $channel): bool
    {
        return match ($channel) {
            'audit' => $user->hasPermission('audit.view'),
            'worklist' => $user->hasPermission('submissions.validate'),
            'dashboard' => $user->hasPermission('dashboards.view.system')
                || $user->hasPermission('dashboards.view.municipality')
                || $user->hasPermission('dashboards.view.partner')
                || $user->hasPermission('dashboards.view.own'),
            default => false,
        };
    }

    private function buildChannelPayload(string $channel, User $user): array
    {
        if ($channel === 'audit') {
            $latest = AuditLog::query()->latest('id')->first();

            return [
                'channel' => $channel,
                'latest_id' => $latest?->id ?? 0,
                'latest_at' => optional($latest?->created_at)->toIso8601String(),
            ];
        }

        if ($channel === 'worklist') {
            $query = Submission::query()->whereIn('status', [
                SubmissionStatus::UNDER_REVIEW->value,
                SubmissionStatus::REWORK_REQUESTED->value,
                SubmissionStatus::SUBMITTED->value,
            ]);

            if ($user->municipality_id && ! $user->hasPermission('submissions.view.all')) {
                $query->where('municipality_id', $user->municipality_id);
            }

            return [
                'channel' => $channel,
                'pending_count' => (clone $query)->count(),
                'latest_submission_id' => (clone $query)->max('id') ?? 0,
            ];
        }

        $query = Submission::query();
        SubmissionAccessService::scope($user, $query);

        return [
            'channel' => $channel,
            'total_submissions' => (clone $query)->count(),
            'approved' => (clone $query)->where('status', SubmissionStatus::APPROVED->value)->count(),
            'under_review' => (clone $query)->where('status', SubmissionStatus::UNDER_REVIEW->value)->count(),
            'rework_requested' => (clone $query)->where('status', SubmissionStatus::REWORK_REQUESTED->value)->count(),
            'rejected' => (clone $query)->where('status', SubmissionStatus::REJECTED->value)->count(),
            'latest_submission_id' => (clone $query)->max('id') ?? 0,
        ];
    }
}
