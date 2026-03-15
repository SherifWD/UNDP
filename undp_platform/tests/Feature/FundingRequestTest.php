<?php

namespace Tests\Feature;

use App\Enums\FundingRequestStatus;
use App\Enums\UserRole;
use App\Models\FundingRequest;
use App\Models\Municipality;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FundingRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_donor_can_create_and_list_own_funding_requests(): void
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

        $donor = User::factory()->create([
            'role' => UserRole::PARTNER_DONOR_VIEWER->value,
        ]);

        $otherDonor = User::factory()->create([
            'role' => UserRole::PARTNER_DONOR_VIEWER->value,
        ]);

        FundingRequest::query()->create([
            'project_id' => $project->id,
            'donor_user_id' => $otherDonor->id,
            'amount' => 5000,
            'currency' => 'USD',
            'status' => FundingRequestStatus::PENDING->value,
        ]);

        Sanctum::actingAs($donor);

        $createResponse = $this->postJson('/api/funding-requests', [
            'project_id' => $project->id,
            'amount' => 12000,
            'currency' => 'usd',
            'reason' => 'Optional donor note',
        ]);

        $createResponse
            ->assertCreated()
            ->assertJsonPath('funding_request.status', FundingRequestStatus::PENDING->value)
            ->assertJsonPath('funding_request.amount', 12000)
            ->assertJsonPath('funding_request.currency', 'USD');

        $indexResponse = $this->getJson('/api/funding-requests');

        $indexResponse->assertOk();
        $this->assertCount(1, $indexResponse->json('data'));
        $this->assertSame($donor->id, (int) $indexResponse->json('data.0.donor.id'));
    }

    public function test_admin_can_approve_and_decline_pending_funding_requests(): void
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
        ]);

        $donor = User::factory()->create([
            'role' => UserRole::PARTNER_DONOR_VIEWER->value,
        ]);

        $admin = User::factory()->create([
            'role' => UserRole::UNDP_ADMIN->value,
        ]);

        $toApprove = FundingRequest::query()->create([
            'project_id' => $project->id,
            'donor_user_id' => $donor->id,
            'amount' => 10000,
            'currency' => 'USD',
            'status' => FundingRequestStatus::PENDING->value,
        ]);

        $toDecline = FundingRequest::query()->create([
            'project_id' => $project->id,
            'donor_user_id' => $donor->id,
            'amount' => 3000,
            'currency' => 'USD',
            'status' => FundingRequestStatus::PENDING->value,
        ]);

        Sanctum::actingAs($admin);

        $approveResponse = $this->postJson("/api/funding-requests/{$toApprove->id}/approve", [
            'review_comment' => 'Approved for this cycle',
        ]);

        $approveResponse
            ->assertOk()
            ->assertJsonPath('funding_request.status', FundingRequestStatus::APPROVED->value);

        $declineResponse = $this->postJson("/api/funding-requests/{$toDecline->id}/decline", [
            'review_comment' => 'Insufficient supporting detail',
        ]);

        $declineResponse
            ->assertOk()
            ->assertJsonPath('funding_request.status', FundingRequestStatus::DECLINED->value);

        $repeatReview = $this->postJson("/api/funding-requests/{$toApprove->id}/decline");
        $repeatReview->assertStatus(422);
    }

    public function test_admin_review_requires_reason(): void
    {
        $municipality = Municipality::query()->create([
            'name_en' => 'Tripoli',
            'name_ar' => 'طرابلس',
            'code' => 'TRI',
        ]);

        $project = Project::query()->create([
            'municipality_id' => $municipality->id,
            'name_en' => 'Roads Upgrade',
            'name_ar' => 'تطوير الطرق',
            'status' => 'active',
        ]);

        $donor = User::factory()->create([
            'role' => UserRole::PARTNER_DONOR_VIEWER->value,
        ]);

        $admin = User::factory()->create([
            'role' => UserRole::UNDP_ADMIN->value,
        ]);

        $request = FundingRequest::query()->create([
            'project_id' => $project->id,
            'donor_user_id' => $donor->id,
            'amount' => 4500,
            'currency' => 'USD',
            'status' => FundingRequestStatus::PENDING->value,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/funding-requests/{$request->id}/approve", []);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['review_comment']);
    }

    public function test_donor_cannot_review_funding_requests(): void
    {
        $municipality = Municipality::query()->create([
            'name_en' => 'Misrata',
            'name_ar' => 'مصراتة',
            'code' => 'MIS',
        ]);

        $project = Project::query()->create([
            'municipality_id' => $municipality->id,
            'name_en' => 'Clinic Upgrade',
            'name_ar' => 'تحديث العيادة',
            'status' => 'active',
        ]);

        $donor = User::factory()->create([
            'role' => UserRole::PARTNER_DONOR_VIEWER->value,
        ]);

        $request = FundingRequest::query()->create([
            'project_id' => $project->id,
            'donor_user_id' => $donor->id,
            'amount' => 2500,
            'currency' => 'USD',
            'status' => FundingRequestStatus::PENDING->value,
        ]);

        Sanctum::actingAs($donor);

        $response = $this->postJson("/api/funding-requests/{$request->id}/approve");

        $response->assertForbidden();
    }
}
