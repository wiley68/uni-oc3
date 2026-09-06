<?php

/**
 * Offline test network isolation (AUD-033 / F-033-01).
 *
 * Enforcement: CLI re-exec with curl_init/curl_exec disabled so production
 * CP/SmartUCF cURL transports cannot send outbound requests. Fake transports
 * and injected SmartUCF executors do not call cURL and remain usable.
 *
 * Source host scanning remains a diagnostic only.
 */

/**
 * @return bool
 */
function mtuc_phase0_network_isolation_active()
{
    // Disabled functions report as non-existent.
    return !function_exists('curl_init') && !function_exists('curl_exec');
}

/**
 * @return void
 */
function mtuc_phase0_install_network_guard()
{
    if (getenv('MTUC_PHASE0_ALLOW_NETWORK') === '1') {
        return;
    }

    if (mtuc_phase0_network_isolation_active()) {
        if (getenv('MTUC_OFFLINE_NETWORK_GUARD') !== '1') {
            putenv('MTUC_OFFLINE_NETWORK_GUARD=1');
            $_ENV['MTUC_OFFLINE_NETWORK_GUARD'] = '1';
        }

        return;
    }

    if (getenv('MTUC_OFFLINE_NETWORK_GUARD') === '1') {
        fwrite(
            STDERR,
            "MTUC network isolation: curl_init/curl_exec still available after guarded re-exec.\n"
        );
        exit(2);
    }

    $argv = (isset($_SERVER['argv']) && is_array($_SERVER['argv'])) ? $_SERVER['argv'] : array();
    if ($argv === array() || !isset($argv[0]) || !is_string($argv[0]) || $argv[0] === '') {
        fwrite(
            STDERR,
            "MTUC network isolation: cannot enforce curl disable without CLI argv;"
                . " run tests via `php tests/<suite>.php`.\n"
        );
        exit(2);
    }

    $php = (defined('PHP_BINARY') && PHP_BINARY !== '') ? PHP_BINARY : 'php';
    $disable = 'curl_init,curl_exec,curl_multi_init,curl_multi_exec,curl_multi_select';
    $existing = ini_get('disable_functions');
    if (is_string($existing) && trim($existing) !== '') {
        $disable = trim($existing) . ',' . $disable;
    }

    $cmd = escapeshellarg($php)
        . ' -d ' . escapeshellarg('disable_functions=' . $disable)
        . ' ' . escapeshellarg($argv[0]);
    for ($i = 1; $i < count($argv); $i++) {
        $cmd .= ' ' . escapeshellarg((string) $argv[$i]);
    }

    putenv('MTUC_OFFLINE_NETWORK_GUARD=1');
    $_ENV['MTUC_OFFLINE_NETWORK_GUARD'] = '1';

    $exitCode = 0;
    passthru($cmd, $exitCode);
    exit((int) $exitCode);
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

/**
 * Explicit assertion for offline suites / isolation regression tests.
 *
 * @return void
 */
function mtuc_phase0_assert_network_isolation_active()
{
    if (getenv('MTUC_PHASE0_ALLOW_NETWORK') === '1') {
        return;
    }
    if (!mtuc_phase0_network_isolation_active()) {
        throw new RuntimeException(
            'MTUC network isolation inactive: unexpected outbound cURL path is still available.'
        );
    }
}
