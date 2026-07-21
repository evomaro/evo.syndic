<?php

return [
    'permissions' => [
        'manage_organization', 'manage_team', 'view_residences', 'create_residences', 'edit_residences',
        'manage_property_structure', 'manage_contacts', 'manage_ownerships', 'manage_occupancies',
        'manage_allocation_keys', 'import_data', 'view_activity_logs',
        'view_finance', 'manage_financial_exercises', 'manage_financial_accounts', 'manage_charge_categories',
        'create_fund_calls', 'validate_fund_calls', 'cancel_fund_calls', 'create_payments', 'validate_payments',
        'reverse_payments', 'allocate_credit', 'view_outstanding', 'view_statements', 'export_finance',
        'view_financial_activity',
    ],
    'roles' => [
        'owner' => ['*'],
        'administrator' => ['manage_organization', 'manage_team', 'view_residences', 'create_residences', 'edit_residences', 'manage_property_structure', 'manage_contacts', 'manage_ownerships', 'manage_occupancies', 'manage_allocation_keys', 'import_data', 'view_activity_logs', 'view_finance', 'manage_financial_exercises', 'manage_financial_accounts', 'manage_charge_categories', 'create_fund_calls', 'validate_fund_calls', 'cancel_fund_calls', 'create_payments', 'validate_payments', 'reverse_payments', 'allocate_credit', 'view_outstanding', 'view_statements', 'export_finance', 'view_financial_activity'],
        'manager' => ['view_residences', 'create_residences', 'edit_residences', 'manage_property_structure', 'manage_contacts', 'manage_ownerships', 'manage_occupancies', 'manage_allocation_keys', 'import_data', 'view_activity_logs', 'view_finance', 'create_fund_calls', 'create_payments', 'view_outstanding', 'view_statements', 'view_financial_activity'],
        'accountant' => ['view_residences', 'manage_contacts', 'manage_allocation_keys', 'import_data', 'view_activity_logs', 'view_finance', 'manage_financial_exercises', 'manage_financial_accounts', 'manage_charge_categories', 'create_fund_calls', 'validate_fund_calls', 'cancel_fund_calls', 'create_payments', 'validate_payments', 'reverse_payments', 'allocate_credit', 'view_outstanding', 'view_statements', 'export_finance', 'view_financial_activity'],
        'maintenance_agent' => ['view_residences', 'manage_contacts'],
        'auditor' => ['view_residences', 'view_activity_logs', 'view_finance', 'view_outstanding', 'view_statements', 'export_finance', 'view_financial_activity'],
    ],
];
