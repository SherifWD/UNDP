<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'role' => ['nullable', Rule::in(UserRole::values())],
            'status' => ['nullable', Rule::in(UserStatus::values())],
            'municipality_id' => ['nullable', 'integer', 'exists:municipalities,id'],
            'sort_by' => ['nullable', Rule::in(['name', 'email', 'phone', 'role', 'status', 'created_at', 'last_login_at'])],
            'sort_dir' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $query = User::query()->with('municipality');

        if (! empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone_e164', 'like', "%{$search}%");
            });
        }

        if (! empty($validated['role'])) {
            $query->where('role', $validated['role']);
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['municipality_id'])) {
            $query->where('municipality_id', $validated['municipality_id']);
        }

        $sortBy = $validated['sort_by'] ?? 'created_at';
        $sortDir = $validated['sort_dir'] ?? 'desc';

        $users = $query
            ->orderBy($sortBy, $sortDir)
            ->paginate($validated['per_page'] ?? 15)
            ->through(fn (User $user): array => $this->serializeUser($user));

        return response()->json($users);
    }

    public function roles(): JsonResponse
    {
        $roles = collect(config('rbac.roles'))
            ->map(fn (array $definition, string $role): array => [
                'value' => $role,
                'label' => $definition['label'] ?? $role,
                'description' => $definition['description'] ?? null,
                'permissions' => $definition['permissions'] ?? [],
            ])
            ->values();

        return response()->json([
            'data' => $roles,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')],
            'country_code' => ['required', 'regex:/^\+?[0-9]{1,4}$/'],
            'phone' => ['required', 'digits_between:6,15'],
            'role' => ['required', Rule::in(UserRole::values())],
            'municipality_id' => ['nullable', 'integer', 'exists:municipalities,id'],
            'preferred_locale' => ['nullable', Rule::in(['ar', 'en'])],
        ]);

        $phoneData = PhoneNumber::normalize($validated['country_code'], $validated['phone']);

        if (User::where('phone_e164', $phoneData['phone_e164'])->exists()) {
            return response()->json([
                'message' => __('Phone number is already in use.'),
            ], 422);
        }

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'country_code' => $phoneData['country_code'],
            'phone' => $phoneData['phone'],
            'phone_e164' => $phoneData['phone_e164'],
            'role' => $validated['role'],
            'status' => UserStatus::ACTIVE->value,
            'municipality_id' => $validated['municipality_id'] ?? null,
            'preferred_locale' => $validated['preferred_locale'] ?? 'ar',
            'password' => Hash::make(str()->random(32)),
        ]);

        $user->syncRoleSafely($validated['role']);

        AuditLogger::log(
            action: 'users.created',
            entityType: 'users',
            entityId: $user->id,
            after: $user->only(['name', 'email', 'phone_e164', 'role', 'status', 'municipality_id']),
            request: $request,
        );

        return response()->json([
            'message' => __('User created successfully.'),
            'user' => $this->serializeUser($user->load('municipality')),
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'country_code' => ['sometimes', 'required', 'regex:/^\+?[0-9]{1,4}$/'],
            'phone' => ['sometimes', 'required', 'digits_between:6,15'],
            'role' => ['sometimes', 'required', Rule::in(UserRole::values())],
            'municipality_id' => ['sometimes', 'nullable', 'integer', 'exists:municipalities,id'],
            'preferred_locale' => ['sometimes', Rule::in(['ar', 'en'])],
            'confirm_role_change' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('role', $validated) && $validated['role'] !== $user->role && ! ($validated['confirm_role_change'] ?? false)) {
            return response()->json([
                'message' => __('Role change requires confirmation.'),
            ], 422);
        }

        $before = $user->only(['name', 'email', 'phone_e164', 'role', 'status', 'municipality_id', 'preferred_locale']);

        if (array_key_exists('country_code', $validated) || array_key_exists('phone', $validated)) {
            $countryCode = $validated['country_code'] ?? $user->country_code;
            $phone = $validated['phone'] ?? $user->phone;

            $phoneData = PhoneNumber::normalize($countryCode, $phone);

            $exists = User::where('phone_e164', $phoneData['phone_e164'])
                ->where('id', '!=', $user->id)
                ->exists();

            if ($exists) {
                return response()->json([
                    'message' => __('Phone number is already in use.'),
                ], 422);
            }

            $validated['country_code'] = $phoneData['country_code'];
            $validated['phone'] = $phoneData['phone'];
            $validated['phone_e164'] = $phoneData['phone_e164'];
        }

        unset($validated['confirm_role_change']);

        $user->fill($validated);

        if (! $user->isDirty()) {
            return response()->json([
                'message' => __('No changes detected.'),
                'user' => $this->serializeUser($user->load('municipality')),
            ]);
        }

        $user->save();

        if (array_key_exists('role', $validated)) {
            $user->syncRoleSafely($validated['role']);
        }

        AuditLogger::log(
            action: 'users.updated',
            entityType: 'users',
            entityId: $user->id,
            before: $before,
            after: $user->only(['name', 'email', 'phone_e164', 'role', 'status', 'municipality_id', 'preferred_locale']),
            request: $request,
        );

        return response()->json([
            'message' => __('User updated successfully.'),
            'user' => $this->serializeUser($user->load('municipality')),
        ]);
    }

    public function updateStatus(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(UserStatus::values())],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $before = $user->only(['status', 'disabled_at', 'disabled_reason']);

        $user->status = $validated['status'];
        $user->disabled_reason = $validated['status'] === UserStatus::DISABLED->value
            ? ($validated['reason'] ?? 'Disabled by admin')
            : null;
        $user->disabled_at = $validated['status'] === UserStatus::DISABLED->value ? now() : null;
        $user->save();

        if ($validated['status'] === UserStatus::DISABLED->value) {
            $user->tokens()->delete();
        }

        AuditLogger::log(
            action: $user->status === UserStatus::DISABLED->value ? 'users.disabled' : 'users.enabled',
            entityType: 'users',
            entityId: $user->id,
            before: $before,
            after: $user->only(['status', 'disabled_at', 'disabled_reason']),
            request: $request,
        );

        return response()->json([
            'message' => __('User status updated successfully.'),
            'user' => $this->serializeUser($user->load('municipality')),
        ]);
    }

    public function audit(Request $request, User $user): JsonResponse
    {
        $logs = AuditLog::with('actor:id,name,role')
            ->where(function ($query) use ($user): void {
                $query
                    ->where('entity_type', 'users')
                    ->where('entity_id', (string) $user->id)
                    ->orWhere('actor_id', $user->id);
            })
            ->latest('created_at')
            ->paginate(20);

        return response()->json($logs);
    }

    private function serializeUser(User $user): array
    {
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
            'disabled_at' => optional($user->disabled_at)->toIso8601String(),
            'disabled_reason' => $user->disabled_reason,
            'municipality' => $user->municipality ? [
                'id' => $user->municipality->id,
                'name_en' => $user->municipality->name_en,
                'name_ar' => $user->municipality->name_ar,
                'name' => $user->municipality->name,
            ] : null,
            'last_login_at' => optional($user->last_login_at)->toIso8601String(),
            'created_at' => optional($user->created_at)->toIso8601String(),
        ];
    }
}
