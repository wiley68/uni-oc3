<?php

/**
 * Deployment environment endpoints (ZIP packaging).
 *
 * Edit only this file to switch development / test / production Control Panel hosts
 * before packaging the module ZIP. Do not duplicate the host elsewhere.
 *
 * OC3 install path: system/library/mt_uni_credit/config/environment.php
 * (OpenCart 3 Extension Installer does not allow writing to shop-root config/.)
 */

return array(
    'control_panel_url' => 'https://uni.avalonbg.com',

    /**
     * Temporary remote definitive CP create failure probe (Phase 11.5C.3).
     * When true, POST /orders adds X-UniPayment-Test-Failure: cp-create-422.
     * Requires matching CP UNIPAYMENT_ENABLE_TEST_FAILURES=true.
     * Default false — leave false in production packages after remote verification.
     */
    'force_test_cp_create_422' => false,
);
