<?php

return [
    'available_locales' => [
        [
            'code' => 'en',
            'label' => 'English',
        ],
        [
            'code' => 'ar',
            'label' => 'Arabic',
        ],
    ],
    'auth' => [
        'access_token_ttl_minutes' => (int) env('MOBILE_ACCESS_TOKEN_TTL_MINUTES', 720),
        'refresh_token_ttl_days' => (int) env('MOBILE_REFRESH_TOKEN_TTL_DAYS', 30),
    ],
    'permissions' => [
        [
            'key' => 'camera',
            'label' => 'Camera',
            'client_managed' => true,
            'description' => 'Required for capturing field evidence from the device camera.',
        ],
        [
            'key' => 'location',
            'label' => 'Location',
            'client_managed' => true,
            'description' => 'Required for auto-filling the observation location during field reporting.',
        ],
    ],
    'reporting' => [
        'report_type' => 'Site Progress Update',
        'component_categories' => [
            'hard_component_infrastructure' => 'Hard Component - Infrastructure',
            'soft_component_capacity_building' => 'Soft Component - Capacity Building',
            'service_delivery' => 'Service Delivery',
            'mixed_component' => 'Mixed Component',
        ],
        'project_statuses' => [
            'planned' => 'Planned',
            'in_progress' => 'In Progress',
            'completed' => 'Completed',
        ],
        'delay_reasons' => [
            'site_empty' => 'Site is empty (No equipment or mobilization)',
            'land_ownership_dispute' => 'Land ownership dispute',
            'funding_gap' => 'Funding gap',
            'security_related' => 'Security-related',
            'weather_conditions' => 'Weather conditions',
            'procurement_delay' => 'Procurement delay',
            'permit_delay' => 'Permit delay',
            'no_information' => 'No information (Unknown)',
        ],
        'progress_impressions' => [
            'good' => 'Good',
            'average' => 'Average',
            'bad' => 'Bad',
        ],
        'functional_statuses' => [
            'fully_functional' => 'Fully Functional / Good condition',
            'operational_needs_maintenance' => 'Operational but needs maintenance',
            'broken_bad_condition' => 'Broken / Bad condition',
        ],
        'user_categories' => [
            'women' => 'Women',
            'youth' => 'Youth',
            'pwd' => 'People with Disabilities (PWD)',
            'idps' => 'IDPs',
            'elderly' => 'Elderly',
            'all_of_the_above' => 'All of the above',
        ],
        'constraint_types' => [
            'security_related' => 'Security-related',
            'logistics' => 'Logistics',
            'weather' => 'Weather',
            'access' => 'Access',
            'procurement' => 'Procurement',
            'community_alignment' => 'Community alignment',
            'none' => 'None',
        ],
    ],
];
