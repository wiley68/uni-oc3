<?php

/**
 * Fail tests if unexpected outbound HTTP wrappers are used.
 */
function mtuc_phase0_install_network_guard()
{
    if (getenv('MTUC_PHASE0_ALLOW_NETWORK') === '1') {
        return;
    }

    $disabled = ini_get('disable_functions');
    $disabledList = $disabled === false || $disabled === '' ? array() : array_map('trim', explode(',', $disabled));
    if (!in_array('curl_exec', $disabledList, true)) {
        // Cannot disable curl at runtime; checks/scan.php forbids live hosts in test PHP instead.
    }
}

/**
 * @param string $contents
 * @return bool
 */
function mtuc_phase0_contains_live_remote_host($contents)
{
    $hosts = array(
        'uni.avalonbg.com',
        'open40.avalonbg.com',
        'online.ucfin.bg',
        'onlinetest.ucfin.bg',
        'presta9.avalonbg.com',
    );
    foreach ($hosts as $host) {
        if (
            preg_match('/https?:\/\/' . preg_quote($host, '/') . '/i', $contents)
            && preg_match('/\b(curl_exec|curl_init|file_get_contents|fopen|fsockopen)\s*\(/i', $contents)
        ) {
            return true;
        }
    }

    return false;
}
