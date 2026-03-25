<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Database\Seeders\RbacSeeder;
use App\Models\Municipality;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
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
            ->assertOk()
            ->assertJsonPath('message', 'User does not exist. Please create an account first.')
            ->assertJsonPath('requires_registration', true)
            ->assertJsonPath('user_exists', false)
            ->assertJsonPath('result', true)
            ->assertJsonPath('data.requires_registration', true);
    }

    public function test_request_otp_for_unknown_number_uses_preferred_locale_header(): void
    {
        $arabicResponse = $this->withHeaders([
            'preferred_locale' => 'ar',
        ])->postJson('/api/auth/request-otp', [
            'country_code' => '+218',
            'phone' => '933333334',
        ]);

        $arabicResponse
            ->assertOk()
            ->assertJsonPath('message', 'المستخدم غير موجود. يرجى إنشاء حساب أولًا.')
            ->assertJsonPath('msg', 'المستخدم غير موجود. يرجى إنشاء حساب أولًا.')
            ->assertJsonPath('requires_registration', true)
            ->assertJsonPath('result', true);

        $englishResponse = $this->withHeaders([
            'preferred_locale' => 'en',
        ])->postJson('/api/auth/request-otp', [
            'country_code' => '+218',
            'phone' => '933333334',
        ]);

        $englishResponse
            ->assertOk()
            ->assertJsonPath('message', 'User does not exist. Please create an account first.')
            ->assertJsonPath('msg', 'User does not exist. Please create an account first.')
            ->assertJsonPath('requires_registration', true)
            ->assertJsonPath('result', true);
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
            ->assertJsonPath('user.avatar_url', null)
            ->assertJsonPath('user.image_url', null)
            ->assertJsonPath('result', true)
            ->assertJsonStructure(['token', 'refresh_token', 'user', 'msg', 'data']);

        $this->assertSame($verifyResponse->json('token'), $verifyResponse->json('data.token'));
        $this->assertSame($verifyResponse->json('refresh_token'), $verifyResponse->json('data.refresh_token'));
        $this->assertSame($verifyResponse->json('user.id'), $verifyResponse->json('data.user.id'));

        $this->assertDatabaseHas('users', [
            'phone_e164' => '+218911111111',
            'role' => UserRole::REPORTER->value,
            'age' => 27,
        ]);
    }

    public function test_refresh_token_issues_new_access_and_refresh_tokens(): void
    {
        $user = User::factory()->create([
            'country_code' => '+218',
            'phone' => '933333333',
            'phone_e164' => '+218933333333',
            'status' => UserStatus::ACTIVE->value,
            'role' => UserRole::REPORTER->value,
            'avatar_path' => 'mobile/avatars/reporter.png',
        ]);

        $issuedRefreshToken = $user->createToken(
            'mobile-refresh-token',
            ['token:refresh'],
            now()->addDay(),
        );

        $response = $this->postJson('/api/auth/refresh-token', [
            'refresh_token' => $issuedRefreshToken->plainTextToken,
            'preferred_locale' => 'en',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('result', true)
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.avatar_url', url('/storage/mobile/avatars/reporter.png'))
            ->assertJsonPath('user.image_url', url('/storage/mobile/avatars/reporter.png'))
            ->assertJsonStructure([
                'token',
                'access_token',
                'refresh_token',
                'token_type',
                'expires_in',
                'refresh_expires_in',
                'user',
                'msg',
                'data',
            ]);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $issuedRefreshToken->accessToken->id,
        ]);

        $this->assertNotSame($issuedRefreshToken->plainTextToken, $response->json('refresh_token'));
    }

    public function test_auth_me_returns_unread_notifications_count(): void
    {
        $municipality = Municipality::query()->create([
            'name_en' => 'Tripoli',
            'name_ar' => 'طرابلس',
            'code' => 'TRI',
        ]);

        $user = User::factory()->create([
            'country_code' => '+218',
            'phone' => '944444444',
            'phone_e164' => '+218944444444',
            'status' => UserStatus::ACTIVE->value,
            'role' => UserRole::REPORTER->value,
            'municipality_id' => $municipality->id,
        ]);

        DatabaseNotification::query()->create([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\SubmissionStatusChangedNotification',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => [
                'title' => 'Unread 1',
            ],
        ]);

        DatabaseNotification::query()->create([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\SubmissionStatusChangedNotification',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => [
                'title' => 'Unread 2',
            ],
        ]);

        DatabaseNotification::query()->create([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\SubmissionStatusChangedNotification',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => [
                'title' => 'Read',
            ],
            'read_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/auth/me');

        $response
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('unread_notifications_count', 2)
            ->assertJsonPath('inbox.unread_count', 2)
            ->assertJsonPath('data.unread_notifications_count', 2)
            ->assertJsonPath('data.inbox.unread_count', 2);
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

    public function test_verify_otp_bypass_code_allows_seeded_user_login_without_existing_otp_row(): void
    {
        $this->seed(RbacSeeder::class);

        $focalPoint = User::factory()->create([
            'name' => 'Municipal Focal Point - Tripoli',
            'country_code' => '+218',
            'phone' => '910000003',
            'phone_e164' => '+218910000003',
            'role' => UserRole::MUNICIPAL_FOCAL_POINT->value,
            'status' => UserStatus::ACTIVE->value,
        ]);

        $this->assertNull(OtpCode::query()->where('phone_e164', $focalPoint->phone_e164)->first());

        $response = $this->postJson('/api/auth/verify-otp', [
            'country_code' => '+218',
            'phone' => '910000003',
            'code' => '111111',
            'preferred_locale' => 'en',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('user.id', $focalPoint->id)
            ->assertJsonPath('user.role', UserRole::MUNICIPAL_FOCAL_POINT->value)
            ->assertJsonPath('result', true);
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
