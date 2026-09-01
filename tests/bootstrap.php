<?php

/**
 * Phase 0 test bootstrap. PHP 7.3 compatible. No live network.
 */
if (PHP_VERSION_ID < 70300) {
    fwrite(STDERR, "Phase 0 checks require PHP 7.3 or newer to match the recommended floor.\n");
    exit(2);
}

define('MTUC_PHASE0_ROOT', dirname(__DIR__));
define('MTUC_PHASE0_FIXTURES', MTUC_PHASE0_ROOT . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures');
define('MTUC_PHASE0_DOCS', MTUC_PHASE0_ROOT . DIRECTORY_SEPARATOR . 'docs');

require_once __DIR__ . '/support/fixture_loader.php';
require_once __DIR__ . '/support/no_network.php';

mtuc_phase0_install_network_guard();
