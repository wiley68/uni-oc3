<?php

/**
 * Offline runner for canonical CP↔OC3 safe tests (no network).
 * Run: php scripts/run_canonical_safe_tests.php
 */
$aggregate = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'phase_canonical_aggregate_check.php';
$php = (defined('PHP_BINARY') && PHP_BINARY !== '') ? PHP_BINARY : 'php';
passthru(escapeshellarg($php) . ' ' . escapeshellarg($aggregate), $code);
exit((int) $code);
