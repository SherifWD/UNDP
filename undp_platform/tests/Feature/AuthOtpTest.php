<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Database\Seeders\RbacSeeder;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthOtpTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_and_verify_otp_creates_reporter_user(): void
    {
        $requestResponse = $this->postJson('/api/auth/request-otp', [
            'country_code' => '+218',
            'phone' => '911111111',
        ]);

        $requestResponse
            ->assertOk()
            ->assertJsonStructure(['message', 'masked_phone', 'resend_in', 'expires_in']);

        $otp = OtpCode::query()->where('phone_e164', '+218911111111')->first();

        $this->assertNotNull($otp);

        $verifyResponse = $this->postJson('/api/auth/verify-otp', [
            'country_code' => '+218',
            'phone' => '911111111',
            'code' => $otp->code,
            'preferred_locale' => 'ar',
        ]);

        $verifyResponse
            ->assertOk()
            ->assertJsonPath('user.role', UserRole::REPORTER->value)
            ->assertJsonPath('user.status', UserStatus::ACTIVE->value)
            ->assertJsonStructure(['token', 'user']);

        $this->assertDatabaseHas('users', [
            'phone_e164' => '+218911111111',
            'role' => UserRole::REPORTER->value,
        ]);
    }

    public function test_disabled_user_cannot_login_via_otp(): void
    {
        $user = User::factory()->create([
            'country_code' => '+218',
            'phone' => '922222222',
            'phone_e164' => '+218922222222',
            'status' => UserStatus::DISABLED->value,
            'role' => UserRole::REPORTER->value,
        ]);

        OtpCode::query()->create([
            'country_code' => '+218',
            'phone' => '922222222',
            'phone_e164' => '+218922222222',
            'code' => '123456',
            'expires_at' => now()->addMinutes(5),
            'last_sent_at' => now(),
            'attempts' => 0,
        ]);

        $response = $this->postJson('/api/auth/verify-otp', [
            'country_code' => '+218',
            'phone' => '922222222',
            'code' => '123456',
        ]);

        $response
            ->assertForbidden()
            ->assertJsonPath('message', 'Your account is disabled. Please contact an administrator.');

        $this->assertSame($user->id, User::query()->where('phone_e164', '+218922222222')->value('id'));
    }

    public function test_existing_admin_login_accepts_local_leading_zero_format(): void
    {
        $this->seed(RbacSeeder::class);

        $admin = User::factory()->create([
            'name' => 'UNDP Admin',
            'country_code' => '+218',
            'phone' => '910000001',
            'phone_e164' => '+218910000001',
            'role' => UserRole::UNDP_ADMIN->value,
            'status' => UserStatus::ACTIVE->value,
        ]);

        OtpCode::query()->create([
            'country_code' => '+218',
            'phone' => '910000001',
            'phone_e164' => '+218910000001',
            'code' => '123456',
            'expires_at' => now()->addMinutes(5),
            'last_sent_at' => now(),
            'attempts' => 0,
        ]);

        $response = $this->postJson('/api/auth/verify-otp', [
            'country_code' => '+218',
            'phone' => '0910000001',
            'code' => '123456',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('user.id', $admin->id)
            ->assertJsonPath('user.role', UserRole::UNDP_ADMIN->value);

        $this->assertSame(1, User::query()->where('phone_e164', '+218910000001')->count());
    }
}
