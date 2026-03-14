<?php

namespace Tests\Feature;

use App\Jobs\GenerateExportTaskJob;
use App\Enums\UserRole;
use App\Enums\SubmissionStatus;
use App\Models\Municipality;
use App\Models\Project;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExportTaskTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_queue_users_csv_export_task(): void
    {
        Queue::fake();

        $admin = User::factory()->create([
            'role' => UserRole::UNDP_ADMIN->value,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/exports/tasks', [
            'format' => 'csv',
            'type' => 'users',
        ]);

        $response
            ->assertStatus(202)
            ->assertJsonPath('task.status', 'queued')
            ->assertJsonPath('task.format', 'csv')
            ->assertJsonPath('task.type', 'users');

        Queue::assertPushed(GenerateExportTaskJob::class);
    }

    public function test_reporter_cannot_queue_export_task_without_permission(): void
    {
        $reporter = User::factory()->create([
            'role' => UserRole::REPORTER->value,
        ]);

        Sanctum::actingAs($reporter);

        $response = $this->postJson('/api/exports/tasks', [
            'format' => 'csv',
            'type' => 'submissions',
        ]);

        $response->assertForbidden();
    }

    public function test_partner_csv_export_respects_municipality_filter_for_approved_submissions(): void
    {
        $municipalityA = Municipality::query()->create([
            'name_en' => 'Tripoli',
            'name_ar' => 'طرابلس',
            'code' => 'TRI',
        ]);

        $municipalityB = Municipality::query()->create([
            'name_en' => 'Benghazi',
            'name_ar' => 'بنغازي',
            'code' => 'BEN',
        ]);

        $projectA = Project::query()->create([
            'municipality_id' => $municipalityA->id,
            'name_en' => 'Project A',
            'name_ar' => 'المشروع أ',
            'status' => 'active',
        ]);

        $projectB = Project::query()->create([
            'municipality_id' => $municipalityB->id,
            'name_en' => 'Project B',
            'name_ar' => 'المشروع ب',
            'status' => 'active',
        ]);

        $reporter = User::factory()->create([
            'role' => UserRole::REPORTER->value,
            'municipality_id' => $municipalityA->id,
        ]);

        Submission::query()->create([
            'reporter_id' => $reporter->id,
            'project_id' => $projectA->id,
            'municipality_id' => $municipalityA->id,
            'status' => SubmissionStatus::APPROVED->value,
            'title' => 'Approved A',
        ]);

        Submission::query()->create([
            'reporter_id' => $reporter->id,
            'project_id' => $projectB->id,
            'municipality_id' => $municipalityB->id,
            'status' => SubmissionStatus::APPROVED->value,
            'title' => 'Approved B',
        ]);

        $partner = User::factory()->create([
            'role' => UserRole::PARTNER_DONOR_VIEWER->value,
        ]);

        Sanctum::actingAs($partner);

        $response = $this->get('/api/exports/csv?type=submissions&status=approved&municipality_id='.$municipalityA->id);

        $response->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString('Approved A', $csv);
        $this->assertStringNotContainsString('Approved B', $csv);
    }
}
