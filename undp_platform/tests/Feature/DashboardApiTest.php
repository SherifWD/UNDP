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
        $tripoliProject->assignedReporters()->attach($reporter->id);

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
        $benghaziProject->assignedReporters()->attach($otherReporter->id);

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

    public function test_partner_dashboard_returns_approved_aggregates_with_breakdowns(): void
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
            'name_en' => 'Water Network',
            'name_ar' => 'شبكة المياه',
            'status' => 'active',
        ]);

        $projectB = Project::query()->create([
            'municipality_id' => $municipalityB->id,
            'name_en' => 'School Rehab',
            'name_ar' => 'تأهيل المدارس',
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
            'title' => 'Approved update A1',
            'data' => [
                'actual_beneficiaries' => 120,
                'approximate_completion_percentage' => 75,
            ],
        ]);

        Submission::query()->create([
            'reporter_id' => $reporter->id,
            'project_id' => $projectA->id,
            'municipality_id' => $municipalityA->id,
            'status' => SubmissionStatus::APPROVED->value,
            'title' => 'Approved update A2',
            'data' => [
                'actual_beneficiaries' => 80,
                'approximate_completion_percentage' => 95,
            ],
        ]);

        Submission::query()->create([
            'reporter_id' => $reporter->id,
            'project_id' => $projectB->id,
            'municipality_id' => $municipalityB->id,
            'status' => SubmissionStatus::REJECTED->value,
            'title' => 'Rejected update B1',
        ]);

        $partner = User::factory()->create([
            'role' => UserRole::PARTNER_DONOR_VIEWER->value,
        ]);

        Sanctum::actingAs($partner);

        $response = $this->getJson('/api/dashboard/partner');

        $response
            ->assertOk()
            ->assertJsonPath('kpis.approved_total', 2)
            ->assertJsonPath('kpis.projects_covered', 1)
            ->assertJsonPath('kpis.municipalities_covered', 1)
            ->assertJsonPath('kpis.total_actual_beneficiaries', 200)
            ->assertJsonPath('kpis.average_completion_percentage', 85)
            ->assertJsonPath('status_breakdown.approved', 2);

        $municipalityCounts = collect($response->json('municipality_breakdown'));
        $projectCounts = collect($response->json('project_breakdown'));

        $this->assertSame(1, $municipalityCounts->count());
        $this->assertSame(1, $projectCounts->count());
        $this->assertSame($municipalityA->id, (int) $municipalityCounts->first()['municipality_id']);
        $this->assertSame($projectA->id, (int) $projectCounts->first()['project_id']);
    }
}
