<?php

namespace Tests\Feature;

use App\Enums\SubmissionStatus;
use App\Enums\UserRole;
use App\Models\Municipality;
use App\Models\Project;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
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
            ->assertJsonPath('data.project.can_report', true);
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
