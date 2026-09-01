<?php

$_['heading_title'] = 'UniCredit';

$_['text_extension'] = 'Extensions';
$_['text_success'] = 'Success: You have modified UniCredit module settings!';
$_['text_edit'] = 'Edit UniCredit Module';
$_['text_enabled'] = 'Enabled';
$_['text_disabled'] = 'Disabled';
$_['text_yes'] = 'Yes';
$_['text_no'] = 'No';
$_['text_module_identity'] = 'Module identity';
$_['text_health'] = 'Health and readiness';
$_['text_health_ready'] = 'Ready';
$_['text_health_warning'] = 'Warning';
$_['text_health_not_configured'] = 'Not configured';
$_['text_health_unavailable'] = 'Unavailable';
$_['text_health_future_phase'] = 'Future phase';
$_['text_environment_test'] = 'Test (SmartUCF / CP test)';
$_['text_environment_production'] = 'Production';
$_['text_secret_keep_current'] = 'Leave blank to keep the current stored secret.';
$_['text_secret_phase2'] = 'Secure secret storage is not available in Phase 1. CP secret persistence requires Phase 2 encryption.';
$_['text_deployment_paths'] = 'Expected protected deployment paths (relative to resolved root)';

$_['entry_status'] = 'Module status';
$_['entry_environment'] = 'Environment';
$_['entry_debug'] = 'Debug logging';
$_['entry_unicid'] = 'UNICID';
$_['entry_secret'] = 'CP secret';

$_['help_status'] = 'Master module enable switch. Storefront financing remains disabled until later phases.';
$_['help_environment'] = 'Placeholder for approved CP/SmartUCF environment selection. Outbound CP calls are not implemented in Phase 1.';
$_['help_debug'] = 'Reserved for redacted diagnostics in later phases. No sensitive logging in Phase 1.';
$_['help_unicid'] = 'Control Panel shop identifier. Required before CP integration (Phase 4).';
$_['help_secret'] = 'Never displayed after save. Phase 1 does not persist plaintext secrets.';

$_['column_check'] = 'Check';
$_['column_status'] = 'Status';
$_['column_detail'] = 'Detail';

$_['button_save'] = 'Save';
$_['button_cancel'] = 'Cancel';

$_['error_permission'] = 'Warning: You do not have permission to modify UniCredit module settings!';
$_['error_invalid_environment'] = 'Environment must be test or production.';
$_['error_secret_phase2_required'] = 'CP secret cannot be saved in Phase 1. Secure storage arrives in Phase 2.';
