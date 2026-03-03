<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    protected $fillable = [
        'general',
        'users_roles',
        'workflow',
        'security',
    ];

    protected function casts(): array
    {
        return [
            'general' => 'array',
            'users_roles' => 'array',
            'workflow' => 'array',
            'security' => 'array',
        ];
    }

    public static function singleton(): self
    {
        return static::query()->first() ?? static::query()->create(static::defaults());
    }

    public static function defaults(): array
    {
        return [
            'general' => [
                'organization_name' => 'UNDP Libya - Southern Region Monitoring Unit',
                'organization_type' => 'International Development Agency',
                'system_environment' => 'Production',
                'primary_contact_email' => 'monitoring@undp-libya.org',
                'support_contact_email' => 'support@undp-libya.org',
                'default_language' => 'English',
                'default_timezone' => 'UTC +2 (Tripoli)',
                'default_date_format' => 'DD MMM YYYY (e.g., 12 July 2026)',
                'default_currency' => 'USD (United States Dollar)',
                'default_reporting_cycle' => 'Weekly',
                'project_statuses' => ['Planned', 'In Progress', 'Not Yet Started', 'Completed'],
                'risk_levels' => ['Low', 'Medium', 'High'],
                'default_progress_format' => 'Percentage (0-100%)',
                'submission_retention' => '5 Years',
                'audit_retention' => '5 Years',
                'archived_visibility' => 'Admin Only',
                'auto_archive_after' => '12 Months of inactivity',
            ],
            'users_roles' => [
                'role_definitions' => [
                    [
                        'name' => 'Super Admin',
                        'items' => [
                            ['label' => 'Manage all users', 'enabled' => true],
                            ['label' => 'Edit global settings', 'enabled' => true],
                            ['label' => 'Access audit logs', 'enabled' => true],
                            ['label' => 'Override workflow decisions', 'enabled' => true],
                        ],
                    ],
                    [
                        'name' => 'UNDP Admin',
                        'items' => [
                            ['label' => 'Review and validate submissions', 'enabled' => true],
                            ['label' => 'Request rework', 'enabled' => true],
                            ['label' => 'Approve or reject reports', 'enabled' => true],
                            ['label' => 'View analytics dashboard', 'enabled' => true],
                            ['label' => 'Access export & reporting tools', 'enabled' => true],
                        ],
                    ],
                    [
                        'name' => 'Municipal Validator',
                        'items' => [
                            ['label' => 'Review submissions within assigned scope', 'enabled' => true],
                            ['label' => 'Add comments', 'enabled' => true],
                            ['label' => 'Approve or request rework', 'enabled' => true],
                            ['label' => 'View project details (restricted)', 'enabled' => true],
                        ],
                    ],
                    [
                        'name' => 'Reporter (Community Agent)',
                        'items' => [
                            ['label' => 'Create and submit monitoring reports', 'enabled' => true],
                            ['label' => 'Upload media attachments', 'enabled' => true],
                            ['label' => 'View own submission history', 'enabled' => true],
                            ['label' => 'Respond to rework requests', 'enabled' => true],
                        ],
                    ],
                    [
                        'name' => 'Donor Observer',
                        'items' => [
                            ['label' => 'View project dashboards', 'enabled' => true],
                            ['label' => 'View approved submissions only', 'enabled' => true],
                            ['label' => 'Download reports', 'enabled' => true],
                            ['label' => 'No edit permissions', 'enabled' => true],
                        ],
                    ],
                ],
                'permission_matrix' => [
                    ['name' => 'Create Submission', 'reporter' => true, 'validator' => true, 'admin' => true, 'super_admin' => true],
                    ['name' => 'Edit Before Approval', 'reporter' => true, 'validator' => true, 'admin' => true, 'super_admin' => true],
                    ['name' => 'Approve / Reject', 'reporter' => false, 'validator' => true, 'admin' => true, 'super_admin' => true],
                    ['name' => 'Manage Users', 'reporter' => false, 'validator' => false, 'admin' => true, 'super_admin' => true],
                    ['name' => 'Access Audit Log', 'reporter' => false, 'validator' => true, 'admin' => true, 'super_admin' => true],
                ],
            ],
            'workflow' => [
                'workflow_mode' => 'standard',
                'default_submission_status' => 'Submitted',
                'auto_status_rule' => 'approved',
                'approval_requirement' => 'validator',
                'escalation_days' => 5,
                'escalation_enabled' => false,
                'comment_for_rework' => false,
                'comment_for_rejection' => true,
                'reporting_frequency' => 'Weekly',
                'deadline_day' => 'Day',
                'deadline_time' => 'Time',
                'deadline_timezone' => 'Timezone',
                'late_mark' => false,
                'late_notify_admin' => false,
                'late_notify_reporter' => false,
                'minimum_attachments' => 2,
                'require_photo' => true,
                'require_video' => false,
                'require_location_tag' => false,
                'allowed_jpg' => true,
                'allowed_png' => false,
                'allowed_mp4' => false,
                'allowed_pdf' => false,
                'risk_reporting_required' => false,
                'auto_flag_high_priority' => true,
                'notify_admin_immediately' => true,
                'require_additional_comment' => true,
                'email_on_new_submission' => true,
                'email_on_approval' => true,
                'email_on_rework' => true,
                'email_on_rejection' => true,
                'in_app_notifications' => false,
                'audit_track_status' => true,
                'audit_log_approval' => true,
                'audit_timestamp_actions' => true,
            ],
            'security' => [
                'require_2fa_admin' => true,
                'enable_sso' => false,
                'minimum_length' => 5,
                'require_uppercase' => false,
                'require_numbers' => false,
                'require_special_chars' => false,
                'password_expiry_days' => 90,
                'rbac_enabled' => true,
                'ip_restriction_admin' => true,
                'log_login_activity' => true,
                'log_data_exports' => true,
            ],
        ];
    }

    public function payload(): array
    {
        $defaults = static::defaults();

        return [
            'general' => array_replace_recursive($defaults['general'], $this->general ?? []),
            'users_roles' => array_replace_recursive($defaults['users_roles'], $this->users_roles ?? []),
            'workflow' => array_replace_recursive($defaults['workflow'], $this->workflow ?? []),
            'security' => array_replace_recursive($defaults['security'], $this->security ?? []),
        ];
    }
}
