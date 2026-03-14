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
        'options_version' => env('MOBILE_REPORTING_OPTIONS_VERSION', '2026.03.mobile-reporting.v2'),
        'report_type' => 'Site Progress Update',
        'component_categories' => [
            'hard_component_infrastructure' => 'Hard Component - Infrastructure',
            'soft_component_capacity_building' => 'Soft Component - Capacity Building',
            'service_delivery' => 'Service Delivery',
            'mixed_component' => 'Mixed Component',
        ],
        'component_categories_ar' => [
            'hard_component_infrastructure' => 'مكون صلب - بنية تحتية',
            'soft_component_capacity_building' => 'مكون ناعم - بناء قدرات',
            'service_delivery' => 'تقديم الخدمات',
            'mixed_component' => 'مكون مختلط',
        ],
        'project_statuses' => [
            'planned' => 'Planned',
            'in_progress' => 'In Progress',
            'completed' => 'Completed',
        ],
        'project_statuses_ar' => [
            'planned' => 'مخطط',
            'in_progress' => 'قيد التنفيذ',
            'completed' => 'مكتمل',
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
        'delay_reasons_ar' => [
            'site_empty' => 'الموقع فارغ (لا توجد معدات أو تعبئة)',
            'land_ownership_dispute' => 'نزاع ملكية الأرض',
            'funding_gap' => 'فجوة تمويلية',
            'security_related' => 'أسباب أمنية',
            'weather_conditions' => 'الظروف الجوية',
            'procurement_delay' => 'تأخر في المشتريات',
            'permit_delay' => 'تأخر في التصاريح',
            'no_information' => 'لا توجد معلومات (غير معروف)',
        ],
        'progress_impressions' => [
            'good' => 'Good',
            'average' => 'Average',
            'bad' => 'Bad',
        ],
        'progress_impressions_ar' => [
            'good' => 'جيد',
            'average' => 'متوسط',
            'bad' => 'ضعيف',
        ],
        'functional_statuses' => [
            'fully_functional' => 'Fully Functional / Good condition',
            'operational_needs_maintenance' => 'Operational but needs maintenance',
            'broken_bad_condition' => 'Broken / Bad condition',
        ],
        'functional_statuses_ar' => [
            'fully_functional' => 'يعمل بالكامل / حالة جيدة',
            'operational_needs_maintenance' => 'تشغيلي لكنه يحتاج صيانة',
            'broken_bad_condition' => 'معطل / حالة سيئة',
        ],
        'user_categories' => [
            'women' => 'Women',
            'youth' => 'Youth',
            'pwd' => 'People with Disabilities (PWD)',
            'idps' => 'IDPs',
            'elderly' => 'Elderly',
            'all_of_the_above' => 'All of the above',
        ],
        'user_categories_ar' => [
            'women' => 'النساء',
            'youth' => 'الشباب',
            'pwd' => 'الأشخاص ذوو الإعاقة',
            'idps' => 'النازحون داخليا',
            'elderly' => 'كبار السن',
            'all_of_the_above' => 'كل ما سبق',
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
        'constraint_types_ar' => [
            'security_related' => 'أسباب أمنية',
            'logistics' => 'لوجستيات',
            'weather' => 'الطقس',
            'access' => 'إمكانية الوصول',
            'procurement' => 'المشتريات',
            'community_alignment' => 'مواءمة المجتمع',
            'none' => 'لا يوجد',
        ],
    ],
];
