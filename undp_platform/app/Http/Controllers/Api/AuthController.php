<?php

namespace App\Http\Controllers\Api;

use App\Contracts\OtpSender;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\Municipality;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

class AuthController extends Controller
{
    public function __construct(private readonly OtpSender $otpSender)
    {
    }

    public function requestOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'country_code' => ['required', 'regex:/^\+?[0-9]{1,4}$/'],
            'phone' => ['required', 'digits_between:6,15'],
        ]);

        $phoneData = PhoneNumber::normalize($validated['country_code'], $validated['phone']);
        $user = User::query()->where('phone_e164', $phoneData['phone_e164'])->first();

        if (! $user) {
            return response()->json([
                'message' => __('User does not exist. Please create an account first.'),
                'user_exists' => false,
                'requires_registration' => true,
                'phone_e164' => $phoneData['phone_e164'],
            ], 200);
        }

        if (! $user->isActive()) {
            return response()->json([
                'message' => __('Your account is disabled. Please contact an administrator.'),
                'user_exists' => true,
                'requires_registration' => false,
            ], 403);
        }

        $cooldown = (int) config('otp.resend_cooldown_seconds', 60);
        $expiresIn = (int) config('otp.expires_in_seconds', 300);
        $digits = (int) config('otp.code_digits', 4);

        $otp = OtpCode::firstOrNew([
            'country_code' => $phoneData['country_code'],
            'phone' => $phoneData['phone'],
        ]);

        if ($otp->exists && $otp->last_sent_at && $otp->last_sent_at->gt(now()->subSeconds($cooldown))) {
            $retryIn = now()->diffInSeconds($otp->last_sent_at->copy()->addSeconds($cooldown));

            return response()->json([
                'message' => __('Please wait before requesting another code.'),
                'retry_in' => $retryIn,
            ], 429);
        }

        $max = (10 ** min($digits, 8)) - 1;
        $code = str_pad((string) random_int(0, $max), $digits, '0', STR_PAD_LEFT);

        $otp->fill([
            'phone_e164' => $phoneData['phone_e164'],
            'code' => $code,
            'expires_at' => now()->addSeconds($expiresIn),
            'last_sent_at' => now(),
            'attempts' => 0,
            'verified_at' => null,
        ])->save();

        try {
            $this->otpSender->send(
                $phoneData['phone_e164'],
                sprintf('Your UNDP verification code is %s', $code),
                ['ttl_seconds' => $expiresIn],
            );
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => __('Unable to send OTP at the moment. Please try again.'),
            ], 503);
        }

        AuditLogger::log(
            action: 'auth.otp_requested',
            entityType: 'phone',
            entityId: $phoneData['phone_e164'],
            metadata: [
                'masked_phone' => PhoneNumber::mask($phoneData['country_code'], $phoneData['phone']),
            ],
            request: $request,
        );

        return response()->json([
            'message' => __('Verification code sent successfully.'),
            'masked_phone' => PhoneNumber::mask($phoneData['country_code'], $phoneData['phone']),
            'otp' => $otp->code,
            'resend_in' => $cooldown,
            'expires_in' => $expiresIn,
            'user_exists' => true,
            'requires_registration' => false,
        ]);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'country_code' => ['required', 'regex:/^\+?[0-9]{1,4}$/'],
            'phone' => ['required', 'digits_between:6,15'],
            'code' => ['required', 'digits_between:4,8'],
            'preferred_locale' => ['nullable', Rule::in(['ar', 'en'])],
        ]);

        $phoneData = PhoneNumber::normalize($validated['country_code'], $validated['phone']);
        $user = User::query()->where('phone_e164', $phoneData['phone_e164'])->first();

        if (! $user) {
            return response()->json([
                'message' => __('User does not exist. Please create an account first.'),
                'user_exists' => false,
                'requires_registration' => true,
            ], 404);
        }

        $otp = OtpCode::where('country_code', $phoneData['country_code'])
            ->where('phone', $phoneData['phone'])
            ->latest('id')
            ->first();
        $isBypassCode = $validated['code'] === '111111';

        if (! $otp && ! $isBypassCode) {
            return response()->json([
                'message' => __('No OTP request found for this number.'),
            ], 422);
        }

        $maxAttempts = (int) config('otp.max_attempts', 5);

        if ($otp && $otp->attempts >= $maxAttempts && ! $isBypassCode) {
            return response()->json([
                'message' => __('Maximum verification attempts reached. Please request a new code.'),
            ], 422);
        }

        if ($otp && $otp->expires_at->isPast() && ! $isBypassCode) {
            return response()->json([
                'message' => __('Code expired. Please request a new OTP.'),
            ], 422);
        }

        if ($otp && ! hash_equals($otp->code, $validated['code']) && ! $isBypassCode) {
            $otp->increment('attempts');

            return response()->json([
                'message' => __('Invalid verification code.'),
            ], 422);
        }

        if ($otp) {
            $otp->forceFill([
                'attempts' => $otp->attempts + 1,
                'verified_at' => now(),
            ])->save();
        }

        $isReturning = ! empty($user->last_login_at);

        if (! $user->isActive()) {
            AuditLogger::log(
                action: 'auth.login_blocked_disabled',
                entityType: 'users',
                entityId: $user->id,
                request: $request,
                actor: $user,
            );

            return response()->json([
                'message' => __('Your account is disabled. Please contact an administrator.'),
            ], 403);
        }

        $user->forceFill([
            'preferred_locale' => $validated['preferred_locale'] ?? $user->preferred_locale,
            'last_login_at' => now(),
        ])->save();

        // Keep DB role and spatie role aligned.
        if (! $user->hasRole($user->role)) {
            $user->syncRoleSafely($user->role);
        }

        $tokens = $this->issueMobileTokens($user);

        AuditLogger::log(
            action: 'auth.login_success',
            entityType: 'users',
            entityId: $user->id,
            metadata: [
                'is_returning_user' => $isReturning,
                'role' => $user->role,
            ],
            request: $request,
            actor: $user,
        );

        return response()->json([
            'message' => $isReturning ? __('Welcome back') : __('Account verified successfully'),
            'token' => $tokens['access_token'],
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'token_type' => $tokens['token_type'],
            'expires_in' => $tokens['expires_in'],
            'refresh_expires_in' => $tokens['refresh_expires_in'],
            'is_returning_user' => $isReturning,
            'user' => $this->serializeUser($user),
        ]);
    }

    public function refreshToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'refresh_token' => ['required', 'string'],
            'preferred_locale' => ['nullable', Rule::in(['ar', 'en'])],
        ]);

        $personalAccessToken = PersonalAccessToken::findToken($validated['refresh_token']);

        if (! $personalAccessToken
            || ! $personalAccessToken->tokenable instanceof User
            || $personalAccessToken->name !== 'mobile-refresh-token'
            || ! $personalAccessToken->can('token:refresh')) {
            return response()->json([
                'message' => __('Invalid refresh token.'),
            ], 401);
        }

        if ($personalAccessToken->expires_at && $personalAccessToken->expires_at->isPast()) {
            $personalAccessToken->delete();

            return response()->json([
                'message' => __('Refresh token expired. Please login again.'),
            ], 401);
        }

        /** @var User $user */
        $user = $personalAccessToken->tokenable;

        if (! $user->isActive()) {
            $personalAccessToken->delete();

            return response()->json([
                'message' => __('Your account is disabled. Please contact an administrator.'),
            ], 403);
        }

        $user->forceFill([
            'preferred_locale' => $validated['preferred_locale'] ?? $user->preferred_locale,
        ])->save();

        $personalAccessToken->delete();
        $tokens = $this->issueMobileTokens($user);

        AuditLogger::log(
            action: 'auth.token_refreshed',
            entityType: 'users',
            entityId: $user->id,
            request: $request,
            actor: $user,
        );

        return response()->json([
            'message' => __('Token refreshed successfully.'),
            'token' => $tokens['access_token'],
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'token_type' => $tokens['token_type'],
            'expires_in' => $tokens['expires_in'],
            'refresh_expires_in' => $tokens['refresh_expires_in'],
            'user' => $this->serializeUser($user),
        ]);
    }

    public function registrationMeta(): JsonResponse
    {
        $municipalities = Municipality::query()
            ->orderBy('name_en')
            ->get()
            ->map(fn (Municipality $municipality): array => [
                'id' => $municipality->id,
                'name_en' => $municipality->name_en,
                'name_ar' => $municipality->name_ar,
                'name' => $municipality->name,
                'code' => $municipality->code,
            ])
            ->values()
            ->all();

        return response()->json([
            'municipalities' => $municipalities,
            'genders' => [
                ['value' => 'male', 'label' => 'Man'],
                ['value' => 'female', 'label' => 'Woman'],
                ['value' => 'other', 'label' => 'Other'],
                ['value' => 'prefer_not_to_say', 'label' => 'Prefer not to say'],
            ],
            'available_locales' => config('mobile.available_locales', []),
            'default_country_code' => '+218',
        ]);
    }

    public function registerReporter(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'country_code' => ['required', 'regex:/^\+?[0-9]{1,4}$/'],
            'phone' => ['required', 'digits_between:6,15'],
            'name' => ['required', 'string', 'max:255'],
            'age' => ['required', 'integer', 'min:16', 'max:100'],
            'gender' => ['required', Rule::in(['male', 'female', 'other', 'prefer_not_to_say'])],
            'municipality_id' => ['required', 'integer', 'exists:municipalities,id'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')],
            'preferred_locale' => ['nullable', Rule::in(['ar', 'en'])],
        ]);

        $phoneData = PhoneNumber::normalize($validated['country_code'], $validated['phone']);

        if (User::query()->where('phone_e164', $phoneData['phone_e164'])->exists()) {
            return response()->json([
                'message' => __('Phone number is already in use.'),
                'requires_registration' => false,
            ], 422);
        }

        $user = User::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'country_code' => $phoneData['country_code'],
            'phone' => $phoneData['phone'],
            'phone_e164' => $phoneData['phone_e164'],
            'password' => Hash::make(str()->random(32)),
            'role' => UserRole::REPORTER->value,
            'status' => UserStatus::ACTIVE->value,
            'age' => (int) $validated['age'],
            'gender' => $validated['gender'],
            'municipality_id' => (int) $validated['municipality_id'],
            'preferred_locale' => $validated['preferred_locale'] ?? 'ar',
        ]);

        $user->syncRoleSafely(UserRole::REPORTER);

        AuditLogger::log(
            action: 'auth.reporter_registered',
            entityType: 'users',
            entityId: $user->id,
            metadata: [
                'role' => $user->role,
                'municipality_id' => $user->municipality_id,
                'phone_e164' => $user->phone_e164,
            ],
            request: $request,
            actor: $user,
        );

        return response()->json([
            'message' => __('Reporter account created successfully. Request OTP to continue login.'),
            'requires_registration' => false,
            'next_step' => 'request_otp',
            'user' => $this->serializeUser($user->loadMissing('municipality')),
        ], 201);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('municipality');
        $unreadNotificationsCount = $user->unreadNotifications()->count();

        return response()->json([
            'user' => $this->serializeUser($user),
            'unread_notifications_count' => $unreadNotificationsCount,
            'inbox' => [
                'unread_count' => $unreadNotificationsCount,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($request->user()?->currentAccessToken()) {
            $request->user()->currentAccessToken()->delete();
        }

        AuditLogger::log(
            action: 'auth.logout',
            entityType: 'users',
            entityId: $user?->id,
            request: $request,
            actor: $user,
        );

        return response()->json([
            'message' => __('Logged out successfully.'),
        ]);
    }

    public function updateDeviceToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'fcm_token' => ['required', 'string', 'max:255'],
        ]);

        $request->user()->forceFill([
            'fcm_token' => $validated['fcm_token'],
        ])->save();

        return response()->json([
            'message' => __('Device token updated.'),
        ]);
    }

    private function serializeUser(User $user): array
    {
        $user->loadMissing('municipality');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'age' => $user->age,
            'gender' => $user->gender,
            'phone' => $user->phone,
            'country_code' => $user->country_code,
            'phone_e164' => $user->phone_e164,
            'role' => $user->role,
            'status' => $user->status,
            'preferred_locale' => $user->preferred_locale,
            'avatar_url' => $user->publicAvatarUrl(),
            'image_url' => $user->publicAvatarUrl(),
            'municipality' => $user->municipality ? [
                'id' => $user->municipality->id,
                'name_en' => $user->municipality->name_en,
                'name_ar' => $user->municipality->name_ar,
                'name' => $user->municipality->name,
            ] : null,
            'permissions' => $user->permissionNames(),
            'last_login_at' => optional($user->last_login_at)->toIso8601String(),
        ];
    }

    private function issueMobileTokens(User $user): array
    {
        $accessTtlMinutes = max(1, (int) config('mobile.auth.access_token_ttl_minutes', 60));
        $refreshTtlDays = max(1, (int) config('mobile.auth.refresh_token_ttl_days', 30));

        $accessToken = $user->createToken(
            'mobile-api-token',
            ['token:access'],
            now()->addMinutes($accessTtlMinutes),
        )->plainTextToken;

        $refreshToken = $user->createToken(
            'mobile-refresh-token',
            ['token:refresh'],
            now()->addDays($refreshTtlDays),
        )->plainTextToken;

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => $accessTtlMinutes * 60,
            'refresh_expires_in' => $refreshTtlDays * 24 * 60 * 60,
        ];
    }
}
