<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Municipality;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserRoleScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_cannot_create_reporter_without_municipality_scope(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::UNDP_ADMIN->value,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/users', [
            'name' => 'Scoped Reporter',
            'email' => 'scoped.reporter@undp.local',
            'country_code' => '+218',
            'phone' => '910001111',
            'role' => UserRole::REPORTER->value,
            'municipality_id' => null,
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['municipality_id']);
    }

    public function test_admin_can_create_focal_point_when_municipality_scope_is_provided(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::UNDP_ADMIN->value,
        ]);

        $municipality = Municipality::query()->create([
            'name_en' => 'Tripoli',
            'name_ar' => 'طرابلس',
            'code' => 'TRI',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/users', [
            'name' => 'Tripoli Focal',
            'email' => 'tripoli.focal@undp.local',
            'country_code' => '+218',
            'phone' => '910001112',
            'role' => UserRole::MUNICIPAL_FOCAL_POINT->value,
            'municipality_id' => $municipality->id,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('user.role', UserRole::MUNICIPAL_FOCAL_POINT->value)
            ->assertJsonPath('user.municipality.id', $municipality->id);
    }

    public function test_admin_cannot_remove_municipality_scope_from_focal_point(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::UNDP_ADMIN->value,
        ]);

        $municipality = Municipality::query()->create([
            'name_en' => 'Tripoli',
            'name_ar' => 'طرابلس',
            'code' => 'TRI',
        ]);

        $focal = User::factory()->create([
            'role' => UserRole::MUNICIPAL_FOCAL_POINT->value,
            'municipality_id' => $municipality->id,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->putJson("/api/users/{$focal->id}", [
            'municipality_id' => null,
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['municipality_id']);
    }
}
