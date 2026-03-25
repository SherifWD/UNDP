<?php

namespace Tests\Feature;

use Tests\TestCase;

class MobileApiTranslationCoverageTest extends TestCase
{
    public function test_mobile_api_literal_response_strings_exist_in_arabic_catalog(): void
    {
        $files = [
            app_path('Http/Controllers/Api/AuthController.php'),
            app_path('Http/Controllers/Api/MediaController.php'),
            app_path('Http/Controllers/Mobile/HomeController.php'),
            app_path('Http/Controllers/Mobile/InboxController.php'),
            app_path('Http/Controllers/Mobile/MobileController.php'),
            app_path('Http/Controllers/Mobile/ProfileController.php'),
            app_path('Http/Controllers/Mobile/ProjectController.php'),
            app_path('Http/Controllers/Mobile/ReportingController.php'),
            app_path('Http/Controllers/Mobile/SettingsController.php'),
            app_path('Http/Controllers/Mobile/SubmissionController.php'),
            app_path('Http/Middleware/EnsureActiveUser.php'),
            app_path('Http/Middleware/EnsureAnyPermission.php'),
            app_path('Http/Middleware/EnsurePermission.php'),
        ];

        $catalog = json_decode((string) file_get_contents(lang_path('ar.json')), true, flags: JSON_THROW_ON_ERROR);

        $keys = collect($files)
            ->flatMap(function (string $file): array {
                $contents = (string) file_get_contents($file);

                preg_match_all("/__\\('([^']+)'/", $contents, $matches);

                return $matches[1] ?? [];
            })
            ->merge([
                'Unauthenticated.',
                'Too Many Attempts.',
                'This action is unauthorized.',
                'The given data was invalid.',
                'English',
                'Arabic',
                'Camera',
                'Location',
                'Required for capturing field evidence from the device camera.',
                'Required for auto-filling the observation location during field reporting.',
            ])
            ->filter(fn ($value): bool => is_string($value) && trim($value) !== '')
            ->unique()
            ->values();

        $missing = $keys
            ->reject(fn (string $key): bool => array_key_exists($key, $catalog))
            ->values()
            ->all();

        $this->assertSame([], $missing, 'Missing Arabic translations: '.implode(', ', $missing));
    }

    public function test_mobile_api_validation_rules_and_attributes_exist_in_arabic_validation_file(): void
    {
        $validation = require lang_path('ar/validation.php');
        $attributes = $validation['attributes'] ?? [];

        foreach (['required', 'regex', 'digits_between', 'email', 'exists', 'in', 'integer', 'min', 'max', 'string', 'uuid', 'boolean', 'array', 'numeric', 'date', 'image', 'between'] as $rule) {
            $this->assertArrayHasKey($rule, $validation, "Missing Arabic validation rule [{$rule}].");
        }

        foreach ([
            'country_code',
            'phone',
            'code',
            'preferred_locale',
            'email',
            'municipality_id',
            'refresh_token',
            'fcm_token',
            'submission_id',
            'client_media_id',
            'checksum_sha256',
            'media_type',
            'mime_type',
            'original_filename',
            'size_bytes',
            'per_page',
        ] as $attribute) {
            $this->assertArrayHasKey($attribute, $attributes, "Missing Arabic validation attribute [{$attribute}].");
        }
    }
}
