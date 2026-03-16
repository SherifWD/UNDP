<?php

namespace App\Http\Controllers\Mobile;

use App\Enums\UserRole;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ReportingController extends MobileController
{
    public function options(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
        ]);

        if ($validator->fails()) {
            return $this->returnValidationError($validator);
        }

        $validated = $validator->validated();
        $projectPayload = null;
        $optionsVersion = (string) config('mobile.reporting.options_version', '2026.03.mobile-reporting.v2');

        if (! empty($validated['project_id'])) {
            $project = Project::query()->with('municipality')->findOrFail($validated['project_id']);
            $user = $request->user();

            if ($user->municipality_id && $user->hasRole([
                UserRole::REPORTER->value,
                UserRole::MUNICIPAL_FOCAL_POINT->value,
            ]) && (int) $project->municipality_id !== (int) $user->municipality_id) {
                return $this->errorResponse('Access denied.', 403);
            }

            $projectPayload = $this->serializeProject($project, $user);
        }

        $availableOptions = $this->availableOptions();
        $mediaDisk = (string) config('media.direct_upload_disk', config('media.disk', 'public'));
        $mediaDiskConfig = config("filesystems.disks.{$mediaDisk}");
        $presignedUploadsEnabled = ($mediaDiskConfig['driver'] ?? null) === 's3';

        return $this->successResponse([
            'version' => $optionsVersion,
            'report_type' => config('mobile.reporting.report_type'),
            'available_options' => $availableOptions,
            'defaults' => [
                'reporting_period_label' => sprintf('Week %s - %s', now()->format('W'), now()->format('F Y')),
                'project_status' => 'in_progress',
                'confirm_accuracy' => false,
                'location_source' => 'manual',
            ],
            'flow' => [
                'version' => $optionsVersion,
                'steps' => $this->statusDrivenFlow(),
            ],
            'submission_contract' => [
                'create' => [
                    'method' => 'POST',
                    'path' => '/api/mobile/submissions',
                    'accepted_modes' => ['draft', 'submit'],
                    'allows_media_references' => false,
                    'allows_direct_file_uploads' => true,
                    'notes' => [
                        'Direct file uploads are supported on create via multipart form-data using repeated assets[] file fields.',
                        'Existing uploaded asset ids still require an existing submission_id and must be attached on update.',
                    ],
                ],
                'update' => [
                    'method' => 'PUT',
                    'path' => '/api/mobile/submissions/{submission_id}',
                    'multipart_transport' => [
                        'http_method' => 'POST',
                        'form_field' => '_method=PUT',
                    ],
                    'accepted_media_reference_keys' => ['assets', 'media'],
                    'accepted_direct_file_keys' => ['assets[]'],
                    'media_item_schema' => [
                        'id' => 'integer|required',
                        'type' => 'image|video|optional',
                        'label' => 'string|optional',
                    ],
                ],
                'field_aliases' => [
                    'activities_started' => [
                        'activity_started',
                        'activities_workshops_or_training_started',
                    ],
                ],
                'media_storage' => [
                    'disk' => $mediaDisk,
                    'driver' => $mediaDiskConfig['driver'] ?? null,
                    'base_url' => url('/storage/mobile/assets'),
                    'public_path_pattern' => 'storage/app/public/mobile/assets/{submission_id}/{filename}',
                    'presigned_uploads_enabled' => $presignedUploadsEnabled,
                ],
                'media_upload_flow' => $presignedUploadsEnabled
                    ? [
                        [
                            'step' => 1,
                            'action' => 'Optional direct-upload path: send multipart form-data with repeated assets[] file fields on create or update.',
                            'endpoint' => 'POST /api/mobile/submissions or POST /api/mobile/submissions/{submission_id} with _method=PUT',
                        ],
                        [
                            'step' => 2,
                            'action' => 'Alternative presigned path: create the draft submission first to receive submission_id.',
                            'endpoint' => 'POST /api/mobile/submissions',
                        ],
                        [
                            'step' => 3,
                            'action' => 'Request an upload URL and media asset id for each file.',
                            'endpoint' => 'POST /api/mobile/media/presign-upload',
                        ],
                        [
                            'step' => 4,
                            'action' => 'Upload the binary file to the returned upload.url using the returned method and headers.',
                            'endpoint' => 'upload.url',
                        ],
                        [
                            'step' => 5,
                            'action' => 'Mark the upload as completed after the binary upload succeeds.',
                            'endpoint' => 'POST /api/mobile/media/{media_asset_id}/complete',
                        ],
                        [
                            'step' => 6,
                            'action' => 'Attach uploaded media to the submission using assets or media.',
                            'endpoint' => 'PUT /api/mobile/submissions/{submission_id}',
                        ],
                    ]
                    : [
                        [
                            'step' => 1,
                            'action' => 'Create a draft with attachments or upload attachments while submitting using repeated assets[] multipart file fields.',
                            'endpoint' => 'POST /api/mobile/submissions',
                        ],
                        [
                            'step' => 2,
                            'action' => 'For submission updates, keep the HTTP request as POST and send _method=PUT together with repeated assets[] file fields.',
                            'endpoint' => 'POST /api/mobile/submissions/{submission_id} with _method=PUT',
                        ],
                        [
                            'step' => 3,
                            'action' => 'Read uploaded files directly from the public storage URL returned in each asset payload or from the download-url endpoint.',
                            'endpoint' => 'GET /api/mobile/media/{media_asset_id}/download-url',
                        ],
                    ],
            ],
            'media_limits' => [
                'images' => [
                    'max_count' => (int) config('media.images.max_count', 10),
                    'max_upload_mb' => (int) config('media.images.max_upload_mb', 15),
                ],
                'videos' => [
                    'max_count' => (int) config('media.videos.max_count', 1),
                    'max_upload_mb' => (int) config('media.videos.max_upload_mb', 300),
                ],
            ],
            'project' => $projectPayload,
        ]);
    }

    private function availableOptions(): array
    {
        return [
            'component_categories' => $this->toOptionList('component_categories'),
            'project_statuses' => $this->toOptionList('project_statuses'),
            'delay_reasons' => $this->toOptionList('delay_reasons'),
            'progress_impressions' => $this->toOptionList('progress_impressions'),
            'functional_statuses' => $this->toOptionList('functional_statuses'),
            'user_categories' => $this->toOptionList('user_categories'),
            'constraint_types' => $this->toOptionList('constraint_types'),
            'yes_no' => $this->yesNoOptions(),
        ];
    }

    private function statusDrivenFlow(): array
    {
        return [
            [
                'key' => 'project_selection',
                'label' => 'Project Selection',
                'fields' => [
                    [
                        'key' => 'project_id',
                        'label' => 'Project Name',
                        'type' => 'project_select',
                        'required' => true,
                    ],
                ],
            ],
            [
                'key' => 'project_status',
                'label' => 'Project Status',
                'base_fields' => [
                    [
                        'key' => 'component_category',
                        'label' => 'Component Category',
                        'type' => 'readonly_text',
                        'required' => true,
                    ],
                    [
                        'key' => 'project_status',
                        'label' => 'Current Project Status',
                        'type' => 'single_choice',
                        'required' => true,
                        'options_key' => 'project_statuses',
                    ],
                ],
                'status_sections' => [
                    [
                        'status' => 'planned',
                        'status_label' => 'Planned / Not Started Yet',
                        'fields' => [
                            [
                                'key' => 'delay_reason',
                                'label' => 'Reason for Delay',
                                'type' => 'single_choice',
                                'required' => true,
                                'options_key' => 'delay_reasons',
                            ],
                        ],
                    ],
                    [
                        'status' => 'in_progress',
                        'status_label' => 'In Progress',
                        'fields' => [
                            [
                                'key' => 'progress_impression',
                                'label' => 'Impression of Work Progress',
                                'type' => 'single_choice',
                                'required' => true,
                                'options_key' => 'progress_impressions',
                            ],
                            [
                                'key' => 'physical_progress',
                                'label' => 'Do you see physical progress in construction or installation?',
                                'type' => 'single_choice',
                                'required' => true,
                                'options_key' => 'yes_no',
                            ],
                            [
                                'key' => 'approximate_completion_percentage',
                                'label' => 'Approximate Completion Percentage',
                                'type' => 'slider',
                                'required' => true,
                                'min' => 0,
                                'max' => 100,
                            ],
                            [
                                'key' => 'additional_observations',
                                'label' => 'Additional Observations',
                                'type' => 'multiline_text',
                                'required' => true,
                            ],
                        ],
                    ],
                    [
                        'status' => 'completed',
                        'status_label' => 'Completed',
                        'fields' => [
                            [
                                'key' => 'is_project_being_used',
                                'label' => 'Is the Project Being Used?',
                                'type' => 'single_choice',
                                'required' => true,
                                'options_key' => 'yes_no',
                            ],
                            [
                                'key' => 'activities_started',
                                'label' => 'Have the activities, workshops, or training sessions actually started?',
                                'type' => 'single_choice',
                                'required' => false,
                                'options_key' => 'yes_no',
                                'aliases' => [
                                    'activity_started',
                                    'activities_workshops_or_training_started',
                                ],
                            ],
                            [
                                'key' => 'user_categories',
                                'label' => 'User Categories',
                                'type' => 'multi_choice',
                                'required_when' => [
                                    'field' => 'is_project_being_used',
                                    'equals' => true,
                                ],
                                'options_key' => 'user_categories',
                            ],
                            [
                                'key' => 'is_used_as_intended',
                                'label' => 'Is the project being used as intended?',
                                'type' => 'single_choice',
                                'required' => true,
                                'options_key' => 'yes_no',
                            ],
                            [
                                'key' => 'functional_status',
                                'label' => 'Functional Status',
                                'type' => 'single_choice',
                                'required' => true,
                                'options_key' => 'functional_statuses',
                            ],
                            [
                                'key' => 'negative_environmental_impact',
                                'label' => 'Do you notice any negative environmental impact?',
                                'type' => 'single_choice',
                                'required' => true,
                                'options_key' => 'yes_no',
                            ],
                            [
                                'key' => 'negative_impact_details',
                                'label' => 'Environmental Impact Details',
                                'type' => 'multiline_text',
                                'required_when' => [
                                    'field' => 'negative_environmental_impact',
                                    'equals' => true,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'key' => 'impact_evidence',
                'label' => 'Impact & Evidence',
                'fields' => [
                    [
                        'key' => 'actual_beneficiaries',
                        'label' => 'Actual Beneficiaries',
                        'type' => 'number',
                        'required' => true,
                        'min' => 0,
                    ],
                    [
                        'key' => 'location_label',
                        'label' => 'Location of Observation',
                        'type' => 'text',
                        'required' => true,
                    ],
                    [
                        'key' => 'location_source',
                        'label' => 'Location Source',
                        'type' => 'single_choice',
                        'required' => false,
                        'options' => [
                            ['value' => 'manual', 'label' => 'Manual', 'label_ar' => 'يدوي'],
                            ['value' => 'gps', 'label' => 'Auto GPS', 'label_ar' => 'تحديد تلقائي عبر GPS'],
                        ],
                    ],
                    [
                        'key' => 'media',
                        'label' => 'Photos / Videos',
                        'type' => 'media_picker',
                        'required' => false,
                    ],
                    [
                        'key' => 'notes',
                        'label' => 'Additional Notes',
                        'type' => 'multiline_text',
                        'required' => false,
                    ],
                    [
                        'key' => 'confirm_accuracy',
                        'label' => 'I confirm this report is accurate and complete',
                        'type' => 'checkbox',
                        'required' => true,
                    ],
                ],
            ],
        ];
    }

    private function yesNoOptions(): array
    {
        return [
            ['value' => true, 'label' => 'Yes', 'label_en' => 'Yes', 'label_ar' => 'نعم'],
            ['value' => false, 'label' => 'No', 'label_en' => 'No', 'label_ar' => 'لا'],
        ];
    }

    private function toOptionList(string $group): array
    {
        $items = config("mobile.reporting.{$group}", []);
        $arabicLabels = config("mobile.reporting.{$group}_ar", []);

        return collect($items)
            ->map(function (string $label, string $value) use ($arabicLabels): array {
                $labelAr = data_get($arabicLabels, $value);

                return [
                    'value' => $value,
                    'label' => $label,
                    'label_en' => $label,
                    'label_ar' => is_string($labelAr) && trim($labelAr) !== '' ? $labelAr : $label,
                ];
            })
            ->values()
            ->all();
    }
}
