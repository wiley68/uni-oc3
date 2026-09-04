<?php

/**
 * Phase 11.5B — Backend manual audit closure: hide presentation-event health UI.
 * Run: php tests/phase11_5b_check.php
 */
require_once __DIR__ . '/bootstrap.php';

$root = MTUC_PHASE0_ROOT;
$failures = array();
$passes = 0;

/**
 * @param bool $condition
 * @param string $message
 * @return void
 */
function mtuc115b_assert($condition, $message)
{
    global $failures, $passes;
    if ($condition) {
        $passes++;
        echo 'PASS  ' . $message . PHP_EOL;

        return;
    }
    $failures[] = $message;
    echo 'FAIL  ' . $message . PHP_EOL;
}

$twig = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR
    . 'view' . DIRECTORY_SEPARATOR . 'template' . DIRECTORY_SEPARATOR . 'extension'
    . DIRECTORY_SEPARATOR . 'module' . DIRECTORY_SEPARATOR . 'mt_uni_credit.twig';
$ctrl = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR
    . 'controller' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'module'
    . DIRECTORY_SEPARATOR . 'mt_uni_credit.php';
$model = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR
    . 'model' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'module'
    . DIRECTORY_SEPARATOR . 'mt_uni_credit.php';
$langBg = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR
    . 'language' . DIRECTORY_SEPARATOR . 'bg-bg' . DIRECTORY_SEPARATOR . 'extension'
    . DIRECTORY_SEPARATOR . 'module' . DIRECTORY_SEPARATOR . 'mt_uni_credit.php';
$langEn = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR
    . 'language' . DIRECTORY_SEPARATOR . 'en-gb' . DIRECTORY_SEPARATOR . 'extension'
    . DIRECTORY_SEPARATOR . 'module' . DIRECTORY_SEPARATOR . 'mt_uni_credit.php';
$installer = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR
    . 'library' . DIRECTORY_SEPARATOR . 'mt_uni_credit' . DIRECTORY_SEPARATOR . 'installer.php';

$twigSrc = (string) file_get_contents($twig);
$ctrlSrc = (string) file_get_contents($ctrl);
$modelSrc = (string) file_get_contents($model);
$bgSrc = (string) file_get_contents($langBg);
$enSrc = (string) file_get_contents($langEn);
$installerSrc = (string) file_get_contents($installer);

mtuc115b_assert(is_file($twig), 'module twig present');
mtuc115b_assert(
    strpos($twigSrc, 'Здраве на presentation events') === false,
    'module settings output source has no "Здраве на presentation events"'
);
mtuc115b_assert(strpos($twigSrc, 'text_event_health') === false, 'twig: no text_event_health bindings');
mtuc115b_assert(strpos($twigSrc, 'event_health_ok') === false, 'twig: no event_health_ok');
mtuc115b_assert(strpos($twigSrc, 'event_health_rows') === false, 'twig: no event_health_rows table');
mtuc115b_assert(strpos($twigSrc, 'fa-heartbeat') === false, 'twig: no event-health panel icon');
mtuc115b_assert(strpos($twigSrc, 'form-module') !== false, 'twig: main settings form retained');
mtuc115b_assert(strpos($twigSrc, 'refresh_bank_data') !== false, 'twig: Refresh action retained');
mtuc115b_assert(strpos($twigSrc, 'download_journal') !== false, 'twig: Journal download retained');

mtuc115b_assert(strpos($ctrlSrc, 'repairCatalogEvents') !== false, 'controller: self-heal still runs on Module admin open');
mtuc115b_assert(strpos($ctrlSrc, 'applyEventRepairWarning') !== false, 'controller: failure-only warning helper present');
mtuc115b_assert(strpos($ctrlSrc, 'assignEventHealth') === false, 'controller: assignEventHealth removed');
mtuc115b_assert(
    strpos($ctrlSrc, 'text_event_health_repair_failed') !== false,
    'controller: repair-failed language key still wired'
);

mtuc115b_assert(strpos($modelSrc, 'ensureCatalogEvents($this->db)') !== false, 'model: ensureCatalogEvents retained');
mtuc115b_assert(strpos($modelSrc, 'function repairCatalogEvents') !== false, 'model: repairCatalogEvents retained');
mtuc115b_assert(strpos($installerSrc, 'function ensureCatalogEvents($db)') !== false, 'installer: ensureCatalogEvents retained');
mtuc115b_assert(strpos($installerSrc, 'function removeCatalogEvents($db)') !== false, 'installer: removeCatalogEvents retained');

mtuc115b_assert(strpos($bgSrc, "text_event_health']") === false, 'bg language: obsolete text_event_health title removed');
mtuc115b_assert(strpos($bgSrc, "text_event_health_ok']") === false, 'bg language: obsolete text_event_health_ok removed');
mtuc115b_assert(strpos($bgSrc, "column_event_code']") === false, 'bg language: obsolete event table columns removed');
mtuc115b_assert(
    strpos($bgSrc, "text_event_health_repair_failed']") !== false,
    'bg language: repair-failed warning string retained'
);
mtuc115b_assert(strpos($enSrc, "text_event_health']") === false, 'en language: obsolete text_event_health title removed');
mtuc115b_assert(
    strpos($enSrc, "text_event_health_repair_failed']") !== false,
    'en language: repair-failed warning string retained'
);
mtuc115b_assert(
    strpos($bgSrc, 'Здраве на presentation events') === false,
    'bg language: title string "Здраве на presentation events" removed'
);

echo PHP_EOL;
if ($failures) {
    echo 'FAILED ' . count($failures) . ' / asserted ' . ($passes + count($failures)) . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo 'PHASE 11.5B BACKEND MANUAL AUDIT CLOSURE: PASS — LOCAL (' . $passes . ' assertions)' . PHP_EOL;
exit(0);
