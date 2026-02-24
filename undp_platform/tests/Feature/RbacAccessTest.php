<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RbacAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_reporter_cannot_access_user_management_endpoint(): void
    {
        $reporter = User::factory()->create([
            'role' => UserRole::REPORTER->value,
        ]);

        Sanctum::actingAs($reporter);

        $response = $this->getJson('/api/users');

        $response->assertForbidden();
    }

    public function test_admin_can_access_user_management_endpoint(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::UNDP_ADMIN->value,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/users');

        $response->assertOk();
    }
}
