<?php

namespace Tests\Feature;

use App\Enums\SubmissionStatus;
use App\Enums\UserRole;
use App\Models\Municipality;
use App\Models\Project;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_reporter_can_access_scoped_kpis_dashboard(): void
    {
        $municipality = Municipality::query()->create([
            'name_en' => 'Tripoli',
            'name_ar' => 'طرابلس',
            'code' => 'TRI',
        ]);

        $project = Project::query()->create([
            'municipality_id' => $municipality->id,
            'name_en' => 'Water Network',
            'name_ar' => 'شبكة المياه',
            'status' => 'active',
            'latitude' => 32.8872,
            'longitude' => 13.1913,
        ]);

        $reporter = User::factory()->create([
            'role' => UserRole::REPORTER->value,
            'municipality_id' => $municipality->id,
        ]);

        Submission::query()->create([
            'reporter_id' => $reporter->id,
            'project_id' => $project->id,
            'municipality_id' => $municipality->id,
            'status' => SubmissionStatus::UNDER_REVIEW->value,
            'title' => 'Reporter scoped item',
        ]);

        Sanctum::actingAs($reporter);

        $response = $this->getJson('/api/dashboard/kpis');

        $response
            ->assertOk()
            ->assertJsonPath('result', true)
            ->assertJsonStructure([
                'data' => [
                    'kpis' => [
                        'total_submissions',
                        'approved',
                        'under_review',
                        'rework_requested',
                        'rejected',
                    ],
                ],
                'kpis' => [
                    'total_submissions',
                    'approved',
                    'under_review',
                    'rework_requested',
                    'rejected',
                ],
            ]);
    }

    public function test_user_without_dashboard_permissions_is_blocked(): void
    {
        $user = User::factory()->create([
            'role' => 'custom_role_no_dashboard',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/dashboard/kpis');

        $response->assertForbidden();
    }

    public function test_map_endpoint_returns_markers_and_clusters(): void
    {
        $municipality = Municipality::query()->create([
            'name_en' => 'Benghazi',
            'name_ar' => 'بنغازي',
            'code' => 'BEN',
        ]);

        $project = Project::query()->create([
            'municipality_id' => $municipality->id,
            'name_en' => 'School Rehab',
            'name_ar' => 'تأهيل المدارس',
            'status' => 'active',
            'latitude' => 32.1167,
            'longitude' => 20.0667,
        ]);

        $reporter = User::factory()->create([
            'role' => UserRole::REPORTER->value,
            'municipality_id' => $municipality->id,
        ]);

        Submission::query()->create([
            'reporter_id' => $reporter->id,
            'project_id' => $project->id,
            'municipality_id' => $municipality->id,
            'status' => SubmissionStatus::APPROVED->value,
            'title' => 'Mapped submission',
            'latitude' => 32.1172,
            'longitude' => 20.0673,
        ]);

        $admin = User::factory()->create([
            'role' => UserRole::UNDP_ADMIN->value,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/dashboard/map?cluster=1&cluster_zoom=7');

        $response
            ->assertOk()
            ->assertJsonStructure([
                'markers',
                'clusters',
                'cluster_meta' => ['enabled', 'zoom'],
            ]);
    }

    public function test_reporter_can_access_map_endpoint_with_own_scope_only(): void
    {
        $tripoli = Municipality::query()->create([
            'name_en' => 'Tripoli',
            'name_ar' => 'طرابلس',
            'code' => 'TRI',
        ]);

        $benghazi = Municipality::query()->create([
            'name_en' => 'Benghazi',
            'name_ar' => 'بنغازي',
            'code' => 'BEN',
        ]);

        $tripoliProject = Project::query()->create([
            'municipality_id' => $tripoli->id,
            'name_en' => 'Tripoli Water Network',
            'name_ar' => 'شبكة مياه طرابلس',
            'status' => 'active',
            'latitude' => 32.8872,
            'longitude' => 13.1913,
        ]);

        $benghaziProject = Project::query()->create([
            'municipality_id' => $benghazi->id,
            'name_en' => 'Benghazi School Rehab',
            'name_ar' => 'تأهيل مدارس بنغازي',
            'status' => 'active',
            'latitude' => 32.1167,
            'longitude' => 20.0667,
        ]);

        $reporter = User::factory()->create([
            'role' => UserRole::REPORTER->value,
            'municipality_id' => $tripoli->id,
        ]);

        Submission::query()->create([
            'reporter_id' => $reporter->id,
            'project_id' => $tripoliProject->id,
            'municipality_id' => $tripoli->id,
            'status' => SubmissionStatus::UNDER_REVIEW->value,
            'title' => 'Reporter own submission',
            'latitude' => 32.8875,
            'longitude' => 13.1918,
        ]);

        $otherReporter = User::factory()->create([
            'role' => UserRole::REPORTER->value,
            'municipality_id' => $benghazi->id,
        ]);

        Submission::query()->create([
            'reporter_id' => $otherReporter->id,
            'project_id' => $benghaziProject->id,
            'municipality_id' => $benghazi->id,
            'status' => SubmissionStatus::APPROVED->value,
            'title' => 'Other reporter submission',
            'latitude' => 32.1171,
            'longitude' => 20.0671,
        ]);

        Sanctum::actingAs($reporter);

        $response = $this->getJson('/api/dashboard/map?cluster=1&cluster_zoom=8');

        $response->assertOk();

        $markers = collect($response->json('markers', []));
        $projectIds = $markers
            ->where('type', 'project')
            ->pluck('id')
            ->all();

        $submissionTitles = $markers
            ->where('type', 'submission')
            ->pluck('name')
            ->all();

        $this->assertContains($tripoliProject->id, $projectIds);
        $this->assertNotContains($benghaziProject->id, $projectIds);
        $this->assertContains('Reporter own submission', $submissionTitles);
        $this->assertNotContains('Other reporter submission', $submissionTitles);
    }
}
