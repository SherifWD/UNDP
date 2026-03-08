<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Database\Seeders\RbacSeeder;
use App\Models\Municipality;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthOtpTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_otp_for_unknown_number_requires_registration(): void
    {
        $requestResponse = $this->postJson('/api/auth/request-otp', [
            'country_code' => '+218',
            'phone' => '911111111',
        ]);

        $requestResponse
            ->assertNotFound()
            ->assertJsonPath('message', 'User does not exist. Please create an account first.')
            ->assertJsonPath('requires_registration', true)
            ->assertJsonPath('user_exists', false)
            ->assertJsonPath('result', false);
    }

    public function test_register_reporter_then_request_and_verify_otp(): void
    {
        $municipality = Municipality::query()->create([
            'name_en' => 'Alkufraa',
            'name_ar' => 'الكفرة',
            'code' => 'ALK',
        ]);

        $registerResponse = $this->postJson('/api/auth/register-reporter', [
            'country_code' => '+218',
            'phone' => '911111111',
            'name' => 'New Reporter',
            'age' => 27,
            'gender' => 'male',
            'municipality_id' => $municipality->id,
            'preferred_locale' => 'ar',
        ]);

        $registerResponse
            ->assertCreated()
            ->assertJsonPath('user.role', UserRole::REPORTER->value)
            ->assertJsonPath('user.age', 27)
            ->assertJsonPath('user.gender', 'male')
            ->assertJsonPath('user.municipality.id', $municipality->id)
            ->assertJsonPath('next_step', 'request_otp')
            ->assertJsonPath('result', true);

        $requestResponse = $this->postJson('/api/auth/request-otp', [
            'country_code' => '+218',
            'phone' => '911111111',
        ]);

        $requestResponse
            ->assertOk()
            ->assertJsonStructure(['message', 'masked_phone', 'resend_in', 'expires_in', 'msg', 'data'])
            ->assertJsonPath('result', true)
            ->assertJsonPath('user_exists', true);

        $this->assertSame($requestResponse->json('message'), $requestResponse->json('msg'));
        $this->assertSame($requestResponse->json('masked_phone'), $requestResponse->json('data.masked_phone'));

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
            ->assertJsonPath('user.age', 27)
            ->assertJsonPath('user.gender', 'male')
            ->assertJsonPath('user.status', UserStatus::ACTIVE->value)
            ->assertJsonPath('result', true)
            ->assertJsonStructure(['token', 'user', 'msg', 'data']);

        $this->assertSame($verifyResponse->json('token'), $verifyResponse->json('data.token'));
        $this->assertSame($verifyResponse->json('user.id'), $verifyResponse->json('data.user.id'));

        $this->assertDatabaseHas('users', [
            'phone_e164' => '+218911111111',
            'role' => UserRole::REPORTER->value,
            'age' => 27,
        ]);
    }

    public function test_registration_meta_returns_municipalities_and_gender_options(): void
    {
        $municipality = Municipality::query()->create([
            'name_en' => 'Tripoli',
            'name_ar' => 'طرابلس',
            'code' => 'TRI',
        ]);

        $response = $this->getJson('/api/auth/registration-meta');

        $response
            ->assertOk()
            ->assertJsonPath('result', true)
            ->assertJsonPath('data.default_country_code', '+218')
            ->assertJsonPath('data.municipalities.0.id', $municipality->id)
            ->assertJsonPath('data.genders.0.value', 'male');
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
            ->assertJsonPath('message', 'Your account is disabled. Please contact an administrator.')
            ->assertJsonPath('result', false);

        $this->assertSame([], $response->json('data'));

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
            ->assertJsonPath('user.role', UserRole::UNDP_ADMIN->value)
            ->assertJsonPath('result', true);

        $this->assertSame(1, User::query()->where('phone_e164', '+218910000001')->count());
    }

    public function test_validation_errors_are_wrapped_in_the_standard_api_envelope(): void
    {
        $response = $this->postJson('/api/auth/request-otp', []);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('result', false)
            ->assertJsonStructure(['message', 'msg', 'errors', 'data']);

        $this->assertSame($response->json('errors'), $response->json('data.errors'));
    }
}
