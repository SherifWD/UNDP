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
            ['label' => $this->tr('Performed by', 'نفذه'), 'value' => $this->actorLabel($log)],
            ['label' => $this->tr('Actor role', 'دور المنفذ'), 'value' => $this->actorRoleLabel($log)],
            ['label' => $this->tr('Affected module', 'الوحدة المتأثرة'), 'value' => $moduleLabel],
            ['label' => $this->tr('Affected record', 'السجل المتأثر'), 'value' => $subjectLabel],
            ['label' => $this->tr('Page / source', 'الصفحة / المصدر'), 'value' => $this->resolvePageLabel($log, $moduleLabel)],
            ['label' => $this->tr('Logged at', 'وقت التسجيل'), 'value' => optional($log->created_at)?->toDateTimeString() ?? $this->tr('Not available', 'غير متوفر')],
            ['label' => $this->tr('IP address', 'عنوان IP'), 'value' => $log->ip_address ?: $this->tr('Not available', 'غير متوفر')],
            ['label' => $this->tr('Device', 'الجهاز'), 'value' => $this->deviceLabel($log->user_agent)],
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
            'submissions.created' => sprintf($this->tr('%s created %s.', '%s أنشأ %s.'), $actor, $subjectLabel),
            'submissions.status_changed' => sprintf(
                $this->tr('%s changed %s status from %s to %s.', '%s غيّر حالة %s من %s إلى %s.'),
                $actor,
                $subjectLabel,
                $this->formatDisplayValue($before['status'] ?? $metadata['old_status'] ?? null, 'status'),
                $this->formatDisplayValue($after['status'] ?? $metadata['status'] ?? null, 'status'),
            ),
            'projects.created' => sprintf($this->tr('%s created %s.', '%s أنشأ %s.'), $actor, $subjectLabel),
            'projects.updated' => sprintf($this->tr('%s updated %s.', '%s حدّث %s.'), $actor, $subjectLabel),
            'projects.deleted' => sprintf($this->tr('%s deleted %s.', '%s حذف %s.'), $actor, $subjectLabel),
            'users.created' => sprintf($this->tr('%s created %s.', '%s أنشأ %s.'), $actor, $subjectLabel),
            'users.updated' => sprintf($this->tr('%s updated %s.', '%s حدّث %s.'), $actor, $subjectLabel),
            'users.disabled' => sprintf($this->tr('%s disabled %s.', '%s عطّل %s.'), $actor, $subjectLabel),
            'users.enabled' => sprintf($this->tr('%s enabled %s.', '%s فعّل %s.'), $actor, $subjectLabel),
            'funding_requests.created' => sprintf($this->tr('%s created %s.', '%s أنشأ %s.'), $actor, $subjectLabel),
            'funding_requests.approved' => sprintf($this->tr('%s approved %s.', '%s وافق على %s.'), $actor, $subjectLabel),
            'funding_requests.declined' => sprintf($this->tr('%s declined %s.', '%s رفض %s.'), $actor, $subjectLabel),
            'municipalities.created' => sprintf($this->tr('%s created %s.', '%s أنشأ %s.'), $actor, $subjectLabel),
            'municipalities.updated' => sprintf($this->tr('%s updated %s.', '%s حدّث %s.'), $actor, $subjectLabel),
            'settings.updated' => sprintf($this->tr('%s updated the system settings.', '%s حدّث إعدادات النظام.'), $actor),
            'exports.task_created' => sprintf(
                $this->tr('%s queued %s export %s.', '%s وضع %s للتصدير في قائمة الانتظار %s.'),
                $actor,
                $this->formatDisplayValue($metadata['type'] ?? null, 'type'),
                $subjectLabel,
            ),
            'exports.task_ready' => sprintf(
                $this->tr('%s is ready for download.', '%s جاهز للتنزيل.'),
                $subjectLabel,
            ),
            'exports.task_failed' => sprintf(
                $this->tr('%s failed during generation.', 'فشل إنشاء %s.'),
                $subjectLabel,
            ),
            'media.presigned_upload_created' => sprintf($this->tr('%s created an upload link for %s.', '%s أنشأ رابط رفع لـ %s.'), $actor, $subjectLabel),
            'media.upload_completed' => sprintf($this->tr('%s completed the upload for %s.', '%s أكمل رفع %s.'), $actor, $subjectLabel),
            'media.download_url_issued' => sprintf($this->tr('%s issued a download link for %s.', '%s أصدر رابط تنزيل لـ %s.'), $actor, $subjectLabel),
            'media.processing_ready' => sprintf($this->tr('%s finished processing %s.', '%s أنهى معالجة %s.'), $actor, $subjectLabel),
            'mobile.submissions.media_deleted' => sprintf($this->tr('%s deleted %s from mobile reporting.', '%s حذف %s من التقارير عبر الجوال.'), $actor, $subjectLabel),
            'mobile.submissions.draft_created' => sprintf($this->tr('%s created draft %s from mobile reporting.', '%s أنشأ مسودة %s من التقارير عبر الجوال.'), $actor, $subjectLabel),
            'mobile.submissions.created' => sprintf($this->tr('%s submitted %s from mobile reporting.', '%s أرسل %s من التقارير عبر الجوال.'), $actor, $subjectLabel),
            'mobile.submissions.draft_updated' => sprintf($this->tr('%s updated draft %s from mobile reporting.', '%s حدّث مسودة %s من التقارير عبر الجوال.'), $actor, $subjectLabel),
            'mobile.submissions.resubmitted' => sprintf($this->tr('%s resubmitted %s from mobile reporting.', '%s أعاد إرسال %s من التقارير عبر الجوال.'), $actor, $subjectLabel),
            'mobile.submissions.asset_uploaded' => sprintf($this->tr('%s uploaded %s from mobile reporting.', '%s رفع %s من التقارير عبر الجوال.'), $actor, $subjectLabel),
            'auth.otp_requested' => sprintf(
                $this->tr('A one-time password was requested for %s.', 'تم طلب رمز تحقق لمرة واحدة لـ %s.'),
                $this->formatDisplayValue($metadata['masked_phone'] ?? $log->entity_id, 'phone'),
            ),
            'auth.login_blocked_disabled' => sprintf($this->tr('Login was blocked because %s is disabled.', 'تم حظر تسجيل الدخول لأن %s معطّل.'), $subjectLabel),
            'auth.login_success' => sprintf($this->tr('%s signed in successfully.', 'سجّل %s الدخول بنجاح.'), $actor),
            'auth.token_refreshed' => sprintf($this->tr('%s refreshed the authentication token.', 'جدّد %s رمز المصادقة.'), $actor),
            'auth.reporter_registered' => sprintf($this->tr('%s registered %s.', '%s سجّل %s.'), $actor, $subjectLabel),
            'auth.logout' => sprintf($this->tr('%s signed out.', 'سجّل %s الخروج.'), $actor),
            'auth.blocked_permission' => sprintf(
                $this->tr(
                    '%s was blocked from accessing a protected action because the required permission was missing.',
                    'تم منع %s من الوصول إلى إجراء محمي بسبب غياب الصلاحية المطلوبة.'
                ),
                $actor,
            ),
            'auth.token_revoked_user_disabled' => sprintf(
                $this->tr(
                    '%s had the current token revoked because the account is disabled.',
                    'تم إلغاء الرمز الحالي لـ %s لأن الحساب معطّل.'
                ),
                $subjectLabel,
            ),
            default => sprintf(
                $this->tr('%s performed "%s" on %s.', '%s نفّذ "%s" على %s.'),
                $actor,
                $this->resolveActionLabel($action),
                $subjectLabel,
            ),
        };

        $comment = rtrim(trim((string) ($metadata['comment'] ?? $metadata['review_comment'] ?? $after['review_comment'] ?? '')), '.');

        if ($comment !== '') {
            $summary .= sprintf($this->tr(' Comment: %s.', ' تعليق: %s.'), $comment);
        }

        return $summary;
    }

    private function resolveActionLabel(string $action): string
    {
        return match ($action) {
            'submissions.created' => $this->tr('Created', 'تم الإنشاء'),
            'submissions.status_changed' => $this->tr('Status changed', 'تم تغيير الحالة'),
            'projects.created' => $this->tr('Created', 'تم الإنشاء'),
            'projects.updated' => $this->tr('Updated', 'تم التحديث'),
            'projects.deleted' => $this->tr('Deleted', 'تم الحذف'),
            'users.created' => $this->tr('Created', 'تم الإنشاء'),
            'users.updated' => $this->tr('Updated', 'تم التحديث'),
            'users.disabled' => $this->tr('Disabled', 'تم التعطيل'),
            'users.enabled' => $this->tr('Enabled', 'تم التفعيل'),
            'funding_requests.created' => $this->tr('Created', 'تم الإنشاء'),
            'funding_requests.approved' => $this->tr('Approved', 'تمت الموافقة'),
            'funding_requests.declined' => $this->tr('Declined', 'تم الرفض'),
            'municipalities.created' => $this->tr('Created', 'تم الإنشاء'),
            'municipalities.updated' => $this->tr('Updated', 'تم التحديث'),
            'settings.updated' => $this->tr('Updated', 'تم التحديث'),
            'exports.task_created' => $this->tr('Export queued', 'تمت إضافة التصدير إلى الانتظار'),
            'exports.task_ready' => $this->tr('Export ready', 'التصدير جاهز'),
            'exports.task_failed' => $this->tr('Export failed', 'فشل التصدير'),
            'media.presigned_upload_created' => $this->tr('Upload link created', 'تم إنشاء رابط الرفع'),
            'media.upload_completed' => $this->tr('Upload completed', 'اكتمل الرفع'),
            'media.download_url_issued' => $this->tr('Download link issued', 'تم إصدار رابط التنزيل'),
            'media.processing_ready' => $this->tr('Processing completed', 'اكتملت المعالجة'),
            'mobile.submissions.media_deleted' => $this->tr('Media deleted', 'تم حذف الوسائط'),
            'mobile.submissions.draft_created' => $this->tr('Draft created', 'تم إنشاء المسودة'),
            'mobile.submissions.created' => $this->tr('Submitted', 'تم الإرسال'),
            'mobile.submissions.draft_updated' => $this->tr('Draft updated', 'تم تحديث المسودة'),
            'mobile.submissions.resubmitted' => $this->tr('Resubmitted', 'تمت إعادة الإرسال'),
            'mobile.submissions.asset_uploaded' => $this->tr('Asset uploaded', 'تم رفع الملف'),
            'auth.otp_requested' => $this->tr('OTP requested', 'تم طلب رمز التحقق'),
            'auth.login_blocked_disabled' => $this->tr('Login blocked', 'تم حظر تسجيل الدخول'),
            'auth.login_success' => $this->tr('Login success', 'تم تسجيل الدخول'),
            'auth.token_refreshed' => $this->tr('Token refreshed', 'تم تجديد الرمز'),
            'auth.reporter_registered' => $this->tr('Reporter registered', 'تم تسجيل المراسل'),
            'auth.logout' => $this->tr('Logged out', 'تم تسجيل الخروج'),
            'auth.blocked_permission' => $this->tr('Permission blocked', 'تم منع الوصول بالصلاحيات'),
            'auth.token_revoked_user_disabled' => $this->tr('Token revoked', 'تم إلغاء الرمز'),
            default => $this->humanizeUnknownAction($action),
        };
    }

    private function resolveModuleLabel(AuditLog $log): string
    {
        $action = (string) $log->action;

        if (str_starts_with($action, 'mobile.')) {
            return $this->tr('Mobile reporting', 'التقارير عبر الجوال');
        }

        $prefix = Str::before($action, '.');

        return match ($prefix) {
            'auth' => $this->tr('Authentication', 'المصادقة'),
            'exports' => $this->tr('Exports', 'التصدير'),
            'media' => $this->tr('Media', 'الوسائط'),
            'settings' => $this->tr('Settings', 'الإعدادات'),
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
            return $this->tr('Phone ', 'الهاتف ').$this->formatDisplayValue($metadata['masked_phone'] ?? $entityId, 'phone');
        }

        if ($entityType === 'settings') {
            return $this->tr('System settings', 'إعدادات النظام');
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
            str_starts_with((string) $log->action, 'mobile.') => $this->tr('Mobile app', 'تطبيق الجوال'),
            str_starts_with((string) $log->action, 'auth.') => $this->tr('Authentication flow', 'مسار المصادقة'),
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
            'status' => $this->tr('Status', 'الحالة'),
            'comment' => $this->tr('Comment', 'تعليق'),
            'phone_e164', 'phone' => $this->tr('Phone', 'الهاتف'),
            'name_en' => $this->tr('English name', 'الاسم بالإنجليزية'),
            'name_ar' => $this->tr('Arabic name', 'الاسم بالعربية'),
            'project_id' => $this->tr('Project ID', 'معرّف المشروع'),
            'municipality_id' => $this->tr('Municipality ID', 'معرّف البلدية'),
            'client_uuid' => $this->tr('Client UUID', 'معرّف العميل'),
            'review_comment' => $this->tr('Review comment', 'تعليق المراجعة'),
            'reviewed_by' => $this->tr('Reviewed by user ID', 'معرّف المستخدم المراجع'),
            'reviewed_at' => $this->tr('Reviewed at', 'تاريخ المراجعة'),
            'submitted_at' => $this->tr('Submitted at', 'تاريخ الإرسال'),
            'validated_at' => $this->tr('Validated at', 'تاريخ التحقق'),
            'validated_by' => $this->tr('Validated by user ID', 'معرّف المستخدم المتحقق'),
            'disabled_at' => $this->tr('Disabled at', 'تاريخ التعطيل'),
            'disabled_reason' => $this->tr('Disable reason', 'سبب التعطيل'),
            'assigned_reporter_ids' => $this->tr('Assigned reporter IDs', 'معرّفات المراسلين المعيّنين'),
            'submission_id' => $this->tr('Submission ID', 'معرّف التقرير'),
            'media_type' => $this->tr('Media type', 'نوع الوسائط'),
            'object_key' => $this->tr('Storage object', 'مسار التخزين'),
            'masked_phone' => $this->tr('Phone', 'الهاتف'),
            'old_status' => $this->tr('Previous status', 'الحالة السابقة'),
            'expires_in' => $this->tr('Link expiry', 'مدة صلاحية الرابط'),
            'size_bytes' => $this->tr('File size', 'حجم الملف'),
            'file_name' => $this->tr('File name', 'اسم الملف'),
            'route_name' => $this->tr('Route name', 'اسم المسار'),
            'request_path', 'path' => $this->tr('Request path', 'مسار الطلب'),
            'request_method', 'method' => $this->tr('Request method', 'طريقة الطلب'),
            default => $this->isArabic() ? $this->arabicizeKey($key) : Str::headline(str_replace('.', ' ', $key)),
        };
    }

    private function formatDisplayValue(mixed $value, string $key = ''): string
    {
        if ($value === null) {
            return $this->tr('Not set', 'غير محدد');
        }

        if (is_bool($value)) {
            return $value ? $this->tr('Yes', 'نعم') : $this->tr('No', 'لا');
        }

        if (is_int($value) || is_float($value)) {
            if ($key === 'size_bytes') {
                return $this->humanFileSize((int) $value);
            }

            if ($key === 'expires_in') {
                return sprintf($this->tr('%d seconds', '%d ثانية'), (int) $value);
            }

            if (str_ends_with($key, '_id')) {
                return '#'.(string) $value;
            }

            return (string) $value;
        }

        if (is_array($value)) {
            if ($value === []) {
                return $this->tr('None', 'لا يوجد');
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

                            return sprintf($this->tr('%d item field(s)', '%d حقلاً'), count($item));
                        }

                        return $this->formatDisplayValue($item);
                    })
                    ->implode(', ');

                return $rendered !== '' ? $rendered : $this->tr('None', 'لا يوجد');
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
            return $this->tr('Not set', 'غير محدد');
        }

        if (str_contains($key, 'phone')) {
            return $stringValue;
        }

        if (in_array($key, ['status', 'old_status', 'role', 'type', 'format', 'source', 'media_type', 'project_status'], true)) {
            return $this->translateKnownValue($key, $stringValue);
        }

        if (str_ends_with($key, '_id') && is_numeric($stringValue)) {
            return '#'.$stringValue;
        }

        if (str_contains($stringValue, '_') && preg_match('/^[a-z0-9_]+$/i', $stringValue) === 1) {
            return $this->translateKnownValue($key, $stringValue);
        }

        return $stringValue;
    }

    private function actorLabel(AuditLog $log): string
    {
        return $log->actor?->name ?: $this->tr('System', 'النظام');
    }

    private function actorRoleLabel(AuditLog $log): string
    {
        return $log->actor?->role
            ? $this->formatDisplayValue($log->actor->role, 'role')
            : $this->tr('System', 'النظام');
    }

    private function entityTypeLabel(?string $entityType): string
    {
        return match ($entityType) {
            'submissions' => $this->tr('Submissions', 'التقارير'),
            'projects' => $this->tr('Projects', 'المشاريع'),
            'users' => $this->tr('Users', 'المستخدمون'),
            'funding_requests' => $this->tr('Funding requests', 'طلبات التمويل'),
            'municipalities' => $this->tr('Municipalities', 'البلديات'),
            'media_assets' => $this->tr('Media assets', 'الوسائط'),
            'export_tasks' => $this->tr('Export tasks', 'مهام التصدير'),
            'settings' => $this->tr('Settings', 'الإعدادات'),
            'permission' => $this->tr('Permissions', 'الصلاحيات'),
            'phone' => $this->tr('Phone verification', 'التحقق من الهاتف'),
            default => $entityType ? ($this->isArabic() ? $this->arabicizeKey($entityType) : Str::headline(str_replace('_', ' ', $entityType))) : $this->tr('System', 'النظام'),
        };
    }

    private function entityTypeSingularLabel(?string $entityType): string
    {
        return match ($entityType) {
            'submissions' => $this->tr('Submission', 'التقرير'),
            'projects' => $this->tr('Project', 'المشروع'),
            'users' => $this->tr('User', 'المستخدم'),
            'funding_requests' => $this->tr('Funding request', 'طلب التمويل'),
            'municipalities' => $this->tr('Municipality', 'البلدية'),
            'media_assets' => $this->tr('Media asset', 'ملف الوسائط'),
            'export_tasks' => $this->tr('Export task', 'مهمة التصدير'),
            'settings' => $this->tr('Setting', 'الإعداد'),
            'permission' => $this->tr('Permission', 'الصلاحية'),
            'phone' => $this->tr('Phone', 'الهاتف'),
            default => $entityType ? ($this->isArabic() ? $this->arabicizeKey(Str::singular($entityType)) : Str::headline(Str::singular(str_replace('_', ' ', $entityType)))) : $this->tr('Record', 'السجل'),
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
            return $this->tr('Unknown device', 'جهاز غير معروف');
        }

        $browser = collect(['Chrome', 'Firefox', 'Safari', 'Edge', 'Opera', 'Brave'])
            ->first(fn (string $item): bool => str_contains($agent, $item)) ?? 'Browser';

        $platform = collect(['Windows', 'macOS', 'Linux', 'Android', 'iPhone', 'iPad'])
            ->first(fn (string $item): bool => str_contains($agent, $item)) ?? 'Device';

        if ($this->isArabic()) {
            $browser = match ($browser) {
                'Chrome' => 'كروم',
                'Firefox' => 'فايرفوكس',
                'Safari' => 'سفاري',
                'Edge' => 'إيدج',
                'Opera' => 'أوبرا',
                'Brave' => 'بريف',
                default => 'متصفح',
            };

            $platform = match ($platform) {
                'Windows' => 'ويندوز',
                'macOS' => 'ماك',
                'Linux' => 'لينكس',
                'Android' => 'أندرويد',
                'iPhone' => 'آيفون',
                'iPad' => 'آيباد',
                default => 'جهاز',
            };
        }

        return $browser.' - '.$platform;
    }

    private function translateKnownValue(string $key, string $value): string
    {
        $normalized = str_replace('.', '_', Str::lower(trim($value)));

        $maps = [
            'status' => [
                'draft' => $this->tr('Draft', 'مسودة'),
                'submitted' => $this->tr('Submitted', 'مرسل'),
                'under_review' => $this->tr('Under Review', 'قيد المراجعة'),
                'approved' => $this->tr('Approved', 'معتمد'),
                'rejected' => $this->tr('Rejected', 'مرفوض'),
                'rework_requested' => $this->tr('Rework Requested', 'طلب إعادة العمل'),
                'pending' => $this->tr('Pending', 'قيد الانتظار'),
                'declined' => $this->tr('Declined', 'مرفوض'),
                'failed' => $this->tr('Failed', 'فشل'),
                'ready' => $this->tr('Ready', 'جاهز'),
                'processing' => $this->tr('Processing', 'قيد المعالجة'),
                'uploaded' => $this->tr('Uploaded', 'تم الرفع'),
                'active' => $this->tr('Active', 'نشط'),
                'disabled' => $this->tr('Disabled', 'معطل'),
                'archived' => $this->tr('Archived', 'مؤرشف'),
                'queued' => $this->tr('Queued', 'في الانتظار'),
                'pending_validation' => $this->tr('Pending Validation', 'بانتظار التحقق'),
                'in_progress' => $this->tr('In Progress', 'قيد التنفيذ'),
                'planned' => $this->tr('Planned', 'مخطط'),
                'completed' => $this->tr('Completed', 'مكتمل'),
            ],
            'old_status' => [],
            'project_status' => [],
            'role' => [
                'reporter' => $this->tr('Reporter', 'مراسل'),
                'municipal_focal_point' => $this->tr('Municipal focal point', 'نقطة اتصال بلدية'),
                'undp_admin' => $this->tr('UNDP admin', 'مسؤول UNDP'),
                'partner_donor_viewer' => $this->tr('Partner donor viewer', 'جهة مانحة / شريك'),
                'auditor' => $this->tr('Auditor', 'مدقق'),
            ],
            'type' => [
                'audit_logs' => $this->tr('Audit logs', 'سجلات التدقيق'),
                'submissions' => $this->tr('Submissions', 'التقارير'),
                'users' => $this->tr('Users', 'المستخدمون'),
                'summary' => $this->tr('Summary', 'الملخص'),
            ],
            'format' => [
                'csv' => 'CSV',
                'pdf' => 'PDF',
            ],
            'source' => [
                'mobile' => $this->tr('Mobile', 'الجوال'),
            ],
            'media_type' => [
                'image' => $this->tr('Image', 'صورة'),
                'video' => $this->tr('Video', 'فيديو'),
            ],
        ];

        if (in_array($key, ['old_status', 'project_status'], true)) {
            $key = 'status';
        }

        if (isset($maps[$key][$normalized])) {
            return $maps[$key][$normalized];
        }

        return $this->isArabic() ? $this->arabicizeKey($normalized) : Str::headline($normalized);
    }

    private function humanizeUnknownAction(string $action): string
    {
        $label = Str::headline(str_replace('.', ' ', Str::afterLast($action, '.')));

        return $this->isArabic() ? $this->arabicizeKey($label) : $label;
    }

    private function arabicizeKey(string $value): string
    {
        return str_replace('_', ' ', trim($value));
    }

    private function tr(string $english, string $arabic): string
    {
        return $this->isArabic() ? $arabic : $english;
    }

    private function isArabic(): bool
    {
        return app()->getLocale() === 'ar';
    }
}
