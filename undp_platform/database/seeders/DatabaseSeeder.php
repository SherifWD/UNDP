<?php

namespace Database\Seeders;

use App\Enums\SubmissionStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Municipality;
use App\Models\Project;
use App\Models\Submission;
use App\Models\SubmissionStatusEvent;
use App\Models\User;
use App\Models\ValidationReason;
use App\Models\WorkflowStatus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RbacSeeder::class);

        $this->seedWorkflowLookups();

        $tripoli = Municipality::updateOrCreate(
            ['code' => 'TRI'],
            [
                'name_en' => 'Tripoli',
                'name_ar' => 'طرابلس',
            ],
        );

        $benghazi = Municipality::updateOrCreate(
            ['code' => 'BEN'],
            [
                'name_en' => 'Benghazi',
                'name_ar' => 'بنغازي',
            ],
        );

        $misrata = Municipality::updateOrCreate(
            ['code' => 'MIS'],
            [
                'name_en' => 'Misrata',
                'name_ar' => 'مصراتة',
            ],
        );

        $projects = collect([
            [
                'municipality_id' => $tripoli->id,
                'name_en' => 'Urban Water Network',
                'name_ar' => 'شبكة المياه الحضرية',
                'description' => 'Water network rehabilitation across central districts.',
                'latitude' => 32.8872,
                'longitude' => 13.1913,
            ],
            [
                'municipality_id' => $benghazi->id,
                'name_en' => 'School Rehabilitation',
                'name_ar' => 'تأهيل المدارس',
                'description' => 'Infrastructure upgrades for public schools.',
                'latitude' => 32.1167,
                'longitude' => 20.0667,
            ],
            [
                'municipality_id' => $misrata->id,
                'name_en' => 'Primary Healthcare Clinics',
                'name_ar' => 'عيادات الرعاية الأولية',
                'description' => 'Equipment and staffing for local clinics.',
                'latitude' => 32.3754,
                'longitude' => 15.0925,
            ],
        ])->map(fn (array $project): Project => Project::updateOrCreate(
            [
                'municipality_id' => $project['municipality_id'],
                'name_en' => $project['name_en'],
            ],
            [
                ...$project,
                'status' => 'active',
                'last_update_at' => now()->subDays(random_int(1, 20)),
            ],
        ));

        $admin = $this->createUser([
            'name' => 'UNDP Admin',
            'email' => 'admin@undp.local',
            'country_code' => '+218',
            'phone' => '910000001',
            'phone_e164' => '+218910000001',
            'role' => UserRole::UNDP_ADMIN->value,
            'preferred_locale' => 'en',
        ]);

        $auditor = $this->createUser([
            'name' => 'Audit Officer',
            'email' => 'auditor@undp.local',
            'country_code' => '+218',
            'phone' => '910000002',
            'phone_e164' => '+218910000002',
            'role' => UserRole::AUDITOR->value,
            'preferred_locale' => 'en',
        ]);

        $focal = $this->createUser([
            'name' => 'Municipal Focal Point',
            'email' => 'focal.tripoli@undp.local',
            'country_code' => '+218',
            'phone' => '910000003',
            'phone_e164' => '+218910000003',
            'role' => UserRole::MUNICIPAL_FOCAL_POINT->value,
            'municipality_id' => $tripoli->id,
            'preferred_locale' => 'ar',
        ]);

        $partner = $this->createUser([
            'name' => 'Partner Donor Viewer',
            'email' => 'partner@undp.local',
            'country_code' => '+218',
            'phone' => '910000004',
            'phone_e164' => '+218910000004',
            'role' => UserRole::PARTNER_DONOR_VIEWER->value,
            'preferred_locale' => 'en',
        ]);

        $reporters = collect([
            $this->createUser([
                'name' => 'Community Reporter - Tripoli',
                'email' => 'reporter1@undp.local',
                'country_code' => '+218',
                'phone' => '910000101',
                'phone_e164' => '+218910000101',
                'role' => UserRole::REPORTER->value,
                'municipality_id' => $tripoli->id,
                'preferred_locale' => 'ar',
            ]),
            $this->createUser([
                'name' => 'Community Reporter - Benghazi',
                'email' => 'reporter2@undp.local',
                'country_code' => '+218',
                'phone' => '910000102',
                'phone_e164' => '+218910000102',
                'role' => UserRole::REPORTER->value,
                'municipality_id' => $benghazi->id,
                'preferred_locale' => 'en',
            ]),
        ]);

        $this->printSeededRoleAccounts([
            'UNDP Admin' => $admin,
            'Auditor' => $auditor,
            'Municipal Focal Point' => $focal,
            'Partner/Donor Viewer' => $partner,
            'Community Reporter (Tripoli)' => $reporters[0],
            'Community Reporter (Benghazi)' => $reporters[1],
        ]);

        $statuses = [
            SubmissionStatus::UNDER_REVIEW,
            SubmissionStatus::APPROVED,
            SubmissionStatus::REWORK_REQUESTED,
            SubmissionStatus::REJECTED,
            SubmissionStatus::SUBMITTED,
            SubmissionStatus::DRAFT,
        ];

        foreach (range(1, 28) as $index) {
            $project = $projects->random();
            $reporter = $reporters->random();
            $status = $statuses[array_rand($statuses)];

            $submission = Submission::create([
                'client_uuid' => (string) str()->uuid(),
                'reporter_id' => $reporter->id,
                'project_id' => $project->id,
                'municipality_id' => $project->municipality_id,
                'status' => $status->value,
                'title' => "Field update {$index}",
                'details' => 'Progress update with photos and verification notes.',
                'data' => [
                    'beneficiaries' => random_int(10, 300),
                    'progress_percent' => random_int(10, 100),
                ],
                'media' => [],
                'latitude' => $project->latitude,
                'longitude' => $project->longitude,
                'submitted_at' => now()->subDays(random_int(0, 20)),
                'validated_by' => in_array($status, [SubmissionStatus::APPROVED, SubmissionStatus::REWORK_REQUESTED, SubmissionStatus::REJECTED], true) ? $focal->id : null,
                'validated_at' => in_array($status, [SubmissionStatus::APPROVED, SubmissionStatus::REWORK_REQUESTED, SubmissionStatus::REJECTED], true) ? now()->subDays(random_int(0, 10)) : null,
                'validation_comment' => $status === SubmissionStatus::REJECTED ? 'Missing evidence attachments.' : null,
                'created_at' => now()->subDays(random_int(0, 25)),
                'updated_at' => now()->subDays(random_int(0, 10)),
            ]);

            SubmissionStatusEvent::create([
                'submission_id' => $submission->id,
                'actor_id' => $reporter->id,
                'from_status' => null,
                'to_status' => SubmissionStatus::SUBMITTED->value,
                'comment' => 'Initial submission.',
                'created_at' => $submission->created_at,
            ]);

            if ($submission->status !== SubmissionStatus::SUBMITTED->value) {
                SubmissionStatusEvent::create([
                    'submission_id' => $submission->id,
                    'actor_id' => $submission->validated_by ?? $admin->id,
                    'from_status' => SubmissionStatus::SUBMITTED->value,
                    'to_status' => $submission->status,
                    'comment' => $submission->validation_comment,
                    'created_at' => $submission->updated_at,
                ]);
            }
        }
    }

    private function seedWorkflowLookups(): void
    {
        $statuses = [
            ['code' => SubmissionStatus::DRAFT->value, 'label_en' => 'Draft', 'label_ar' => 'مسودة', 'is_terminal' => false, 'sort_order' => 1],
            ['code' => SubmissionStatus::QUEUED->value, 'label_en' => 'Queued', 'label_ar' => 'قيد الانتظار', 'is_terminal' => false, 'sort_order' => 2],
            ['code' => SubmissionStatus::SUBMITTED->value, 'label_en' => 'Submitted', 'label_ar' => 'تم الإرسال', 'is_terminal' => false, 'sort_order' => 3],
            ['code' => SubmissionStatus::UNDER_REVIEW->value, 'label_en' => 'Under Review', 'label_ar' => 'قيد المراجعة', 'is_terminal' => false, 'sort_order' => 4],
            ['code' => SubmissionStatus::APPROVED->value, 'label_en' => 'Approved', 'label_ar' => 'معتمد', 'is_terminal' => true, 'sort_order' => 5],
            ['code' => SubmissionStatus::REWORK_REQUESTED->value, 'label_en' => 'Rework Requested', 'label_ar' => 'مطلوب إعادة العمل', 'is_terminal' => false, 'sort_order' => 6],
            ['code' => SubmissionStatus::REJECTED->value, 'label_en' => 'Rejected', 'label_ar' => 'مرفوض', 'is_terminal' => true, 'sort_order' => 7],
        ];

        foreach ($statuses as $status) {
            WorkflowStatus::updateOrCreate(['code' => $status['code']], $status + ['is_active' => true]);
        }

        $reasons = [
            ['code' => 'MISSING_MEDIA', 'action' => 'reject', 'label_en' => 'Missing evidence media', 'label_ar' => 'نقص في الوسائط الداعمة', 'sort_order' => 1],
            ['code' => 'INVALID_DATA', 'action' => 'reject', 'label_en' => 'Invalid or conflicting data', 'label_ar' => 'بيانات غير صحيحة أو متضاربة', 'sort_order' => 2],
            ['code' => 'NEED_MORE_DETAIL', 'action' => 'rework', 'label_en' => 'Please add more details', 'label_ar' => 'يرجى إضافة تفاصيل أكثر', 'sort_order' => 1],
            ['code' => 'LOCATION_CLARIFICATION', 'action' => 'rework', 'label_en' => 'Location requires clarification', 'label_ar' => 'الموقع يحتاج توضيح', 'sort_order' => 2],
        ];

        foreach ($reasons as $reason) {
            ValidationReason::updateOrCreate(['code' => $reason['code']], $reason + ['is_active' => true]);
        }
    }

    private function createUser(array $attributes): User
    {
        $defaults = [
            ...$attributes,
            'password' => Hash::make('password'),
            'status' => UserStatus::ACTIVE->value,
        ];

        $user = User::updateOrCreate(
            ['phone_e164' => $attributes['phone_e164']],
            $defaults,
        );

        $user->syncRoleSafely($user->role);

        return $user;
    }

    private function printSeededRoleAccounts(array $accounts): void
    {
        if (! $this->command) {
            return;
        }

        $this->command->info('Seeded role accounts (OTP login via phone):');

        foreach ($accounts as $label => $user) {
            $this->command->line(sprintf('- %s: %s', $label, $user->phone_e164));
        }
    }
}
