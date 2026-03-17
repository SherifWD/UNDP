<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuditLogApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_log_show_returns_human_readable_fields(): void
    {
        $this->seed(RbacSeeder::class);

        $auditor = User::factory()->create([
            'name' => 'Audit Officer',
            'role' => UserRole::AUDITOR->value,
        ]);

        Sanctum::actingAs($auditor);

        $log = AuditLog::query()->create([
            'actor_id' => $auditor->id,
            'action' => 'submissions.status_changed',
            'entity_type' => 'submissions',
            'entity_id' => '15',
            'before' => ['status' => 'under_review'],
            'after' => ['status' => 'approved'],
            'metadata' => [
                'project_id' => 3,
                'comment' => 'Approved after review.',
                'request_path' => 'api/submissions/15/approve',
                'request_method' => 'POST',
            ],
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/122.0.0.0 Safari/537.36',
            'created_at' => now(),
        ]);

        $response = $this->getJson("/api/audit-logs/{$log->id}");

        $response
            ->assertOk()
            ->assertJsonPath('data.action_label', 'Status changed')
            ->assertJsonPath('data.module_label', 'Submissions')
            ->assertJsonPath('data.subject_label', 'Submission #15')
            ->assertJsonPath('data.page_label', 'POST /api/submissions/15/approve')
            ->assertJsonPath('data.device_label', 'Chrome - Windows')
            ->assertJsonPath(
                'data.summary',
                'Audit Officer changed Submission #15 status from Under Review to Approved. Comment: Approved after review.'
            );

        $response->assertJsonFragment([
            'label' => 'Performed by',
            'value' => 'Audit Officer',
        ]);

        $response->assertJsonFragment([
            'field' => 'Status',
            'before' => 'Under Review',
            'after' => 'Approved',
        ]);

        $response->assertJsonFragment([
            'label' => 'Project ID',
            'value' => '#3',
        ]);

        $response->assertJsonFragment([
            'label' => 'Comment',
            'value' => 'Approved after review.',
        ]);
    }

    public function test_audit_logger_merges_request_context_into_metadata(): void
    {
        $this->seed(RbacSeeder::class);

        $admin = User::factory()->create([
            'role' => UserRole::UNDP_ADMIN->value,
        ]);

        $request = Request::create('/api/projects/9', 'PUT');
        $request->setUserResolver(static fn (): User => $admin);

        AuditLogger::log(
            action: 'projects.updated',
            entityType: 'projects',
            entityId: 9,
            metadata: [
                'assigned_reporter_ids' => [4, 7],
            ],
            request: $request,
            actor: $admin,
        );

        $log = AuditLog::query()->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame('api/projects/9', $log->metadata['request_path']);
        $this->assertSame('PUT', $log->metadata['request_method']);
        $this->assertSame([4, 7], $log->metadata['assigned_reporter_ids']);
    }
}
