<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class AuditLogPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function present(AuditLog $log): array
    {
        $action = (string) $log->action;
        $moduleLabel = $this->resolveModuleLabel($log);
        $subjectLabel = $this->resolveSubjectLabel($log);

        return [
            'action_label' => $this->resolveActionLabel($action),
            'module_label' => $moduleLabel,
            'subject_label' => $subjectLabel,
            'page_label' => $this->resolvePageLabel($log, $moduleLabel),
            'summary' => $this->resolveSummary($log, $subjectLabel),
            'overview' => $this->buildOverview($log, $moduleLabel, $subjectLabel),
            'changes' => $this->buildChanges($log),
            'context' => $this->buildContext($log),
            'device_label' => $this->deviceLabel($log->user_agent),
        ];
    }

    /**
     * @return array<int, array{label:string,value:string}>
     */
    private function buildOverview(AuditLog $log, string $moduleLabel, string $subjectLabel): array
    {
        $items = [
            ['label' => 'Performed by', 'value' => $this->actorLabel($log)],
            ['label' => 'Actor role', 'value' => $this->actorRoleLabel($log)],
            ['label' => 'Affected module', 'value' => $moduleLabel],
            ['label' => 'Affected record', 'value' => $subjectLabel],
            ['label' => 'Page / source', 'value' => $this->resolvePageLabel($log, $moduleLabel)],
            ['label' => 'Logged at', 'value' => optional($log->created_at)?->toDateTimeString() ?? 'Not available'],
            ['label' => 'IP address', 'value' => $log->ip_address ?: 'Not available'],
            ['label' => 'Device', 'value' => $this->deviceLabel($log->user_agent)],
        ];

        return array_values(array_filter(
            $items,
            static fn (array $item): bool => trim((string) ($item['value'] ?? '')) !== ''
        ));
    }

    /**
     * @return array<int, array{field:string,before:string,after:string}>
     */
    private function buildChanges(AuditLog $log): array
    {
        $before = $this->flattenPayload($log->before ?? []);
        $after = $this->flattenPayload($log->after ?? []);
        $keys = array_values(array_unique(array_merge(array_keys($before), array_keys($after))));
        $changes = [];

        foreach ($keys as $key) {
            $beforeValue = $before[$key] ?? null;
            $afterValue = $after[$key] ?? null;

            if ($beforeValue === $afterValue) {
                continue;
            }

            $changes[] = [
                'field' => $this->labelFromPath($key),
                'before' => $this->formatDisplayValue($beforeValue, $key),
                'after' => $this->formatDisplayValue($afterValue, $key),
            ];
        }

        return $changes;
    }

    /**
     * @return array<int, array{label:string,value:string}>
     */
    private function buildContext(AuditLog $log): array
    {
        $metadata = $this->flattenPayload($log->metadata ?? []);
        $beforeKeys = array_keys($this->flattenPayload($log->before ?? []));
        $afterKeys = array_keys($this->flattenPayload($log->after ?? []));
        $items = [];

        foreach ($metadata as $key => $value) {
            if (in_array($key, ['request_path', 'request_method', 'route_name', 'source'], true)) {
                continue;
            }

            if (in_array($key, $beforeKeys, true) || in_array($key, $afterKeys, true)) {
                continue;
            }

            if ($key === 'old_status' && (in_array('status', $beforeKeys, true) || in_array('status', $afterKeys, true))) {
                continue;
            }

            $items[] = [
                'label' => $this->labelFromPath($key),
                'value' => $this->formatDisplayValue($value, $key),
            ];
        }

        return $items;
    }

    private function resolveSummary(AuditLog $log, string $subjectLabel): string
    {
        $action = (string) $log->action;
        $actor = $this->actorLabel($log);
        $metadata = is_array($log->metadata) ? $log->metadata : [];
        $before = is_array($log->before) ? $log->before : [];
        $after = is_array($log->after) ? $log->after : [];

        $summary = match ($action) {
            'submissions.created' => sprintf('%s created %s.', $actor, $subjectLabel),
            'submissions.status_changed' => sprintf(
                '%s changed %s status from %s to %s.',
                $actor,
                $subjectLabel,
                $this->formatDisplayValue($before['status'] ?? $metadata['old_status'] ?? null, 'status'),
                $this->formatDisplayValue($after['status'] ?? $metadata['status'] ?? null, 'status'),
            ),
            'projects.created' => sprintf('%s created %s.', $actor, $subjectLabel),
            'projects.updated' => sprintf('%s updated %s.', $actor, $subjectLabel),
            'projects.deleted' => sprintf('%s deleted %s.', $actor, $subjectLabel),
            'users.created' => sprintf('%s created %s.', $actor, $subjectLabel),
            'users.updated' => sprintf('%s updated %s.', $actor, $subjectLabel),
            'users.disabled' => sprintf('%s disabled %s.', $actor, $subjectLabel),
            'users.enabled' => sprintf('%s enabled %s.', $actor, $subjectLabel),
            'funding_requests.created' => sprintf('%s created %s.', $actor, $subjectLabel),
            'funding_requests.approved' => sprintf('%s approved %s.', $actor, $subjectLabel),
            'funding_requests.declined' => sprintf('%s declined %s.', $actor, $subjectLabel),
            'municipalities.created' => sprintf('%s created %s.', $actor, $subjectLabel),
            'municipalities.updated' => sprintf('%s updated %s.', $actor, $subjectLabel),
            'settings.updated' => sprintf('%s updated the system settings.', $actor),
            'exports.task_created' => sprintf(
                '%s queued %s export %s.',
                $actor,
                $this->formatDisplayValue($metadata['type'] ?? null, 'type'),
                $subjectLabel,
            ),
            'exports.task_ready' => sprintf(
                '%s is ready for download.',
                $subjectLabel,
            ),
            'exports.task_failed' => sprintf(
                '%s failed during generation.',
                $subjectLabel,
            ),
            'media.presigned_upload_created' => sprintf('%s created an upload link for %s.', $actor, $subjectLabel),
            'media.upload_completed' => sprintf('%s completed the upload for %s.', $actor, $subjectLabel),
            'media.download_url_issued' => sprintf('%s issued a download link for %s.', $actor, $subjectLabel),
            'media.processing_ready' => sprintf('%s finished processing %s.', $actor, $subjectLabel),
            'mobile.submissions.media_deleted' => sprintf('%s deleted %s from mobile reporting.', $actor, $subjectLabel),
            'mobile.submissions.draft_created' => sprintf('%s created draft %s from mobile reporting.', $actor, $subjectLabel),
            'mobile.submissions.created' => sprintf('%s submitted %s from mobile reporting.', $actor, $subjectLabel),
            'mobile.submissions.draft_updated' => sprintf('%s updated draft %s from mobile reporting.', $actor, $subjectLabel),
            'mobile.submissions.resubmitted' => sprintf('%s resubmitted %s from mobile reporting.', $actor, $subjectLabel),
            'mobile.submissions.asset_uploaded' => sprintf('%s uploaded %s from mobile reporting.', $actor, $subjectLabel),
            'auth.otp_requested' => sprintf(
                'A one-time password was requested for %s.',
                $this->formatDisplayValue($metadata['masked_phone'] ?? $log->entity_id, 'phone'),
            ),
            'auth.login_blocked_disabled' => sprintf('Login was blocked because %s is disabled.', $subjectLabel),
            'auth.login_success' => sprintf('%s signed in successfully.', $actor),
            'auth.token_refreshed' => sprintf('%s refreshed the authentication token.', $actor),
            'auth.reporter_registered' => sprintf('%s registered %s.', $actor, $subjectLabel),
            'auth.logout' => sprintf('%s signed out.', $actor),
            'auth.blocked_permission' => sprintf(
                '%s was blocked from accessing a protected action because the required permission was missing.',
                $actor,
            ),
            'auth.token_revoked_user_disabled' => sprintf(
                '%s had the current token revoked because the account is disabled.',
                $subjectLabel,
            ),
            default => sprintf(
                '%s performed "%s" on %s.',
                $actor,
                $this->resolveActionLabel($action),
                $subjectLabel,
            ),
        };

        $comment = rtrim(trim((string) ($metadata['comment'] ?? $metadata['review_comment'] ?? $after['review_comment'] ?? '')), '.');

        if ($comment !== '') {
            $summary .= sprintf(' Comment: %s.', $comment);
        }

        return $summary;
    }

    private function resolveActionLabel(string $action): string
    {
        return match ($action) {
            'submissions.created' => 'Created',
            'submissions.status_changed' => 'Status changed',
            'projects.created' => 'Created',
            'projects.updated' => 'Updated',
            'projects.deleted' => 'Deleted',
            'users.created' => 'Created',
            'users.updated' => 'Updated',
            'users.disabled' => 'Disabled',
            'users.enabled' => 'Enabled',
            'funding_requests.created' => 'Created',
            'funding_requests.approved' => 'Approved',
            'funding_requests.declined' => 'Declined',
            'municipalities.created' => 'Created',
            'municipalities.updated' => 'Updated',
            'settings.updated' => 'Updated',
            'exports.task_created' => 'Export queued',
            'exports.task_ready' => 'Export ready',
            'exports.task_failed' => 'Export failed',
            'media.presigned_upload_created' => 'Upload link created',
            'media.upload_completed' => 'Upload completed',
            'media.download_url_issued' => 'Download link issued',
            'media.processing_ready' => 'Processing completed',
            'mobile.submissions.media_deleted' => 'Media deleted',
            'mobile.submissions.draft_created' => 'Draft created',
            'mobile.submissions.created' => 'Submitted',
            'mobile.submissions.draft_updated' => 'Draft updated',
            'mobile.submissions.resubmitted' => 'Resubmitted',
            'mobile.submissions.asset_uploaded' => 'Asset uploaded',
            'auth.otp_requested' => 'OTP requested',
            'auth.login_blocked_disabled' => 'Login blocked',
            'auth.login_success' => 'Login success',
            'auth.token_refreshed' => 'Token refreshed',
            'auth.reporter_registered' => 'Reporter registered',
            'auth.logout' => 'Logged out',
            'auth.blocked_permission' => 'Permission blocked',
            'auth.token_revoked_user_disabled' => 'Token revoked',
            default => Str::headline(str_replace('.', ' ', Str::afterLast($action, '.'))),
        };
    }

    private function resolveModuleLabel(AuditLog $log): string
    {
        $action = (string) $log->action;

        if (str_starts_with($action, 'mobile.')) {
            return 'Mobile reporting';
        }

        $prefix = Str::before($action, '.');

        return match ($prefix) {
            'auth' => 'Authentication',
            'exports' => 'Exports',
            'media' => 'Media',
            'settings' => 'Settings',
            default => $this->entityTypeLabel($log->entity_type),
        };
    }

    private function resolveSubjectLabel(AuditLog $log): string
    {
        $entityType = (string) $log->entity_type;
        $entityId = $log->entity_id;
        $metadata = is_array($log->metadata) ? $log->metadata : [];
        $name = $this->extractDisplayName($log);

        if ($entityType === 'phone') {
            return 'Phone '.$this->formatDisplayValue($metadata['masked_phone'] ?? $entityId, 'phone');
        }

        if ($entityType === 'settings') {
            return 'System settings';
        }

        $label = $this->entityTypeSingularLabel($entityType);

        if ($entityId !== null && $entityId !== '') {
            $label .= ' #'.$entityId;
        }

        if ($name !== null && $name !== '') {
            $label .= sprintf(' "%s"', $name);
        }

        return $label;
    }

    private function resolvePageLabel(AuditLog $log, string $moduleLabel): string
    {
        $metadata = is_array($log->metadata) ? $log->metadata : [];
        $path = trim((string) ($metadata['request_path'] ?? $metadata['path'] ?? ''));
        $method = strtoupper(trim((string) ($metadata['request_method'] ?? $metadata['method'] ?? '')));

        if ($path !== '') {
            $normalizedPath = str_starts_with($path, '/') ? $path : '/'.$path;

            return trim(sprintf('%s %s', $method, $normalizedPath));
        }

        return match (true) {
            str_starts_with((string) $log->action, 'mobile.') => 'Mobile app',
            str_starts_with((string) $log->action, 'auth.') => 'Authentication flow',
            default => $moduleLabel,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function flattenPayload(array $payload, string $prefix = ''): array
    {
        $flattened = [];

        foreach ($payload as $key => $value) {
            if (! is_string($key) && ! is_int($key)) {
                continue;
            }

            $stringKey = (string) $key;

            if ($this->shouldSkipField($stringKey)) {
                continue;
            }

            $path = $prefix === '' ? $stringKey : $prefix.'.'.$stringKey;

            if (is_array($value)) {
                if ($value === []) {
                    $flattened[$path] = [];
                    continue;
                }

                if (Arr::isAssoc($value) && $this->canExpandArray($path)) {
                    $flattened = array_merge($flattened, $this->flattenPayload($value, $path));
                    continue;
                }

                $flattened[$path] = $value;

                continue;
            }

            $flattened[$path] = $value;
        }

        return $flattened;
    }

    private function canExpandArray(string $path): bool
    {
        if (substr_count($path, '.') >= 2) {
            return false;
        }

        foreach (['assigned_reporters', 'variants', 'permissions', 'role_definitions', 'permission_matrix'] as $fragment) {
            if (str_contains($path, $fragment)) {
                return false;
            }
        }

        return true;
    }

    private function shouldSkipField(string $key): bool
    {
        return in_array($key, [
            'id',
            'pivot',
            'mobile_meta',
            'remember_token',
            'password',
            'created_at',
            'updated_at',
        ], true);
    }

    private function labelFromPath(string $path): string
    {
        $parts = explode('.', $path);

        return collect($parts)
            ->map(fn (string $part): string => $this->labelForKey($part))
            ->implode(' / ');
    }

    private function labelForKey(string $key): string
    {
        return match ($key) {
            'phone_e164', 'phone' => 'Phone',
            'name_en' => 'English name',
            'name_ar' => 'Arabic name',
            'project_id' => 'Project ID',
            'municipality_id' => 'Municipality ID',
            'client_uuid' => 'Client UUID',
            'review_comment' => 'Review comment',
            'reviewed_by' => 'Reviewed by user ID',
            'reviewed_at' => 'Reviewed at',
            'submitted_at' => 'Submitted at',
            'validated_at' => 'Validated at',
            'validated_by' => 'Validated by user ID',
            'disabled_at' => 'Disabled at',
            'disabled_reason' => 'Disable reason',
            'assigned_reporter_ids' => 'Assigned reporter IDs',
            'submission_id' => 'Submission ID',
            'media_type' => 'Media type',
            'object_key' => 'Storage object',
            'masked_phone' => 'Phone',
            'old_status' => 'Previous status',
            'expires_in' => 'Link expiry',
            'size_bytes' => 'File size',
            'file_name' => 'File name',
            'route_name' => 'Route name',
            'request_path', 'path' => 'Request path',
            'request_method', 'method' => 'Request method',
            default => Str::headline(str_replace('.', ' ', $key)),
        };
    }

    private function formatDisplayValue(mixed $value, string $key = ''): string
    {
        if ($value === null) {
            return 'Not set';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_int($value) || is_float($value)) {
            if ($key === 'size_bytes') {
                return $this->humanFileSize((int) $value);
            }

            if ($key === 'expires_in') {
                return sprintf('%d seconds', (int) $value);
            }

            if (str_ends_with($key, '_id')) {
                return '#'.(string) $value;
            }

            return (string) $value;
        }

        if (is_array($value)) {
            if ($value === []) {
                return 'None';
            }

            if (! Arr::isAssoc($value)) {
                $rendered = collect($value)
                    ->map(function (mixed $item): string {
                        if (is_array($item)) {
                            if (isset($item['id'])) {
                                return '#'.$item['id'];
                            }

                            if (isset($item['name'])) {
                                return (string) $item['name'];
                            }

                            if (isset($item['label'])) {
                                return (string) $item['label'];
                            }

                            return sprintf('%d item field(s)', count($item));
                        }

                        return $this->formatDisplayValue($item);
                    })
                    ->implode(', ');

                return $rendered !== '' ? $rendered : 'None';
            }

            if (isset($value['name'])) {
                return (string) $value['name'];
            }

            if (isset($value['name_en'])) {
                return (string) $value['name_en'];
            }

            if (isset($value['title'])) {
                return (string) $value['title'];
            }

            return collect($value)
                ->map(fn (mixed $item, string|int $itemKey): string => sprintf(
                    '%s: %s',
                    $this->labelForKey((string) $itemKey),
                    $this->formatDisplayValue($item, (string) $itemKey),
                ))
                ->implode('; ');
        }

        $stringValue = trim((string) $value);

        if ($stringValue === '') {
            return 'Not set';
        }

        if (str_contains($key, 'phone')) {
            return $stringValue;
        }

        if (in_array($key, ['status', 'old_status', 'role', 'type', 'format', 'source', 'media_type', 'project_status'], true)) {
            return Str::headline(str_replace(['.', '_'], ' ', $stringValue));
        }

        if (str_ends_with($key, '_id') && is_numeric($stringValue)) {
            return '#'.$stringValue;
        }

        if (str_contains($stringValue, '_') && preg_match('/^[a-z0-9_]+$/i', $stringValue) === 1) {
            return Str::headline($stringValue);
        }

        return $stringValue;
    }

    private function actorLabel(AuditLog $log): string
    {
        return $log->actor?->name ?: 'System';
    }

    private function actorRoleLabel(AuditLog $log): string
    {
        return $log->actor?->role
            ? $this->formatDisplayValue($log->actor->role, 'role')
            : 'System';
    }

    private function entityTypeLabel(?string $entityType): string
    {
        return match ($entityType) {
            'submissions' => 'Submissions',
            'projects' => 'Projects',
            'users' => 'Users',
            'funding_requests' => 'Funding requests',
            'municipalities' => 'Municipalities',
            'media_assets' => 'Media assets',
            'export_tasks' => 'Export tasks',
            'settings' => 'Settings',
            'permission' => 'Permissions',
            'phone' => 'Phone verification',
            default => $entityType ? Str::headline(str_replace('_', ' ', $entityType)) : 'System',
        };
    }

    private function entityTypeSingularLabel(?string $entityType): string
    {
        return match ($entityType) {
            'submissions' => 'Submission',
            'projects' => 'Project',
            'users' => 'User',
            'funding_requests' => 'Funding request',
            'municipalities' => 'Municipality',
            'media_assets' => 'Media asset',
            'export_tasks' => 'Export task',
            'settings' => 'Setting',
            'permission' => 'Permission',
            'phone' => 'Phone',
            default => $entityType ? Str::headline(Str::singular(str_replace('_', ' ', $entityType))) : 'Record',
        };
    }

    private function extractDisplayName(AuditLog $log): ?string
    {
        foreach ([$log->after ?? [], $log->before ?? [], $log->metadata ?? []] as $payload) {
            if (! is_array($payload)) {
                continue;
            }

            foreach (['title', 'name', 'name_en', 'file_name'] as $field) {
                $value = trim((string) ($payload[$field] ?? ''));

                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    private function humanFileSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        $units = ['KB', 'MB', 'GB', 'TB'];
        $size = $bytes / 1024;
        $unitIndex = 0;

        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        return number_format($size, $size < 10 ? 1 : 0).' '.$units[$unitIndex];
    }

    private function deviceLabel(?string $userAgent): string
    {
        $agent = trim((string) $userAgent);

        if ($agent === '') {
            return 'Unknown device';
        }

        $browser = collect(['Chrome', 'Firefox', 'Safari', 'Edge', 'Opera', 'Brave'])
            ->first(fn (string $item): bool => str_contains($agent, $item)) ?? 'Browser';

        $platform = collect(['Windows', 'macOS', 'Linux', 'Android', 'iPhone', 'iPad'])
            ->first(fn (string $item): bool => str_contains($agent, $item)) ?? 'Device';

        return $browser.' - '.$platform;
    }
}
