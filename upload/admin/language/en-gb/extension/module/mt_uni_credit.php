<?php

$_['heading_title'] = 'UniCredit Purchases on Credit';

$_['text_extension'] = 'Extensions';
$_['text_home'] = 'Home';
$_['text_success'] = 'Settings saved successfully.';
$_['text_edit'] = 'Module settings';
$_['text_enabled'] = 'Enabled';
$_['text_disabled'] = 'Disabled';
$_['text_health'] = 'Health and readiness';
$_['text_health_ready'] = 'Ready';
$_['text_health_warning'] = 'Warning';
$_['text_health_not_configured'] = 'Not configured';
$_['text_health_unavailable'] = 'Unavailable';
$_['text_health_future_phase'] = 'Future phase';
$_['text_environment_test'] = 'Test (SmartUCF / CP test)';
$_['text_environment_production'] = 'Production';
$_['text_secret_keep_current'] = 'Leave blank to keep the current secret.';
$_['text_secret_phase2'] = 'Secure secret storage is not available in Phase 1. Secret persistence requires Phase 2 encryption.';
$_['text_deployment_paths'] = 'Expected protected deployment paths (relative to resolved root)';

$_['entry_status'] = 'Status';
$_['entry_environment'] = 'Environment';
$_['entry_debug_enabled'] = 'Debug mode';
$_['entry_unicid'] = 'Shop unique identification code';
$_['entry_secret'] = 'Shop secret code';

$_['help_status'] = 'Master module enable switch. Storefront financing remains disabled until later phases.';
$_['help_environment'] = 'Placeholder for approved CP/SmartUCF environment selection. Outbound CP calls are not implemented in Phase 1.';
$_['help_debug_enabled'] = 'When enabled, server-side SmartUCF diagnostic entries (request/response) are stored for support. Data is redacted and never shown to customers.';
$_['help_unicid'] = 'Your unique shop identification code in the UniCredit system.';
$_['help_secret'] = 'Your shop secret code in the UniCredit system.';

$_['column_check'] = 'Check';
$_['column_status'] = 'Status';
$_['column_detail'] = 'Detail';

$_['button_save'] = 'Save';
$_['button_cancel'] = 'Cancel';

$_['error_permission'] = 'Warning: You do not have permission to modify UniCredit module settings!';
$_['error_invalid_environment'] = 'Environment must be test or production.';
$_['error_unicid_required'] = 'UNICID is required.';
$_['error_unicid_max_length'] = 'UNICID must not exceed 36 characters.';
$_['error_secret_required'] = 'Shop secret code is required.';
$_['error_secret_max_length'] = 'Shop secret code must not exceed 64 characters.';
$_['error_secret_phase2_required'] = 'Secret cannot be saved in Phase 1. Secure storage arrives in Phase 2.';
