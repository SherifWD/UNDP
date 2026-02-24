<?php

namespace Tests\Feature;

use App\Enums\SubmissionStatus;
use App\Enums\UserRole;
use App\Models\Municipality;
use App\Models\Project;
use App\Models\Submission;
use App\Models\SubmissionStatusEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubmissionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_reporter_can_submit_and_focal_point_can_approve(): void
    {
        $municipality = Municipality::query()->create([
            'name_en' => 'Tripoli',
            'name_ar' => 'طرابلس',
            'code' => 'TRI',
        ]);

        $project = Project::query()->create([
            'municipality_id' => $municipality->id,
            'name_en' => 'Water Project',
            'name_ar' => 'مشروع المياه',
            'status' => 'active',
        ]);

        $reporter = User::factory()->create([
            'role' => UserRole::REPORTER->value,
            'municipality_id' => $municipality->id,
        ]);

        $focal = User::factory()->create([
            'role' => UserRole::MUNICIPAL_FOCAL_POINT->value,
            'municipality_id' => $municipality->id,
        ]);

        Sanctum::actingAs($reporter);

        $createResponse = $this->postJson('/api/submissions', [
            'project_id' => $project->id,
            'title' => 'Pipe replacement completed',
            'details' => 'Section A completed with photos.',
            'status' => SubmissionStatus::UNDER_REVIEW->value,
        ]);

        $createResponse->assertCreated();

        $submissionId = $createResponse->json('submission.id');
        $this->assertNotNull($submissionId);

        Sanctum::actingAs($focal);

        $approveResponse = $this->postJson("/api/submissions/{$submissionId}/approve", [
            'comment' => 'Approved after verification.',
        ]);

        $approveResponse
            ->assertOk()
            ->assertJsonPath('submission.status', SubmissionStatus::APPROVED->value);

        $this->assertDatabaseHas('submissions', [
            'id' => $submissionId,
            'status' => SubmissionStatus::APPROVED->value,
            'validated_by' => $focal->id,
        ]);

        $this->assertGreaterThanOrEqual(
            2,
            SubmissionStatusEvent::query()->where('submission_id', $submissionId)->count(),
        );

        $submission = Submission::query()->find($submissionId);
        $this->assertSame(SubmissionStatus::APPROVED->value, $submission->status);
    }
}
