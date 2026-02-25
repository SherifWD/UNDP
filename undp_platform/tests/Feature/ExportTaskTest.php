<?php

namespace Tests\Feature;

use App\Jobs\GenerateExportTaskJob;
use App\Enums\UserRole;
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
}
