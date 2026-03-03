<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function show(): JsonResponse
    {
        $settings = SystemSetting::singleton();

        return response()->json([
            'settings' => $settings->payload(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());

        $settings = SystemSetting::singleton();
        $before = $settings->payload();

        foreach (['general', 'users_roles', 'workflow', 'security'] as $section) {
            if (array_key_exists($section, $validated)) {
                $settings->{$section} = $validated[$section];
            }
        }

        if (! $settings->isDirty()) {
            return response()->json([
                'message' => __('No settings changes detected.'),
                'settings' => $before,
            ]);
        }

        $settings->save();
        $after = $settings->payload();

        AuditLogger::log(
            action: 'settings.updated',
            entityType: 'settings',
            entityId: 'global',
            before: $before,
            after: $after,
            request: $request,
        );

        return response()->json([
            'message' => __('Settings saved successfully.'),
            'settings' => $after,
        ]);
    }

    private function rules(): array
    {
        return [
            'general' => ['sometimes', 'array'],
            'general.organization_name' => ['required_with:general', 'string', 'max:255'],
            'general.organization_type' => ['required_with:general', 'string', 'max:255'],
            'general.system_environment' => ['required_with:general', 'string', 'max:120'],
            'general.primary_contact_email' => ['required_with:general', 'email', 'max:255'],
            'general.support_contact_email' => ['required_with:general', 'email', 'max:255'],
            'general.default_language' => ['required_with:general', 'string', 'max:50'],
            'general.default_timezone' => ['required_with:general', 'string', 'max:120'],
            'general.default_date_format' => ['required_with:general', 'string', 'max:120'],
            'general.default_currency' => ['required_with:general', 'string', 'max:120'],
            'general.default_reporting_cycle' => ['required_with:general', 'string', 'max:60'],
            'general.project_statuses' => ['required_with:general', 'array', 'min:1'],
            'general.project_statuses.*' => ['required', 'string', 'max:80'],
            'general.risk_levels' => ['required_with:general', 'array', 'min:1'],
            'general.risk_levels.*' => ['required', 'string', 'max:80'],
            'general.default_progress_format' => ['required_with:general', 'string', 'max:120'],
            'general.submission_retention' => ['required_with:general', 'string', 'max:80'],
            'general.audit_retention' => ['required_with:general', 'string', 'max:80'],
            'general.archived_visibility' => ['required_with:general', 'string', 'max:80'],
            'general.auto_archive_after' => ['required_with:general', 'string', 'max:120'],

            'users_roles' => ['sometimes', 'array'],
            'users_roles.role_definitions' => ['required_with:users_roles', 'array', 'min:1'],
            'users_roles.role_definitions.*.name' => ['required', 'string', 'max:120'],
            'users_roles.role_definitions.*.items' => ['required', 'array', 'min:1'],
            'users_roles.role_definitions.*.items.*.label' => ['required', 'string', 'max:255'],
            'users_roles.role_definitions.*.items.*.enabled' => ['required', 'boolean'],
            'users_roles.permission_matrix' => ['required_with:users_roles', 'array', 'min:1'],
            'users_roles.permission_matrix.*.name' => ['required', 'string', 'max:255'],
            'users_roles.permission_matrix.*.reporter' => ['required', 'boolean'],
            'users_roles.permission_matrix.*.validator' => ['required', 'boolean'],
            'users_roles.permission_matrix.*.admin' => ['required', 'boolean'],
            'users_roles.permission_matrix.*.super_admin' => ['required', 'boolean'],

            'workflow' => ['sometimes', 'array'],
            'workflow.workflow_mode' => ['required_with:workflow', 'string', 'max:50'],
            'workflow.default_submission_status' => ['required_with:workflow', 'string', 'max:60'],
            'workflow.auto_status_rule' => ['required_with:workflow', 'string', 'max:50'],
            'workflow.approval_requirement' => ['required_with:workflow', 'string', 'max:50'],
            'workflow.escalation_days' => ['required_with:workflow', 'integer', 'min:0', 'max:365'],
            'workflow.escalation_enabled' => ['required_with:workflow', 'boolean'],
            'workflow.comment_for_rework' => ['required_with:workflow', 'boolean'],
            'workflow.comment_for_rejection' => ['required_with:workflow', 'boolean'],
            'workflow.reporting_frequency' => ['required_with:workflow', 'string', 'max:50'],
            'workflow.deadline_day' => ['required_with:workflow', 'string', 'max:40'],
            'workflow.deadline_time' => ['required_with:workflow', 'string', 'max:40'],
            'workflow.deadline_timezone' => ['required_with:workflow', 'string', 'max:80'],
            'workflow.late_mark' => ['required_with:workflow', 'boolean'],
            'workflow.late_notify_admin' => ['required_with:workflow', 'boolean'],
            'workflow.late_notify_reporter' => ['required_with:workflow', 'boolean'],
            'workflow.minimum_attachments' => ['required_with:workflow', 'integer', 'min:0', 'max:50'],
            'workflow.require_photo' => ['required_with:workflow', 'boolean'],
            'workflow.require_video' => ['required_with:workflow', 'boolean'],
            'workflow.require_location_tag' => ['required_with:workflow', 'boolean'],
            'workflow.allowed_jpg' => ['required_with:workflow', 'boolean'],
            'workflow.allowed_png' => ['required_with:workflow', 'boolean'],
            'workflow.allowed_mp4' => ['required_with:workflow', 'boolean'],
            'workflow.allowed_pdf' => ['required_with:workflow', 'boolean'],
            'workflow.risk_reporting_required' => ['required_with:workflow', 'boolean'],
            'workflow.auto_flag_high_priority' => ['required_with:workflow', 'boolean'],
            'workflow.notify_admin_immediately' => ['required_with:workflow', 'boolean'],
            'workflow.require_additional_comment' => ['required_with:workflow', 'boolean'],
            'workflow.email_on_new_submission' => ['required_with:workflow', 'boolean'],
            'workflow.email_on_approval' => ['required_with:workflow', 'boolean'],
            'workflow.email_on_rework' => ['required_with:workflow', 'boolean'],
            'workflow.email_on_rejection' => ['required_with:workflow', 'boolean'],
            'workflow.in_app_notifications' => ['required_with:workflow', 'boolean'],
            'workflow.audit_track_status' => ['required_with:workflow', 'boolean'],
            'workflow.audit_log_approval' => ['required_with:workflow', 'boolean'],
            'workflow.audit_timestamp_actions' => ['required_with:workflow', 'boolean'],

            'security' => ['sometimes', 'array'],
            'security.require_2fa_admin' => ['required_with:security', 'boolean'],
            'security.enable_sso' => ['required_with:security', 'boolean'],
            'security.minimum_length' => ['required_with:security', 'integer', 'min:4', 'max:64'],
            'security.require_uppercase' => ['required_with:security', 'boolean'],
            'security.require_numbers' => ['required_with:security', 'boolean'],
            'security.require_special_chars' => ['required_with:security', 'boolean'],
            'security.password_expiry_days' => ['required_with:security', 'integer', 'min:1', 'max:365'],
            'security.rbac_enabled' => ['required_with:security', 'boolean'],
            'security.ip_restriction_admin' => ['required_with:security', 'boolean'],
            'security.log_login_activity' => ['required_with:security', 'boolean'],
            'security.log_data_exports' => ['required_with:security', 'boolean'],
        ];
    }
}
