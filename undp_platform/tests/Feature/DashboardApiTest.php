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
            ->assertJsonStructure([
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
}
