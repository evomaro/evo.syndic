<?php

$governancePermissions = [
    'view_governance_dashboard', 'view_assemblies', 'create_assemblies', 'manage_assemblies',
    'transition_assemblies', 'issue_convocations', 'override_late_convocation', 'manage_agenda',
    'manage_electorate_snapshots', 'manage_governance_document_room', 'record_attendance',
    'verify_proxies', 'record_ballots', 'correct_ballots', 'finalize_results', 'reopen_results',
    'prepare_minutes', 'sign_minutes', 'manage_decision_notifications', 'manage_execution_actions',
    'manage_governance_mandates', 'view_governance_reports', 'download_internal_governance_documents',
];

return [
    'permissions' => [
        'manage_organization', 'manage_team', 'view_residences', 'create_residences', 'edit_residences',
        'manage_property_structure', 'manage_contacts', 'manage_ownerships', 'manage_occupancies',
        'manage_allocation_keys', 'import_data', 'view_activity_logs',
        'view_finance', 'manage_financial_exercises', 'manage_financial_accounts', 'manage_charge_categories',
        'create_fund_calls', 'validate_fund_calls', 'cancel_fund_calls', 'create_payments', 'validate_payments',
        'reverse_payments', 'allocate_credit', 'view_outstanding', 'view_statements', 'export_finance',
        'view_financial_activity',
        'view_suppliers', 'manage_suppliers', 'view_supplier_private_data', 'manage_supplier_contracts',
        'view_expenses', 'create_expenses', 'validate_expenses', 'cancel_expenses', 'approve_commitments',
        'create_settlements', 'validate_settlements', 'reverse_settlements', 'manage_credit_notes',
        'view_supplier_payables', 'export_expenses', 'manage_budgets', 'approve_budgets',
        'view_documents', 'manage_documents', 'publish_documents', 'view_announcements',
        'manage_announcements', 'publish_announcements', 'view_expense_transparency',
        'manage_notification_preferences', 'create_cross_residence_expenses',
        'view_maintenance_requests', 'create_maintenance_requests', 'manage_maintenance_requests',
        'transition_maintenance_requests', 'assign_maintenance_requests', 'manage_maintenance_categories',
        'view_maintenance_quotations', 'manage_maintenance_quotations', 'accept_maintenance_quotations',
        'view_work_orders', 'manage_work_orders', 'complete_work_orders', 'validate_work_orders',
        'manage_maintenance_equipment', 'manage_preventive_plans', 'view_maintenance_reports',
        'create_work_order_invoices', 'download_internal_maintenance_attachments',
        ...$governancePermissions,
    ],
    'roles' => [
        'owner' => ['*'],
        'administrator' => ['manage_organization', 'manage_team', 'view_residences', 'create_residences', 'edit_residences', 'manage_property_structure', 'manage_contacts', 'manage_ownerships', 'manage_occupancies', 'manage_allocation_keys', 'import_data', 'view_activity_logs', 'view_finance', 'manage_financial_exercises', 'manage_financial_accounts', 'manage_charge_categories', 'create_fund_calls', 'validate_fund_calls', 'cancel_fund_calls', 'create_payments', 'validate_payments', 'reverse_payments', 'allocate_credit', 'view_outstanding', 'view_statements', 'export_finance', 'view_financial_activity', 'view_suppliers', 'manage_suppliers', 'view_supplier_private_data', 'manage_supplier_contracts', 'view_expenses', 'create_expenses', 'validate_expenses', 'cancel_expenses', 'approve_commitments', 'create_settlements', 'validate_settlements', 'reverse_settlements', 'manage_credit_notes', 'view_supplier_payables', 'export_expenses', 'manage_budgets', 'approve_budgets', 'view_documents', 'manage_documents', 'publish_documents', 'view_announcements', 'manage_announcements', 'publish_announcements', 'view_expense_transparency', 'manage_notification_preferences', 'create_cross_residence_expenses', 'view_maintenance_requests', 'create_maintenance_requests', 'manage_maintenance_requests', 'transition_maintenance_requests', 'assign_maintenance_requests', 'manage_maintenance_categories', 'view_maintenance_quotations', 'manage_maintenance_quotations', 'accept_maintenance_quotations', 'view_work_orders', 'manage_work_orders', 'complete_work_orders', 'validate_work_orders', 'manage_maintenance_equipment', 'manage_preventive_plans', 'view_maintenance_reports', 'create_work_order_invoices', 'download_internal_maintenance_attachments', ...$governancePermissions],
        'manager' => ['view_residences', 'create_residences', 'edit_residences', 'manage_property_structure', 'manage_contacts', 'manage_ownerships', 'manage_occupancies', 'manage_allocation_keys', 'import_data', 'view_activity_logs', 'view_finance', 'create_fund_calls', 'create_payments', 'view_outstanding', 'view_statements', 'view_financial_activity', 'view_suppliers', 'manage_suppliers', 'manage_supplier_contracts', 'view_expenses', 'create_expenses', 'approve_commitments', 'view_supplier_payables', 'manage_budgets', 'view_documents', 'manage_documents', 'publish_documents', 'view_announcements', 'manage_announcements', 'publish_announcements', 'view_expense_transparency', 'manage_notification_preferences', 'view_maintenance_requests', 'create_maintenance_requests', 'manage_maintenance_requests', 'transition_maintenance_requests', 'assign_maintenance_requests', 'manage_maintenance_categories', 'view_maintenance_quotations', 'manage_maintenance_quotations', 'accept_maintenance_quotations', 'view_work_orders', 'manage_work_orders', 'complete_work_orders', 'validate_work_orders', 'manage_maintenance_equipment', 'manage_preventive_plans', 'view_maintenance_reports', 'create_work_order_invoices', 'download_internal_maintenance_attachments', ...$governancePermissions],
        'accountant' => ['view_residences', 'manage_contacts', 'manage_allocation_keys', 'import_data', 'view_activity_logs', 'view_finance', 'manage_financial_exercises', 'manage_financial_accounts', 'manage_charge_categories', 'create_fund_calls', 'validate_fund_calls', 'cancel_fund_calls', 'create_payments', 'validate_payments', 'reverse_payments', 'allocate_credit', 'view_outstanding', 'view_statements', 'export_finance', 'view_financial_activity', 'view_suppliers', 'manage_suppliers', 'view_supplier_private_data', 'manage_supplier_contracts', 'view_expenses', 'create_expenses', 'validate_expenses', 'cancel_expenses', 'approve_commitments', 'create_settlements', 'validate_settlements', 'reverse_settlements', 'manage_credit_notes', 'view_supplier_payables', 'export_expenses', 'manage_budgets', 'approve_budgets', 'view_documents', 'view_announcements', 'view_expense_transparency', 'manage_notification_preferences'],
        'maintenance_agent' => ['view_residences', 'manage_contacts', 'view_suppliers', 'manage_supplier_contracts', 'view_maintenance_requests', 'create_maintenance_requests', 'manage_maintenance_requests', 'transition_maintenance_requests', 'assign_maintenance_requests', 'view_maintenance_quotations', 'manage_maintenance_quotations', 'view_work_orders', 'manage_work_orders', 'complete_work_orders', 'manage_maintenance_equipment', 'manage_preventive_plans', 'view_maintenance_reports', 'download_internal_maintenance_attachments'],
        'auditor' => ['view_residences', 'view_activity_logs', 'view_finance', 'view_outstanding', 'view_statements', 'export_finance', 'view_financial_activity', 'view_suppliers', 'view_expenses', 'view_supplier_payables', 'export_expenses', 'view_documents', 'view_announcements', 'view_expense_transparency', 'view_maintenance_requests', 'view_maintenance_quotations', 'view_work_orders', 'view_maintenance_reports'],
    ],
    'maintenance' => ['reopen_days' => 7],
];
