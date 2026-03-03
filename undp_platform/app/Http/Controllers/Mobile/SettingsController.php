<?php

namespace App\Http\Controllers\Mobile;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SettingsController extends MobileController
{
    public function show(Request $request): JsonResponse
    {
        return $this->successResponse([
            'language' => [
                'selected' => $request->user()->preferred_locale,
                'available' => config('mobile.available_locales', []),
            ],
            'permissions' => config('mobile.permissions', []),
            'actions' => [
                'can_logout' => true,
            ],
        ]);
    }

    public function updateLanguage(Request $request): JsonResponse
    {
        $allowedLocales = collect(config('mobile.available_locales', []))
            ->pluck('code')
            ->filter(fn ($value): bool => is_string($value) && $value !== '')
            ->values()
            ->all();

        $validator = Validator::make($request->all(), [
            'preferred_locale' => ['required', Rule::in($allowedLocales)],
        ]);

        if ($validator->fails()) {
            return $this->returnValidationError($validator);
        }

        $request->user()->forceFill([
            'preferred_locale' => $validator->validated()['preferred_locale'],
        ])->save();

        return $this->successResponse([
            'preferred_locale' => $request->user()->preferred_locale,
            'available_locales' => config('mobile.available_locales', []),
        ], 'Language updated successfully.');
    }
}
