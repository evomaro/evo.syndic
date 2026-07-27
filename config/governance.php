<?php

return [
    'legal_source' => [
        'identifier' => 'MA-18-00-AS-AMENDED-106-12',
        'version' => 1,
        'effective_from' => '2016-11-03',
        'official_source' => 'Loi n° 18-00 relative au statut de la copropriété des immeubles bâtis, modifiée par la loi n° 106-12',
        'source_url' => 'https://adala.justice.gov.ma/api/uploads/2024/03/05/Statut%20de%20la%20copropri%C3%A9t%C3%A9%20des%20immeubles%20b%C3%A2tis-1709644798716.pdf',
        'review_status' => 'pending_counsel_review',
        'review_note' => 'Official consolidated Ministry of Justice text verified 2026-07-22. Operational interpretation, abstention treatment, eligibility restrictions and delivery practice require Moroccan counsel approval before legal reliance.',
    ],
    'notice_days' => 15,
    'agenda_question_hours' => 24,
    'decision_notification_days' => 8,
    'document_access_days' => 15,
    'second_convocation_max_days' => 30,
    'rules' => [
        'article_20_relative_majority' => [
            'numerator_definition' => 'for_weight', 'denominator_definition' => 'present_represented_weight',
            'threshold_numerator' => 1, 'threshold_denominator' => 2, 'comparison' => 'gt',
            'abstention_behavior' => 'included_in_denominator', 'review_status' => 'pending_counsel_review',
            'articles' => ['18', '20'],
        ],
        'article_21_three_quarters' => [
            'numerator_definition' => 'for_weight', 'denominator_definition' => 'all_eligible_weight',
            'threshold_numerator' => 3, 'threshold_denominator' => 4, 'comparison' => 'gte',
            'abstention_behavior' => 'included_in_denominator', 'review_status' => 'pending_counsel_review',
            'articles' => ['18', '19', '21', '24'],
        ],
        'article_22_unanimity' => [
            'numerator_definition' => 'for_weight', 'denominator_definition' => 'all_eligible_weight',
            'threshold_numerator' => 1, 'threshold_denominator' => 1, 'comparison' => 'gte',
            'abstention_behavior' => 'included_in_denominator', 'review_status' => 'verified_statutory_text_pending_application_review',
            'articles' => ['18', '22'],
        ],
    ],
    'proxy' => ['written_required' => true, 'max_principals' => 3, 'max_total_weight_numerator' => 10, 'max_total_weight_denominator' => 100],
    'remote_voting' => ['enabled' => false, 'review_status' => 'not_legally_validated'],
    'uploads' => ['max_kilobytes' => 10240, 'mimes' => ['pdf', 'jpg', 'jpeg', 'png'], 'mime_types' => ['application/pdf', 'image/jpeg', 'image/png']],
];
