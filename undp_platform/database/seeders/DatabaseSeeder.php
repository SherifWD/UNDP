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
                'mobile_meta' => [
                    'code' => 'PRJ-TRI-001',
                    'goal_area' => 'Central District',
                    'location_label' => 'South Region - Tripoli',
                    'component_category' => 'Hard Component - Infrastructure',
                    'donors' => ['UNDP Libya', 'European Union', 'Government of Italy'],
                    'program_lead' => 'UNDP Libya',
                    'duration_months' => 3,
                    'implemented_by' => 'Tripoli Municipality',
                    'contacts' => ['+218 91 554 88 441'],
                    'execution_status' => 'in_progress',
                    'progress_percent' => 55,
                    'is_invited' => true,
                    'expected_end_date' => now()->addMonths(9)->endOfMonth()->toDateString(),
                ],
            ],
            [
                'municipality_id' => $benghazi->id,
                'name_en' => 'School Rehabilitation',
                'name_ar' => 'تأهيل المدارس',
                'description' => 'Infrastructure upgrades for public schools.',
                'latitude' => 32.1167,
                'longitude' => 20.0667,
                'mobile_meta' => [
                    'code' => 'PRJ-BEN-002',
                    'goal_area' => 'North Sector',
                    'location_label' => 'South Region - Benghazi',
                    'component_category' => 'Hard Component - Infrastructure',
                    'donors' => ['UNDP Libya', 'Government of Italy'],
                    'program_lead' => 'UNDP Libya',
                    'duration_months' => 4,
                    'implemented_by' => 'Benghazi Municipality',
                    'contacts' => ['+218 91 554 88 442'],
                    'execution_status' => 'completed',
                    'progress_percent' => 66,
                    'is_invited' => true,
                    'expected_end_date' => now()->addMonths(6)->endOfMonth()->toDateString(),
                ],
            ],
            [
                'municipality_id' => $misrata->id,
                'name_en' => 'Primary Healthcare Clinics',
                'name_ar' => 'عيادات الرعاية الأولية',
                'description' => 'Equipment and staffing for local clinics.',
                'latitude' => 32.3754,
                'longitude' => 15.0925,
                'mobile_meta' => [
                    'code' => 'PRJ-MIS-003',
                    'goal_area' => 'Service Zone',
                    'location_label' => 'South Region - Misrata',
                    'component_category' => 'Service Delivery',
                    'donors' => ['UNDP Libya'],
                    'program_lead' => 'UNDP Libya',
                    'duration_months' => 6,
                    'implemented_by' => 'Misrata Municipality',
                    'contacts' => ['+218 91 554 88 443'],
                    'execution_status' => 'planned',
                    'progress_percent' => 0,
                    'is_invited' => false,
                    'expected_end_date' => now()->addMonths(12)->endOfMonth()->toDateString(),
                ],
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
            'gender' => 'male',
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

        $focals = collect([
            $this->createUser([
                'name' => 'Municipal Focal Point - Tripoli',
                'email' => 'focal.tripoli@undp.local',
                'country_code' => '+218',
                'phone' => '910000003',
                'phone_e164' => '+218910000003',
                'role' => UserRole::MUNICIPAL_FOCAL_POINT->value,
                'gender' => 'male',
                'municipality_id' => $tripoli->id,
                'preferred_locale' => 'ar',
            ]),
            $this->createUser([
                'name' => 'Municipal Focal Point - Benghazi',
                'email' => 'focal.benghazi@undp.local',
                'country_code' => '+218',
                'phone' => '910000005',
                'phone_e164' => '+218910000005',
                'role' => UserRole::MUNICIPAL_FOCAL_POINT->value,
                'gender' => 'female',
                'municipality_id' => $benghazi->id,
                'preferred_locale' => 'en',
            ]),
            $this->createUser([
                'name' => 'Municipal Focal Point - Misrata',
                'email' => 'focal.misrata@undp.local',
                'country_code' => '+218',
                'phone' => '910000006',
                'phone_e164' => '+218910000006',
                'role' => UserRole::MUNICIPAL_FOCAL_POINT->value,
                'gender' => 'male',
                'municipality_id' => $misrata->id,
                'preferred_locale' => 'ar',
            ]),
        ]);

        $partner = $this->createUser([
            'name' => 'Partner Donor Viewer',
            'email' => 'partner@undp.local',
            'country_code' => '+218',
            'phone' => '910000004',
            'phone_e164' => '+218910000004',
            'role' => UserRole::PARTNER_DONOR_VIEWER->value,
            'gender' => 'male',
            'preferred_locale' => 'en',
        ]);

        $reporters = collect([
            $this->createUser([
                'name' => 'Community Reporter - Tripoli',
                'email' => 'reporter.tripoli@undp.local',
                'country_code' => '+218',
                'phone' => '910000101',
                'phone_e164' => '+218910000101',
                'role' => UserRole::REPORTER->value,
                'gender' => 'male',
                'municipality_id' => $tripoli->id,
                'preferred_locale' => 'ar',
            ]),
            $this->createUser([
                'name' => 'Community Reporter - Benghazi',
                'email' => 'reporter.benghazi@undp.local',
                'country_code' => '+218',
                'phone' => '910000102',
                'phone_e164' => '+218910000102',
                'role' => UserRole::REPORTER->value,
                'gender' => 'female',
                'municipality_id' => $benghazi->id,
                'preferred_locale' => 'en',
            ]),
            $this->createUser([
                'name' => 'Community Reporter - Misrata',
                'email' => 'reporter.misrata@undp.local',
                'country_code' => '+218',
                'phone' => '910000103',
                'phone_e164' => '+218910000103',
                'role' => UserRole::REPORTER->value,
                'gender' => 'male',
                'municipality_id' => $misrata->id,
                'preferred_locale' => 'ar',
            ]),
        ]);

        $reportersByMunicipality = $reporters->groupBy('municipality_id');
        $focalsByMunicipality = $focals->keyBy('municipality_id');

        $this->printSeededRoleAccounts([
            'UNDP Admin' => $admin,
            'Auditor' => $auditor,
            'Municipal Focal Point (Tripoli)' => $focals[0],
            'Municipal Focal Point (Benghazi)' => $focals[1],
            'Municipal Focal Point (Misrata)' => $focals[2],
            'Partner/Donor Viewer' => $partner,
            'Community Reporter (Tripoli)' => $reporters[0],
            'Community Reporter (Benghazi)' => $reporters[1],
            'Community Reporter (Misrata)' => $reporters[2],
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
            $reporter = $reportersByMunicipality
                ->get($project->municipality_id, collect([$reporters->random()]))
                ->random();
            $validator = $focalsByMunicipality->get($project->municipality_id, $admin);
            $status = $statuses[array_rand($statuses)];
            $createdAt = now()->subDays(random_int(0, 25))->subHours(random_int(0, 23));
            $submittedAt = in_array($status, [SubmissionStatus::DRAFT, SubmissionStatus::QUEUED], true)
                ? null
                : $createdAt->copy()->addMinutes(random_int(10, 180));
            $validatedAt = in_array($status, [SubmissionStatus::APPROVED, SubmissionStatus::REWORK_REQUESTED, SubmissionStatus::REJECTED], true)
                ? ($submittedAt?->copy()->addHours(random_int(1, 48)) ?? $createdAt->copy()->addHours(random_int(1, 48)))
                : null;
            $validationComment = match ($status) {
                SubmissionStatus::REJECTED => 'Missing evidence attachments.',
                SubmissionStatus::REWORK_REQUESTED => 'Please add location and attachment details.',
                default => null,
            };
            $updatedAt = $validatedAt ?? ($submittedAt?->copy()->addHours(random_int(1, 24)) ?? $createdAt->copy()->addHours(random_int(1, 24)));

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
                'submitted_at' => $submittedAt,
                'validated_by' => in_array($status, [SubmissionStatus::APPROVED, SubmissionStatus::REWORK_REQUESTED, SubmissionStatus::REJECTED], true) ? $validator->id : null,
                'validated_at' => $validatedAt,
                'validation_comment' => $validationComment,
                'created_at' => $createdAt,
                'updated_at' => $updatedAt,
            ]);

            $this->createSeedStatusEvents($submission, $reporter, $validator);
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

    private function createSeedStatusEvents(Submission $submission, User $reporter, User $validator): void
    {
        $status = SubmissionStatus::from($submission->status);
        $createdAt = $submission->created_at ?? now();
        $submittedAt = $submission->submitted_at ?? $createdAt->copy()->addMinutes(15);
        $underReviewAt = $submittedAt->copy()->addMinutes(30);
        $decisionAt = $submission->validated_at ?? $submission->updated_at ?? $underReviewAt->copy()->addHours(2);

        if ($status === SubmissionStatus::DRAFT) {
            SubmissionStatusEvent::create([
                'submission_id' => $submission->id,
                'actor_id' => $reporter->id,
                'from_status' => null,
                'to_status' => SubmissionStatus::DRAFT->value,
                'comment' => 'Saved as draft.',
                'created_at' => $createdAt,
            ]);

            return;
        }

        if ($status === SubmissionStatus::QUEUED) {
            SubmissionStatusEvent::create([
                'submission_id' => $submission->id,
                'actor_id' => $reporter->id,
                'from_status' => null,
                'to_status' => SubmissionStatus::QUEUED->value,
                'comment' => 'Queued while offline.',
                'created_at' => $createdAt,
            ]);

            return;
        }

        SubmissionStatusEvent::create([
            'submission_id' => $submission->id,
            'actor_id' => $reporter->id,
            'from_status' => null,
            'to_status' => SubmissionStatus::SUBMITTED->value,
            'comment' => 'Initial submission.',
            'created_at' => $submittedAt,
        ]);

        if ($status === SubmissionStatus::SUBMITTED) {
            return;
        }

        SubmissionStatusEvent::create([
            'submission_id' => $submission->id,
            'actor_id' => $validator->id,
            'from_status' => SubmissionStatus::SUBMITTED->value,
            'to_status' => SubmissionStatus::UNDER_REVIEW->value,
            'comment' => 'Submission entered validation queue.',
            'created_at' => $underReviewAt,
        ]);

        if ($status === SubmissionStatus::UNDER_REVIEW) {
            return;
        }

        SubmissionStatusEvent::create([
            'submission_id' => $submission->id,
            'actor_id' => $validator->id,
            'from_status' => SubmissionStatus::UNDER_REVIEW->value,
            'to_status' => $status->value,
            'comment' => $submission->validation_comment,
            'created_at' => $decisionAt,
        ]);
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
