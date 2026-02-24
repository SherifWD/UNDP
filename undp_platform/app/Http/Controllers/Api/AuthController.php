<?php

namespace App\Http\Controllers\Api;

use App\Contracts\OtpSender;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
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

        $cooldown = (int) config('otp.resend_cooldown_seconds', 60);
        $expiresIn = (int) config('otp.expires_in_seconds', 300);
        $digits = (int) config('otp.code_digits', 6);

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
            'resend_in' => $cooldown,
            'expires_in' => $expiresIn,
        ]);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'country_code' => ['required', 'regex:/^\+?[0-9]{1,4}$/'],
            'phone' => ['required', 'digits_between:6,15'],
            'code' => ['required', 'digits_between:4,8'],
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'preferred_locale' => ['nullable', Rule::in(['ar', 'en'])],
        ]);

        $phoneData = PhoneNumber::normalize($validated['country_code'], $validated['phone']);

        $otp = OtpCode::where('country_code', $phoneData['country_code'])
            ->where('phone', $phoneData['phone'])
            ->latest('id')
            ->first();

        if (! $otp) {
            return response()->json([
                'message' => __('No OTP request found for this number.'),
            ], 422);
        }

        $maxAttempts = (int) config('otp.max_attempts', 5);

        if ($otp->attempts >= $maxAttempts) {
            return response()->json([
                'message' => __('Maximum verification attempts reached. Please request a new code.'),
            ], 422);
        }

        if ($otp->expires_at->isPast()) {
            return response()->json([
                'message' => __('Code expired. Please request a new OTP.'),
            ], 422);
        }

        if (! hash_equals($otp->code, $validated['code'])) {
            $otp->increment('attempts');

            return response()->json([
                'message' => __('Invalid verification code.'),
            ], 422);
        }

        if (! empty($validated['email'])) {
            $emailTaken = User::query()
                ->where('email', $validated['email'])
                ->where('phone_e164', '!=', $phoneData['phone_e164'])
                ->exists();

            if ($emailTaken) {
                return response()->json([
                    'message' => __('Email is already in use.'),
                ], 422);
            }
        }

        $otp->forceFill([
            'attempts' => $otp->attempts + 1,
            'verified_at' => now(),
        ])->save();

        $user = User::where('phone_e164', $phoneData['phone_e164'])->first();
        $isReturning = (bool) $user;

        if (! $user) {
            $user = User::create([
                'name' => $validated['name'] ?? 'Community Reporter',
                'email' => $validated['email'] ?? null,
                'country_code' => $phoneData['country_code'],
                'phone' => $phoneData['phone'],
                'phone_e164' => $phoneData['phone_e164'],
                'password' => Hash::make(str()->random(32)),
                'role' => UserRole::REPORTER->value,
                'status' => UserStatus::ACTIVE->value,
                'preferred_locale' => $validated['preferred_locale'] ?? 'ar',
            ]);

            $user->syncRoleSafely(UserRole::REPORTER);
        }

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

        $token = $user->createToken('mobile-api-token')->plainTextToken;

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
            'token' => $token,
            'token_type' => 'Bearer',
            'is_returning_user' => $isReturning,
            'user' => $this->serializeUser($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('municipality');

        return response()->json([
            'user' => $this->serializeUser($user),
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
            'phone' => $user->phone,
            'country_code' => $user->country_code,
            'phone_e164' => $user->phone_e164,
            'role' => $user->role,
            'status' => $user->status,
            'preferred_locale' => $user->preferred_locale,
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
}
