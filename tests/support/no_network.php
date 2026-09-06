<?php

/**
 * Offline test network isolation (AUD-033 / F-033-01 / F-033-04).
 *
 * Enforcement: CLI re-exec with curl_* disabled so production CP/SmartUCF
 * cURL transports cannot send outbound requests. Fake transports and injected
 * SmartUCF executors do not call cURL and remain usable.
 *
 * Activity detection (cross-version):
 * - re-exec / offline guard marker, and/or
 * - effective disable_functions listing required curl symbols
 * - OR curl symbols unavailable (PHP 8+ / extension absent)
 *
 * Do NOT rely solely on function_exists() — on PHP 7.3/7.4 disabled functions
 * may still appear in the function table.
 *
 * Source host scanning remains a diagnostic only.
 */

/**
 * @return array<int, string>
 */
function mtuc_phase0_required_disabled_curl_functions()
{
    return array(
        'curl_init',
        'curl_exec',
        'curl_multi_init',
        'curl_multi_exec',
        'curl_multi_select',
    );
}

/**
 * @param string|null $raw
 * @return array<int, string> lower-case function names
 */
function mtuc_phase0_parse_disable_functions($raw = null)
{
    if ($raw === null) {
        $raw = ini_get('disable_functions');
    }
    $raw = trim((string) $raw);
    if ($raw === '') {
        return array();
    }
    $parts = preg_split('/\s*,\s*/', strtolower($raw));
    if (!is_array($parts)) {
        return array();
    }
    $out = array();
    foreach ($parts as $part) {
        $part = trim((string) $part);
        if ($part !== '') {
            $out[] = $part;
        }
    }

    return array_values(array_unique($out));
}

/**
 * @param string|null $raw disable_functions ini string
 * @return bool
 */
function mtuc_phase0_curl_functions_disabled_in_ini($raw = null)
{
    $disabled = array_fill_keys(mtuc_phase0_parse_disable_functions($raw), true);
    foreach (mtuc_phase0_required_disabled_curl_functions() as $fn) {
        if (!isset($disabled[strtolower($fn)])) {
            return false;
        }
    }

    return true;
}

/**
 * Pure predicate for cross-version regression (no I/O).
 *
 * @param bool $allowNetwork
 * @param bool $guardMarker
 * @param string $disableFunctionsIni
 * @param bool $curlInitExists
 * @param bool $curlExecExists
 * @return bool
 */
function mtuc_phase0_evaluate_network_isolation(
    $allowNetwork,
    $guardMarker,
    $disableFunctionsIni,
    $curlInitExists,
    $curlExecExists
) {
    if ($allowNetwork) {
        // Explicit opt-out: isolation is not active.
        return false;
    }

    $iniBlocks = mtuc_phase0_curl_functions_disabled_in_ini($disableFunctionsIni);
    $symbolsGone = (!$curlInitExists && !$curlExecExists);

    // Guarded child: require effective disable_functions (PHP 7.x may still function_exists).
    if ($guardMarker) {
        return $iniBlocks || $symbolsGone;
    }

    // Unguarded process may already be offline via php.ini or missing curl extension.
    return $iniBlocks || $symbolsGone;
}

/**
 * @return bool
 */
function mtuc_phase0_network_isolation_active()
{
    $allow = getenv('MTUC_PHASE0_ALLOW_NETWORK') === '1';
    $guard = getenv('MTUC_OFFLINE_NETWORK_GUARD') === '1';
    $ini = ini_get('disable_functions');
    $ini = is_string($ini) ? $ini : '';

    return mtuc_phase0_evaluate_network_isolation(
        $allow,
        $guard,
        $ini,
        function_exists('curl_init'),
        function_exists('curl_exec')
    );
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
            "MTUC network isolation: guarded re-exec did not apply required disable_functions"
                . " for curl_init/curl_exec (and curl_multi_*).\n"
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
    $disable = implode(',', mtuc_phase0_required_disabled_curl_functions());
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
