<?php

$_['heading_title'] = 'UniCredit Purchases on Credit';

$_['text_extension'] = 'Extensions';
$_['text_home'] = 'Home';
$_['text_success'] = 'Settings saved successfully.';
$_['text_edit'] = 'Module settings';
$_['text_event_health'] = 'Presentation event health';
$_['text_event_health_ok'] = 'Presentation events are healthy. All Thank You / native mail presentation events are registered and enabled.';
$_['text_event_health_repair_failed'] = 'Presentation event repair was attempted on this page load, but events are still missing or unhealthy. Check DB write permissions for the event table and OpenCart error log (mt_uni_credit: ensureCatalogEvents).';
$_['column_event_code'] = 'Code';
$_['column_event_trigger'] = 'Expected trigger';
$_['column_event_registered'] = 'Registered';
$_['column_event_enabled'] = 'Enabled';
$_['column_event_duplicates'] = 'Duplicates';
$_['column_event_healthy'] = 'Healthy';
$_['text_product_button_add_to_cart'] = 'Add to cart';
$_['text_product_button_buy'] = 'Buy';
$_['text_secret_keep_current'] = 'Leave blank to keep the current secret.';
$_['text_secret_configured'] = 'A secret is already configured and stored encrypted.';
$_['text_bank_data_refreshed'] = 'Bank data refreshed successfully.';
$_['text_bank_data_refreshed_at'] = 'Updated at: %s.';
$_['text_bank_data_scheme_count'] = 'Schemes in cache: %d.';

$_['entry_status'] = 'Status';
$_['entry_unicid'] = 'Shop unique identification code';
$_['entry_secret'] = 'Shop secret code';
$_['entry_advertising_enabled'] = 'Show advertising';
$_['entry_debug_enabled'] = 'Debug mode';
$_['entry_product_button_action'] = 'Buy button';
$_['entry_button_top_spacing'] = 'Space above the button';

$_['help_unicid'] = 'Your unique shop identification code in the UniCredit system.';
$_['help_secret'] = 'Your shop secret code in the UniCredit system.';
$_['help_advertising_enabled'] = 'Enable or disable advertising on the store home page.';
$_['help_debug_enabled'] = 'When enabled, server-side SmartUCF diagnostic entries (request/response) are stored for support. Data is redacted and never shown to customers.';
$_['help_product_button_action'] = 'Behavior of the secondary button in the product popup.';
$_['help_button_top_spacing'] = 'Space above the button in px (0–200).';
$_['help_download_journal'] = 'Downloads a JSON journal of SmartUCF operations for the current store (empty when no entries exist or debug mode is off).';

$_['button_save'] = 'Save';
$_['button_cancel'] = 'Cancel';
$_['button_refresh_bank_data'] = 'Refresh bank data';
$_['button_download_journal'] = 'Download operations journal';

$_['error_permission'] = 'Warning: You do not have permission to modify UniCredit module settings!';
$_['error_unicid_required'] = 'UNICID is required.';
$_['error_unicid_max_length'] = 'UNICID must not exceed 36 characters.';
$_['error_secret_required'] = 'Shop secret code is required.';
$_['error_secret_max_length'] = 'Shop secret code must not exceed 64 characters.';
$_['error_secret_encrypt_failed'] = 'The secret could not be saved securely. The previous secret was preserved.';
$_['error_invalid_product_button_action'] = 'Invalid buy button action.';
$_['error_invalid_button_top_spacing'] = 'Space above the button must be an integer between 0 and 200.';
$_['error_journal_download_failed'] = 'The operations journal could not be downloaded.';

$_['error_bank_unicid_missing'] = 'UNICID is missing.';
$_['error_bank_secret_missing'] = 'Secret is missing.';
$_['error_bank_secret_unreadable'] = 'The stored Secret cannot be read. Re-enter it and save.';
$_['error_bank_shop_url_missing'] = 'Shop URL is missing for Control Panel connection.';
$_['error_bank_authentication_failed'] = 'Control Panel authentication failed.';
$_['error_bank_shop_snapshot_invalid'] = 'Invalid bank data was received.';
$_['error_bank_transient_failure'] = 'Control Panel is temporarily unavailable.';
$_['error_bank_request_failed'] = 'Bank data could not be refreshed due to a technical error.';
