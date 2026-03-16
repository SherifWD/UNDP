<?php

namespace Tests\Feature;

use App\Enums\SubmissionStatus;
use App\Enums\UserRole;
use App\Models\MediaAsset;
use App\Models\Municipality;
use App\Models\Project;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_reporter_can_load_mobile_home_and_projects(): void
    {
        $municipality = Municipality::query()->create([
            'name_en' => 'Alkufraa',
            'name_ar' => 'الكفرة',
            'code' => 'ALK',
        ]);

        $invitedProject = Project::query()->create([
            'municipality_id' => $municipality->id,
            'name_en' => 'Alkufraa Police Station Project',
            'name_ar' => 'مشروع مركز شرطة الكفرة',
            'status' => 'active',
            'latitude' => 23.3112,
            'longitude' => 21.8569,
            'last_update_at' => now(),
            'mobile_meta' => [
                'code' => 'PRJ-ALK-001',
                'goal_area' => 'Central District',
                'location_label' => 'South Region - Alkufraa',
                'execution_status' => 'in_progress',
                'progress_percent' => 55,
                'funding_budget' => 1200000,
                'funding_sources' => ['UNDP Libya', 'European Union'],
                'is_invited' => true,
            ],
        ]);

        Project::query()->create([
            'municipality_id' => $municipality->id,
            'name_en' => 'Community Health Center',
            'name_ar' => 'مركز الصحة المجتمعية',
            'status' => 'active',
            'latitude' => 23.3001,
            'longitude' => 21.8601,
            'last_update_at' => now()->subDay(),
            'mobile_meta' => [
                'code' => 'PRJ-ALK-002',
                'goal_area' => 'West Sector',
                'location_label' => 'South Region - Alkufraa',
                'execution_status' => 'planned',
                'progress_percent' => 0,
                'is_invited' => false,
            ],
        ]);

        $reporter = User::factory()->create([
            'role' => UserRole::REPORTER->value,
            'municipality_id' => $municipality->id,
            'status' => 'active',
        ]);

        $invitedProject->assignedReporters()->sync([
            $reporter->id => ['assigned_by' => $reporter->id],
        ]);

        Submission::query()->create([
            'reporter_id' => $reporter->id,
            'project_id' => $invitedProject->id,
            'municipality_id' => $municipality->id,
            'status' => SubmissionStatus::APPROVED->value,
            'title' => 'Approved update',
        ]);

        Submission::query()->create([
            'reporter_id' => $reporter->id,
            'project_id' => $invitedProject->id,
            'municipality_id' => $municipality->id,
            'status' => SubmissionStatus::REJECTED->value,
            'title' => 'Rejected update',
        ]);

        Submission::query()->create([
            'reporter_id' => $reporter->id,
            'project_id' => $invitedProject->id,
            'municipality_id' => $municipality->id,
            'status' => SubmissionStatus::UNDER_REVIEW->value,
            'title' => 'Pending update',
        ]);

        Sanctum::actingAs($reporter);

        $homeResponse = $this->getJson('/api/mobile/home');

        $homeResponse
            ->assertOk()
            ->assertJsonPath('result', true)
            ->assertJsonPath('data.submission_overview.total', 3)
            ->assertJsonPath('data.projects.invited_count', 1)
            ->assertJsonPath('data.projects.invited.0.code', 'PRJ-ALK-001');

        $projectsResponse = $this->getJson('/api/mobile/projects?list_type=invited');

        $projectsResponse
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.execution_status', 'in_progress');

        $detailResponse = $this->getJson('/api/mobile/projects/'.$invitedProject->id);

        $detailResponse
            ->assertOk()
            ->assertJsonPath('data.project.code', 'PRJ-ALK-001')
            ->assertJsonPath('data.project.funding_budget', 1200000)
            ->assertJsonPath('data.project.funding_sources.0', 'UNDP Libya')
            ->assertJsonPath('data.project.funding_sources.1', 'European Union')
            ->assertJsonPath('data.project.can_report', true);
    }

    public function test_mobile_home_supports_independent_invited_and_area_filters_with_generic_search(): void
    {
        $municipality = Municipality::query()->create([
            'name_en' => 'Benghazi',
            'name_ar' => 'بنغازي',
            'code' => 'BEN',
        ]);

        Project::query()->create([
            'municipality_id' => $municipality->id,
            'name_en' => 'Clinic Rehabilitation',
            'name_ar' => 'إعادة تأهيل العيادة',
            'description' => 'Main district health clinic rehabilitation.',
            'status' => 'active',
            'latitude' => 32.1167,
            'longitude' => 20.0667,
            'last_update_at' => now(),
            'mobile_meta' => [
                'code' => 'PRJ-BEN-001',
                'execution_status' => 'planned',
                'is_invited' => true,
            ],
        ]);

        Project::query()->create([
            'municipality_id' => $municipality->id,
            'name_en' => 'Market Water Network',
            'name_ar' => 'شبكة مياه السوق',
            'description' => 'Central market water line replacement in progress.',
            'status' => 'active',
            'latitude' => 32.119,
            'longitude' => 20.064,
            'last_update_at' => now()->subMinutes(10),
            'mobile_meta' => [
                'code' => 'PRJ-BEN-002',
                'execution_status' => 'in_progress',
                'is_invited' => true,
            ],
        ]);

        Project::query()->create([
            'municipality_id' => $municipality->id,
            'name_en' => 'School Rehabilitation',
            'name_ar' => 'تأهيل المدرسة',
            'description' => 'School work has been completed and handed over.',
            'status' => 'active',
            'latitude' => 32.121,
            'longitude' => 20.061,
            'last_update_at' => now()->subMinutes(30),
            'mobile_meta' => [
                'code' => 'PRJ-BEN-003',
                'execution_status' => 'completed',
                'is_invited' => false,
            ],
        ]);

        $focalPoint = User::factory()->create([
            'role' => UserRole::MUNICIPAL_FOCAL_POINT->value,
            'municipality_id' => $municipality->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($focalPoint);

        $response = $this->getJson('/api/mobile/home?invited_status=inprogress&invited_search=market&area_status=planned&area_search=clinic');

        $response
            ->assertOk()
            ->assertJsonPath('result', true)
            ->assertJsonPath('data.projects.invited_count', 1)
            ->assertJsonPath('data.projects.invited.0.code', 'PRJ-BEN-002')
            ->assertJsonPath('data.projects.invited.0.execution_status', 'in_progress')
            ->assertJsonPath('data.projects.area_count', 1)
            ->assertJsonPath('data.projects.area.0.code', 'PRJ-BEN-001')
            ->assertJsonPath('data.projects.area.0.execution_status', 'planned')
            ->assertJsonPath('data.projects.filters.invited.status', 'in_progress')
            ->assertJsonPath('data.projects.filters.area.status', 'planned');
    }

    public function test_mobile_home_invited_projects_support_pagination(): void
    {
        $municipality = Municipality::query()->create([
            'name_en' => 'Alkufraa',
            'name_ar' => 'الكفرة',
            'code' => 'ALK',
        ]);

        $reporter = User::factory()->create([
            'role' => UserRole::REPORTER->value,
            'municipality_id' => $municipality->id,
            'status' => 'active',
        ]);

        $firstProject = Project::query()->create([
            'municipality_id' => $municipality->id,
            'name_en' => 'Project One',
            'name_ar' => 'المشروع الأول',
            'status' => 'active',
            'latitude' => 23.31,
            'longitude' => 21.85,
            'last_update_at' => now(),
            'mobile_meta' => [
                'code' => 'PRJ-ALK-001',
                'execution_status' => 'planned',
            ],
        ]);

        $secondProject = Project::query()->create([
            'municipality_id' => $municipality->id,
            'name_en' => 'Project Two',
            'name_ar' => 'المشروع الثاني',
            'status' => 'active',
            'latitude' => 23.32,
            'longitude' => 21.86,
            'last_update_at' => now()->subMinute(),
            'mobile_meta' => [
                'code' => 'PRJ-ALK-002',
                'execution_status' => 'in_progress',
            ],
        ]);

        $thirdProject = Project::query()->create([
            'municipality_id' => $municipality->id,
            'name_en' => 'Project Three',
            'name_ar' => 'المشروع الثالث',
            'status' => 'active',
            'latitude' => 23.33,
            'longitude' => 21.87,
            'last_update_at' => now()->subMinutes(2),
            'mobile_meta' => [
                'code' => 'PRJ-ALK-003',
                'execution_status' => 'completed',
            ],
        ]);

        $firstProject->assignedReporters()->syncWithoutDetaching([$reporter->id => ['assigned_by' => $reporter->id]]);
        $secondProject->assignedReporters()->syncWithoutDetaching([$reporter->id => ['assigned_by' => $reporter->id]]);
        $thirdProject->assignedReporters()->syncWithoutDetaching([$reporter->id => ['assigned_by' => $reporter->id]]);

        Sanctum::actingAs($reporter);

        $response = $this->getJson('/api/mobile/home?invited_limit=2&invited_page=2');

        $response
            ->assertOk()
            ->assertJsonPath('result', true)
            ->assertJsonPath('data.projects.invited_count', 3)
            ->assertJsonPath('data.projects.invited_pagination.page', 2)
            ->assertJsonPath('data.projects.invited_pagination.per_page', 2)
            ->assertJsonPath('data.projects.invited_pagination.total_pages', 2)
            ->assertJsonPath('data.projects.invited_pagination.has_previous', true)
            ->assertJsonPath('data.projects.invited_pagination.has_more', false)
            ->assertJsonCount(1, 'data.projects.invited')
            ->assertJsonPath('data.projects.invited.0.code', 'PRJ-ALK-003');
    }

    public function test_mobile_submissions_support_status_filters_and_pagination(): void
    {
        $municipality = Municipality::query()->create([
            'name_en' => 'Alkufraa',
            'name_ar' => 'الكفرة',
            'code' => 'ALK',
        ]);

        $project = Project::query()->create([
            'municipality_id' => $municipality->id,
            'name_en' => 'Alkufraa Police Station Project',
            'name_ar' => 'مشروع مركز شرطة الكفرة',
            'status' => 'active',
            'latitude' => 23.3112,
            'longitude' => 21.8569,
            'last_update_at' => now(),
        ]);

        $reporter = User::factory()->create([
            'role' => UserRole::REPORTER->value,
            'municipality_id' => $municipality->id,
            'status' => 'active',
        ]);

        $project->assignedReporters()->sync([
            $reporter->id => ['assigned_by' => $reporter->id],
        ]);

        $approvedOlder = Submission::query()->create([
            'reporter_id' => $reporter->id,
            'project_id' => $project->id,
            'municipality_id' => $municipality->id,
            'status' => SubmissionStatus::APPROVED->value,
            'title' => 'Approved older',
        ]);

        $approvedLatest = Submission::query()->create([
            'reporter_id' => $reporter->id,
            'project_id' => $project->id,
            'municipality_id' => $municipality->id,
            'status' => SubmissionStatus::APPROVED->value,
            'title' => 'Approved latest',
        ]);

        $rejected = Submission::query()->create([
            'reporter_id' => $reporter->id,
            'project_id' => $project->id,
            'municipality_id' => $municipality->id,
            'status' => SubmissionStatus::REJECTED->value,
            'title' => 'Rejected report',
        ]);

        $reworkRequested = Submission::query()->create([
            'reporter_id' => $reporter->id,
            'project_id' => $project->id,
            'municipality_id' => $municipality->id,
            'status' => SubmissionStatus::REWORK_REQUESTED->value,
            'title' => 'Needs rework',
        ]);

        $draft = Submission::query()->create([
            'reporter_id' => $reporter->id,
            'project_id' => $project->id,
            'municipality_id' => $municipality->id,
            'status' => SubmissionStatus::DRAFT->value,
            'title' => 'Draft report',
        ]);

        DB::table('submissions')->where('id', $approvedLatest->id)->update([
            'updated_at' => now()->subMinute(),
        ]);
        DB::table('submissions')->where('id', $approvedOlder->id)->update([
            'updated_at' => now()->subMinutes(2),
        ]);
        DB::table('submissions')->where('id', $rejected->id)->update([
            'updated_at' => now()->subMinutes(3),
        ]);
        DB::table('submissions')->where('id', $reworkRequested->id)->update([
            'updated_at' => now()->subMinutes(4),
        ]);
        DB::table('submissions')->where('id', $draft->id)->update([
            'updated_at' => now()->subMinutes(5),
        ]);

        Sanctum::actingAs($reporter);

        $approvedPageOne = $this->getJson('/api/mobile/submissions?tab=submitted&status=approved&per_page=1&page=1');
        $approvedPageOne
            ->assertOk()
            ->assertJsonPath('result', true)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $approvedLatest->id)
            ->assertJsonPath('data.items.0.status', SubmissionStatus::APPROVED->value)
            ->assertJsonPath('data.pagination.page', 1)
            ->assertJsonPath('data.pagination.per_page', 1)
            ->assertJsonPath('data.pagination.total', 2)
            ->assertJsonPath('data.pagination.total_pages', 2)
            ->assertJsonPath('data.pagination.has_more', true);

        $approvedPageTwo = $this->getJson('/api/mobile/submissions?tab=submitted&status=approved&per_page=1&page=2');
        $approvedPageTwo
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $approvedOlder->id)
            ->assertJsonPath('data.pagination.page', 2)
            ->assertJsonPath('data.pagination.has_previous', true)
            ->assertJsonPath('data.pagination.has_more', false);

        $rejectedResponse = $this->getJson('/api/mobile/submissions?status=rejected&per_page=10&page=1');
        $rejectedResponse
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', $rejected->id)
            ->assertJsonPath('data.items.0.status', SubmissionStatus::REJECTED->value);

        $reworkResponse = $this->getJson('/api/mobile/submissions?status=rework&per_page=10&page=1');
        $reworkResponse
            ->assertOk()
            ->assertJsonPath('data.filters.status', SubmissionStatus::REWORK_REQUESTED->value)
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', $reworkRequested->id)
            ->assertJsonPath('data.items.0.status', SubmissionStatus::REWORK_REQUESTED->value);

        $draftsResponse = $this->getJson('/api/mobile/submissions?tab=drafts&per_page=1&page=1');
        $draftsResponse
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', $draft->id)
            ->assertJsonPath('data.items.0.status', SubmissionStatus::DRAFT->value);
    }

    public function test_create_mobile_submission_defaults_to_draft_when_mode_is_missing(): void
    {
        $municipality = Municipality::query()->create([
            'name_en' => 'Alkufraa',
            'name_ar' => 'الكفرة',
            'code' => 'ALK',
        ]);

        $project = Project::query()->create([
            'municipality_id' => $municipality->id,
            'name_en' => 'Alkufraa Police Station Project',
            'name_ar' => 'مشروع مركز شرطة الكفرة',
            'status' => 'active',
            'latitude' => 23.3112,
            'longitude' => 21.8569,
            'last_update_at' => now(),
            'mobile_meta' => [
                'code' => 'PRJ-ALK-001',
                'location_label' => 'South Region - Alkufraa',
                'execution_status' => 'in_progress',
                'progress_percent' => 55,
                'is_invited' => true,
            ],
        ]);

        $reporter = User::factory()->create([
            'role' => UserRole::REPORTER->value,
            'municipality_id' => $municipality->id,
            'status' => 'active',
        ]);

        $project->assignedReporters()->sync([
            $reporter->id => ['assigned_by' => $reporter->id],
        ]);

        Sanctum::actingAs($reporter);

        $response = $this->postJson('/api/mobile/submissions', [
            'project_id' => $project->id,
            'title' => 'Phase 1 Reinforcement Progress Update',
            'project_status' => 'in_progress',
            'summary_of_observation' => 'Initial draft content.',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('result', true)
            ->assertJsonPath('data.submission.status', SubmissionStatus::DRAFT->value)
            ->assertJsonPath('data.submission.data.project_status', 'in_progress');
    }

    public function test_reporter_can_save_draft_and_submit_mobile_submission(): void
    {
        $municipality = Municipality::query()->create([
            'name_en' => 'Alkufraa',
            'name_ar' => 'الكفرة',
            'code' => 'ALK',
        ]);

        $project = Project::query()->create([
            'municipality_id' => $municipality->id,
            'name_en' => 'Alkufraa Police Station Project',
            'name_ar' => 'مشروع مركز شرطة الكفرة',
            'status' => 'active',
            'latitude' => 23.3112,
            'longitude' => 21.8569,
            'last_update_at' => now(),
            'mobile_meta' => [
                'code' => 'PRJ-ALK-001',
                'location_label' => 'South Region - Alkufraa',
                'execution_status' => 'in_progress',
                'progress_percent' => 55,
                'is_invited' => true,
            ],
        ]);

        $reporter = User::factory()->create([
            'role' => UserRole::REPORTER->value,
            'municipality_id' => $municipality->id,
            'status' => 'active',
        ]);

        $project->assignedReporters()->sync([
            $reporter->id => ['assigned_by' => $reporter->id],
        ]);

        Sanctum::actingAs($reporter);

        $draftResponse = $this->postJson('/api/mobile/submissions', [
            'client_uuid' => (string) Str::uuid(),
            'project_id' => $project->id,
            'mode' => 'draft',
            'title' => 'Phase 1 Reinforcement Progress Update',
            'project_status' => 'in_progress',
            'summary_of_observation' => 'Initial draft content.',
        ]);

        $draftResponse
            ->assertCreated()
            ->assertJsonPath('data.submission.status', SubmissionStatus::DRAFT->value);

        $submissionId = $draftResponse->json('data.submission.id');

        $submitResponse = $this->putJson('/api/mobile/submissions/'.$submissionId, [
            'project_id' => $project->id,
            'mode' => 'submit',
            'title' => 'Phase 1 Reinforcement Progress Update',
            'project_status' => 'in_progress',
            'progress_impression' => 'good',
            'physical_progress' => true,
            'approximate_completion_percentage' => 25,
            'additional_observations' => 'Concrete reinforcement is ongoing.',
            'summary_of_observation' => 'Structural reinforcement of the perimeter wall is progressing steadily.',
            'key_updates' => [
                'Reinforced concrete materials delivered on-site',
                'Night shift introduced to accelerate progress',
            ],
            'actual_beneficiaries' => 123,
            'location_label' => 'Alkufraa Municipality, Southern Libya',
            'notes' => 'Community members expressed satisfaction with the new facility.',
            'confirm_accuracy' => true,
        ]);

        $submitResponse
            ->assertOk()
            ->assertJsonPath('data.submission.status', SubmissionStatus::SUBMITTED->value)
            ->assertJsonPath('data.submission.data.approximate_completion_percentage', 25);

        $this->assertDatabaseHas('submissions', [
            'id' => $submissionId,
            'status' => SubmissionStatus::SUBMITTED->value,
        ]);
    }

    public function test_reporter_can_submit_using_assets_alias(): void
    {
        $municipality = Municipality::query()->create([
            'name_en' => 'Alkufraa',
            'name_ar' => 'الكفرة',
            'code' => 'ALK',
        ]);

        $project = Project::query()->create([
            'municipality_id' => $municipality->id,
            'name_en' => 'Alkufraa Police Station Project',
            'name_ar' => 'مشروع مركز شرطة الكفرة',
            'status' => 'active',
            'latitude' => 23.3112,
            'longitude' => 21.8569,
            'last_update_at' => now(),
        ]);

        $reporter = User::factory()->create([
            'role' => UserRole::REPORTER->value,
            'municipality_id' => $municipality->id,
            'status' => 'active',
        ]);

        $project->assignedReporters()->sync([
            $reporter->id => ['assigned_by' => $reporter->id],
        ]);

        Sanctum::actingAs($reporter);

        $draftResponse = $this->postJson('/api/mobile/submissions', [
            'client_uuid' => (string) Str::uuid(),
            'project_id' => $project->id,
            'mode' => 'draft',
            'title' => 'Completed Status Draft',
            'project_status' => 'completed',
        ])->assertCreated();

        $submissionId = $draftResponse->json('data.submission.id');

        $mediaAsset = MediaAsset::query()->create([
            'uuid' => (string) Str::uuid(),
            'submission_id' => $submissionId,
            'uploaded_by' => $reporter->id,
            'client_media_id' => 'assets-alias-1',
            'disk' => 'public',
            'bucket' => null,
            'object_key' => 'test/mobile/'.$submissionId.'/assets-alias-1.jpg',
            'media_type' => 'image',
            'mime_type' => 'image/jpeg',
            'original_filename' => 'assets-alias-1.jpg',
            'status' => 'uploaded',
            'label' => 'Training room',
            'display_order' => 0,
        ]);

        $submitResponse = $this->putJson('/api/mobile/submissions/'.$submissionId, [
            'mode' => 'submit',
            'project_status' => 'completed',
            'is_project_being_used' => true,
            'activity_started' => 'Yes',
            'user_categories' => ['all_of_the_above'],
            'is_used_as_intended' => true,
            'functional_status' => 'fully_functional',
            'negative_environmental_impact' => false,
            'actual_beneficiaries' => 200,
            'location_label' => 'South Region - Alkufraa',
            'confirm_accuracy' => true,
            'assets' => [
                $mediaAsset->id,
            ],
        ]);

        $submitResponse
            ->assertOk()
            ->assertJsonPath('data.submission.status', SubmissionStatus::SUBMITTED->value)
            ->assertJsonPath('data.submission.data.activities_started', true)
            ->assertJsonPath('data.submission.data.activity_started', true)
            ->assertJsonPath('data.submission.media_assets.0.id', $mediaAsset->id)
            ->assertJsonPath('data.submission.assets.0.id', $mediaAsset->id);
    }

    public function test_reporter_can_upload_assets_via_submission_form_data(): void
    {
        Storage::fake(config('media.disk', 's3'));

        $municipality = Municipality::query()->create([
            'name_en' => 'Alkufraa',
            'name_ar' => 'الكفرة',
            'code' => 'ALK',
        ]);

        $project = Project::query()->create([
            'municipality_id' => $municipality->id,
            'name_en' => 'Alkufraa Police Station Project',
            'name_ar' => 'مشروع مركز شرطة الكفرة',
            'status' => 'active',
            'latitude' => 23.3112,
            'longitude' => 21.8569,
            'last_update_at' => now(),
        ]);

        $reporter = User::factory()->create([
            'role' => UserRole::REPORTER->value,
            'municipality_id' => $municipality->id,
            'status' => 'active',
        ]);

        $project->assignedReporters()->sync([
            $reporter->id => ['assigned_by' => $reporter->id],
        ]);

        Sanctum::actingAs($reporter);

        $draftResponse = $this->postJson('/api/mobile/submissions', [
            'client_uuid' => (string) Str::uuid(),
            'project_id' => $project->id,
            'mode' => 'draft',
            'title' => 'Completed Status Draft',
            'project_status' => 'completed',
        ])->assertCreated();

        $submissionId = $draftResponse->json('data.submission.id');

        $submitResponse = $this->put('/api/mobile/submissions/'.$submissionId, [
            'project_id' => $project->id,
            'mode' => 'submit',
            'title' => 'Completed Project Verification',
            'project_status' => 'completed',
            'is_project_being_used' => '1',
            'activity_started' => 'Yes',
            'user_categories' => ['all_of_the_above'],
            'is_used_as_intended' => '1',
            'functional_status' => 'fully_functional',
            'negative_environmental_impact' => '0',
            'summary_of_observation' => 'Project assets are operational and in daily use.',
            'actual_beneficiaries' => '460',
            'location_label' => 'Alkufraa Municipality, Southern Libya',
            'location_source' => 'manual',
            'assets' => [
                UploadedFile::fake()->image('completed-site-condition.jpg'),
            ],
            'notes' => 'Community feedback indicates high satisfaction with service quality.',
            'confirm_accuracy' => '1',
        ], [
            'Accept' => 'application/json',
        ]);

        $submitResponse
            ->assertOk()
            ->assertJsonPath('data.submission.status', SubmissionStatus::SUBMITTED->value)
            ->assertJsonPath('data.submission.data.activities_started', true)
            ->assertJsonPath('data.submission.data.activity_started', true)
            ->assertJsonPath('data.submission.assets.0.media_type', 'image');

        $mediaAsset = MediaAsset::query()
            ->where('submission_id', $submissionId)
            ->latest('id')
            ->first();

        $this->assertNotNull($mediaAsset);
        Storage::disk(config('media.disk', 's3'))->assertExists($mediaAsset->object_key);
    }

    public function test_reporter_can_resubmit_submitted_report_before_validator_action(): void
    {
        $municipality = Municipality::query()->create([
            'name_en' => 'Alkufraa',
            'name_ar' => 'الكفرة',
            'code' => 'ALK',
        ]);

        $project = Project::query()->create([
            'municipality_id' => $municipality->id,
            'name_en' => 'Alkufraa Police Station Project',
            'name_ar' => 'مشروع مركز شرطة الكفرة',
            'status' => 'active',
            'latitude' => 23.3112,
            'longitude' => 21.8569,
            'last_update_at' => now(),
        ]);

        $reporter = User::factory()->create([
            'role' => UserRole::REPORTER->value,
            'municipality_id' => $municipality->id,
            'status' => 'active',
        ]);

        $project->assignedReporters()->sync([
            $reporter->id => ['assigned_by' => $reporter->id],
        ]);

        Sanctum::actingAs($reporter);

        $draftResponse = $this->postJson('/api/mobile/submissions', [
            'client_uuid' => (string) Str::uuid(),
            'project_id' => $project->id,
            'mode' => 'draft',
            'title' => 'Planned Status Draft',
            'project_status' => 'planned',
        ])->assertCreated();

        $submissionId = $draftResponse->json('data.submission.id');

        $plannedSubmit = $this->putJson('/api/mobile/submissions/'.$submissionId, [
            'project_id' => $project->id,
            'mode' => 'submit',
            'title' => 'Planned Status Draft',
            'project_status' => 'planned',
            'delay_reason' => 'land_ownership_dispute',
            'actual_beneficiaries' => 0,
            'location_label' => 'South Region - Alkufraa',
            'confirm_accuracy' => true,
        ]);

        $plannedSubmit
            ->assertOk()
            ->assertJsonPath('data.submission.status', SubmissionStatus::SUBMITTED->value)
            ->assertJsonPath('data.submission.data.project_status', 'planned');

        $completedResubmit = $this->putJson('/api/mobile/submissions/'.$submissionId, [
            'project_id' => $project->id,
            'mode' => 'submit',
            'title' => 'Completed Status Update',
            'project_status' => 'completed',
            'is_project_being_used' => true,
            'user_categories' => ['all_of_the_above'],
            'is_used_as_intended' => true,
            'functional_status' => 'fully_functional',
            'negative_environmental_impact' => true,
            'negative_impact_details' => 'Minor construction debris left near the entrance area.',
            'actual_beneficiaries' => 200,
            'location_label' => 'South Region - Alkufraa',
            'confirm_accuracy' => true,
        ]);

        $completedResubmit
            ->assertOk()
            ->assertJsonPath('data.submission.status', SubmissionStatus::SUBMITTED->value)
            ->assertJsonPath('data.submission.data.project_status', 'completed')
            ->assertJsonPath('data.submission.data.functional_status', 'fully_functional');
    }

    public function test_reporting_options_expose_status_driven_mobile_flow(): void
    {
        $municipality = Municipality::query()->create([
            'name_en' => 'Alkufraa',
            'name_ar' => 'الكفرة',
            'code' => 'ALK',
        ]);

        $project = Project::query()->create([
            'municipality_id' => $municipality->id,
            'name_en' => 'Alkufraa Police Station Project',
            'name_ar' => 'مشروع مركز شرطة الكفرة',
            'status' => 'active',
            'latitude' => 23.3112,
            'longitude' => 21.8569,
            'last_update_at' => now(),
            'mobile_meta' => [
                'code' => 'PRJ-ALK-001',
                'location_label' => 'South Region - Alkufraa',
                'execution_status' => 'planned',
                'progress_percent' => 0,
                'is_invited' => true,
            ],
        ]);

        $reporter = User::factory()->create([
            'role' => UserRole::REPORTER->value,
            'municipality_id' => $municipality->id,
            'status' => 'active',
        ]);

        $project->assignedReporters()->sync([
            $reporter->id => ['assigned_by' => $reporter->id],
        ]);

        Sanctum::actingAs($reporter);

        $response = $this->getJson('/api/mobile/reporting/options?project_id='.$project->id);

        $response
            ->assertOk()
            ->assertJsonPath('data.version', config('mobile.reporting.options_version'))
            ->assertJsonPath('data.flow.version', config('mobile.reporting.options_version'))
            ->assertJsonPath('data.flow.steps.1.key', 'project_status')
            ->assertJsonPath('data.flow.steps.1.status_sections.0.status', 'planned')
            ->assertJsonPath('data.flow.steps.1.status_sections.2.status', 'completed')
            ->assertJsonPath('data.flow.steps.1.status_sections.2.fields.1.key', 'activities_started')
            ->assertJsonPath('data.flow.steps.1.status_sections.2.fields.1.aliases.0', 'activity_started')
            ->assertJsonPath('data.flow.steps.1.status_sections.2.fields.1.aliases.1', 'activities_workshops_or_training_started')
            ->assertJsonPath('data.available_options.functional_statuses.1.label', 'Operational but needs maintenance')
            ->assertJsonPath('data.available_options.functional_statuses.1.label_ar', 'تشغيلي لكنه يحتاج صيانة')
            ->assertJsonPath('data.submission_contract.create.path', '/api/mobile/submissions')
            ->assertJsonPath('data.submission_contract.update.accepted_media_reference_keys.0', 'assets')
            ->assertJsonPath('data.submission_contract.field_aliases.activities_started.0', 'activity_started')
            ->assertJsonPath('data.submission_contract.field_aliases.activities_started.1', 'activities_workshops_or_training_started')
            ->assertJsonPath('data.submission_contract.media_upload_flow.4.endpoint', 'PUT /api/mobile/submissions/{submission_id}')
            ->assertJsonPath('data.media_limits.images.max_count', 10);
    }

    public function test_completed_status_submission_requires_conditional_fields(): void
    {
        $municipality = Municipality::query()->create([
            'name_en' => 'Alkufraa',
            'name_ar' => 'الكفرة',
            'code' => 'ALK',
        ]);

        $project = Project::query()->create([
            'municipality_id' => $municipality->id,
            'name_en' => 'Alkufraa Police Station Project',
            'name_ar' => 'مشروع مركز شرطة الكفرة',
            'status' => 'active',
            'latitude' => 23.3112,
            'longitude' => 21.8569,
            'last_update_at' => now(),
            'mobile_meta' => [
                'code' => 'PRJ-ALK-001',
                'location_label' => 'South Region - Alkufraa',
                'execution_status' => 'completed',
                'progress_percent' => 100,
                'is_invited' => true,
            ],
        ]);

        $reporter = User::factory()->create([
            'role' => UserRole::REPORTER->value,
            'municipality_id' => $municipality->id,
            'status' => 'active',
        ]);

        $project->assignedReporters()->sync([
            $reporter->id => ['assigned_by' => $reporter->id],
        ]);

        Sanctum::actingAs($reporter);

        $draft = $this->postJson('/api/mobile/submissions', [
            'client_uuid' => (string) Str::uuid(),
            'project_id' => $project->id,
            'mode' => 'draft',
            'title' => 'Completed Status Draft',
            'project_status' => 'completed',
        ])->assertCreated();

        $submissionId = $draft->json('data.submission.id');

        $invalidSubmit = $this->putJson('/api/mobile/submissions/'.$submissionId, [
            'mode' => 'submit',
            'project_status' => 'completed',
            'is_project_being_used' => true,
            'is_used_as_intended' => true,
            'functional_status' => 'fully_functional',
            'negative_environmental_impact' => true,
            'actual_beneficiaries' => 200,
            'location_label' => 'South Region - Alkufraa',
            'confirm_accuracy' => true,
        ]);

        $invalidSubmit
            ->assertStatus(422)
            ->assertJsonPath('result', false)
            ->assertJsonPath('data.errors.user_categories.0', 'At least one user category is required.')
            ->assertJsonPath('data.errors.negative_impact_details.0', 'Please describe the environmental impact observed.');

        $validSubmit = $this->putJson('/api/mobile/submissions/'.$submissionId, [
            'mode' => 'submit',
            'project_status' => 'completed',
            'is_project_being_used' => true,
            'activities_workshops_or_training_started' => true,
            'user_categories' => ['all_of_the_above'],
            'is_used_as_intended' => true,
            'functional_status' => 'fully_functional',
            'negative_environmental_impact' => true,
            'negative_impact_details' => 'Minor construction debris left near the entrance area.',
            'actual_beneficiaries' => 200,
            'location_label' => 'South Region - Alkufraa',
            'confirm_accuracy' => true,
        ]);

        $validSubmit
            ->assertOk()
            ->assertJsonPath('data.submission.status', SubmissionStatus::SUBMITTED->value)
            ->assertJsonPath('data.submission.data.activities_started', true)
            ->assertJsonPath('data.submission.data.functional_status', 'fully_functional')
            ->assertJsonPath('data.submission.data.user_categories.0', 'all_of_the_above');
    }

    public function test_mobile_profile_avatar_urls_are_directly_accessible(): void
    {
        Storage::fake('public');

        UploadedFile::fake()
            ->image('avatar-test.jpg')
            ->storeAs('mobile/avatars', 'avatar-test.jpg', 'public');

        $municipality = Municipality::query()->create([
            'name_en' => 'Alkufraa',
            'name_ar' => 'الكفرة',
            'code' => 'ALK',
        ]);

        $reporter = User::factory()->create([
            'role' => UserRole::REPORTER->value,
            'municipality_id' => $municipality->id,
            'status' => 'active',
            'avatar_path' => 'mobile/avatars/avatar-test.jpg',
        ]);

        Sanctum::actingAs($reporter);

        $profileResponse = $this->getJson('/api/mobile/profile');

        $profileResponse
            ->assertOk()
            ->assertJsonPath('data.profile.avatar_url', url('/storage/mobile/avatars/avatar-test.jpg'));

        $imageResponse = $this->get('/storage/mobile/avatars/avatar-test.jpg');

        $imageResponse
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_reporter_can_list_and_remove_submission_media(): void
    {
        $municipality = Municipality::query()->create([
            'name_en' => 'Alkufraa',
            'name_ar' => 'الكفرة',
            'code' => 'ALK',
        ]);

        $project = Project::query()->create([
            'municipality_id' => $municipality->id,
            'name_en' => 'Alkufraa Police Station Project',
            'name_ar' => 'مشروع مركز شرطة الكفرة',
            'status' => 'active',
            'latitude' => 23.3112,
            'longitude' => 21.8569,
            'last_update_at' => now(),
        ]);

        $reporter = User::factory()->create([
            'role' => UserRole::REPORTER->value,
            'municipality_id' => $municipality->id,
            'status' => 'active',
        ]);

        $project->assignedReporters()->sync([
            $reporter->id => ['assigned_by' => $reporter->id],
        ]);

        $submission = Submission::query()->create([
            'reporter_id' => $reporter->id,
            'project_id' => $project->id,
            'municipality_id' => $municipality->id,
            'status' => SubmissionStatus::DRAFT->value,
            'title' => 'Draft with media',
            'media' => [],
        ]);

        $firstMedia = MediaAsset::query()->create([
            'uuid' => (string) Str::uuid(),
            'submission_id' => $submission->id,
            'uploaded_by' => $reporter->id,
            'client_media_id' => 'm-1',
            'disk' => 'public',
            'bucket' => null,
            'object_key' => 'test/mobile/'.$submission->id.'/media-1.jpg',
            'media_type' => 'image',
            'mime_type' => 'image/jpeg',
            'original_filename' => 'media-1.jpg',
            'status' => 'uploaded',
            'label' => 'Overview',
            'display_order' => 1,
        ]);

        $secondMedia = MediaAsset::query()->create([
            'uuid' => (string) Str::uuid(),
            'submission_id' => $submission->id,
            'uploaded_by' => $reporter->id,
            'client_media_id' => 'm-2',
            'disk' => 'public',
            'bucket' => null,
            'object_key' => 'test/mobile/'.$submission->id.'/media-2.jpg',
            'media_type' => 'image',
            'mime_type' => 'image/jpeg',
            'original_filename' => 'media-2.jpg',
            'status' => 'uploaded',
            'label' => 'Details',
            'display_order' => 0,
        ]);

        $submission->forceFill([
            'media' => [
                ['id' => $secondMedia->id, 'type' => 'image', 'label' => 'Details'],
                ['id' => $firstMedia->id, 'type' => 'image', 'label' => 'Overview'],
            ],
        ])->save();

        Sanctum::actingAs($reporter);

        $listResponse = $this->getJson('/api/mobile/submissions/'.$submission->id.'/media');

        $listResponse
            ->assertOk()
            ->assertJsonPath('data.media_assets.0.id', $secondMedia->id)
            ->assertJsonPath('data.media_assets.1.id', $firstMedia->id);

        $deleteResponse = $this->deleteJson('/api/mobile/submissions/'.$submission->id.'/media/'.$secondMedia->id);

        $deleteResponse
            ->assertOk()
            ->assertJsonPath('data.media_assets.0.id', $firstMedia->id);

        $this->assertDatabaseMissing('media_assets', [
            'id' => $secondMedia->id,
        ]);
    }

    public function test_reporter_can_read_and_mark_mobile_notifications(): void
    {
        $municipality = Municipality::query()->create([
            'name_en' => 'Alkufraa',
            'name_ar' => 'الكفرة',
            'code' => 'ALK',
        ]);

        $project = Project::query()->create([
            'municipality_id' => $municipality->id,
            'name_en' => 'Alkufraa Police Station Project',
            'name_ar' => 'مشروع مركز شرطة الكفرة',
            'status' => 'active',
        ]);

        $reporter = User::factory()->create([
            'role' => UserRole::REPORTER->value,
            'municipality_id' => $municipality->id,
            'status' => 'active',
        ]);

        $project->assignedReporters()->sync([
            $reporter->id => ['assigned_by' => $reporter->id],
        ]);

        $submission = Submission::query()->create([
            'reporter_id' => $reporter->id,
            'project_id' => $project->id,
            'municipality_id' => $municipality->id,
            'status' => SubmissionStatus::REWORK_REQUESTED->value,
            'title' => 'Rework requested submission',
        ]);

        $notification = DatabaseNotification::query()->create([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\SubmissionStatusChangedNotification',
            'notifiable_type' => User::class,
            'notifiable_id' => $reporter->id,
            'data' => [
                'submission_id' => $submission->id,
                'title' => $submission->title,
                'status' => $submission->status,
                'project_name' => $project->name_en,
            ],
        ]);

        Sanctum::actingAs($reporter);

        $indexResponse = $this->getJson('/api/mobile/inbox');

        $indexResponse
            ->assertOk()
            ->assertJsonPath('data.meta.unread_count', 1)
            ->assertJsonPath('data.items.0.status_label', 'Sent Back');

        $readResponse = $this->patchJson('/api/mobile/inbox/'.$notification->id.'/read');

        $readResponse
            ->assertOk()
            ->assertJsonPath('data.notification.is_read', true);
    }
}
