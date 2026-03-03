<?php

namespace App\Http\Controllers\Mobile;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ProfileController extends MobileController
{
    public function show(Request $request): JsonResponse
    {
        $request->user()->loadMissing('municipality');

        return $this->successResponse([
            'profile' => $this->serializeProfile($request->user()),
            'available_genders' => [
                ['value' => 'male', 'label' => 'Man'],
                ['value' => 'female', 'label' => 'Woman'],
                ['value' => 'other', 'label' => 'Other'],
                ['value' => 'prefer_not_to_say', 'label' => 'Prefer not to say'],
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'gender' => ['sometimes', 'nullable', Rule::in(['male', 'female', 'other', 'prefer_not_to_say'])],
        ]);

        if ($validator->fails()) {
            return $this->returnValidationError($validator);
        }

        $validated = $validator->validated();
        $user = $request->user();

        $user->fill($validated);
        $user->save();
        $user->loadMissing('municipality');

        return $this->successResponse([
            'profile' => $this->serializeProfile($user),
        ], 'Profile updated successfully.');
    }

    public function uploadAvatar(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'avatar' => ['required', 'image', 'max:5120'],
        ]);

        if ($validator->fails()) {
            return $this->returnValidationError($validator);
        }

        $user = $request->user();

        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
        }

        $path = $request->file('avatar')->store('mobile/avatars', 'public');

        $user->forceFill([
            'avatar_path' => $path,
        ])->save();

        $user->loadMissing('municipality');

        return $this->successResponse([
            'profile' => $this->serializeProfile($user),
        ], 'Profile image updated successfully.');
    }

    private function serializeProfile($user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'phone' => $user->phone,
            'country_code' => $user->country_code,
            'phone_e164' => $user->phone_e164,
            'email' => $user->email,
            'role' => $user->role,
            'role_label' => ucwords(str_replace('_', ' ', $user->role)),
            'gender' => $user->gender,
            'gender_label' => match ($user->gender) {
                'male' => 'Man',
                'female' => 'Woman',
                'other' => 'Other',
                'prefer_not_to_say' => 'Prefer not to say',
                default => null,
            },
            'preferred_locale' => $user->preferred_locale,
            'avatar_url' => $user->avatar_path ? Storage::disk('public')->url($user->avatar_path) : null,
            'municipality' => $user->municipality ? [
                'id' => $user->municipality->id,
                'name' => $user->municipality->name,
                'name_en' => $user->municipality->name_en,
                'name_ar' => $user->municipality->name_ar,
            ] : null,
        ];
    }
}
