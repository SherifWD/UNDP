<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Municipality;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_reporter_only_sees_assigned_projects_in_web_and_mobile(): void
    {
        $municipality = Municipality::query()->create([
            'name_en' => 'Alkufraa',
            'name_ar' => 'الكفرة',
            'code' => 'ALK',
        ]);

        $assignedProject = Project::query()->create([
            'municipality_id' => $municipality->id,
            'name_en' => 'Assigned Project',
            'name_ar' => 'مشروع مخصص',
            'status' => 'active',
        ]);

        Project::query()->create([
            'municipality_id' => $municipality->id,
            'name_en' => 'Unassigned Project',
            'name_ar' => 'مشروع غير مخصص',
            'status' => 'active',
        ]);

        $reporter = User::factory()->create([
            'role' => UserRole::REPORTER->value,
            'municipality_id' => $municipality->id,
            'status' => 'active',
        ]);

        $assignedProject->assignedReporters()->sync([
            $reporter->id => ['assigned_by' => $reporter->id],
        ]);

        Sanctum::actingAs($reporter);

        $webResponse = $this->getJson('/api/projects?with_stats=1');

        $webResponse
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $assignedProject->id);

        $mobileResponse = $this->getJson('/api/mobile/projects');

        $mobileResponse
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.id', $assignedProject->id);
    }

    public function test_admin_can_create_project_with_assigned_reporters(): void
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

        $admin = User::factory()->create([
            'role' => UserRole::UNDP_ADMIN->value,
            'status' => 'active',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/projects', [
            'municipality_id' => $municipality->id,
            'name_en' => 'Alkufraa Police Station Project',
            'name_ar' => 'مشروع مركز شرطة الكفرة',
            'description' => 'Public safety infrastructure initiative.',
            'status' => 'active',
            'project_code' => 'PRJ-ALK-001',
            'execution_status' => 'in_progress',
            'project_category' => 'Infrastructure - Public Safety',
            'region_label' => 'South Region - Alkufraa',
            'location_label' => 'Alkufraa Municipality, Southern Libya',
            'implementing_partner' => 'Alkufraa Municipality',
            'program_lead' => 'UNDP Libya',
            'development_goal_area' => 'Public Safety',
            'execution_model' => 'Government-led implementation with donor support',
            'start_date' => '2026-04-01',
            'end_date' => '2026-08-31',
            'objectives' => ['Strengthen local security infrastructure'],
            'hard_components' => ['Perimeter wall reinforcement'],
            'soft_components' => ['Institutional coordination support'],
            'funding_budget' => 1200000,
            'funding_sources' => ['UNDP Libya', 'European Union'],
            'funding_types' => ['International Donor Funding'],
            'progress_percent' => 41,
            'visibility' => 'Internal - Admin & Authorized Stakeholders',
            'latitude' => 23.3112,
            'longitude' => 21.8569,
            'assigned_reporter_ids' => [$reporter->id],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('project.code', 'PRJ-ALK-001')
            ->assertJsonPath('reporters.0.id', $reporter->id);

        $projectId = (int) $response->json('project.id');

        $this->assertDatabaseHas('project_reporter_assignments', [
            'project_id' => $projectId,
            'reporter_id' => $reporter->id,
            'assigned_by' => $admin->id,
        ]);
    }
}
