<?php

/**
 * AUD-009 F03-R1 — Checkout MODE_CONFLICT language entry exists (BG/EN).
 * Run: php tests/phase_aud009_f03_r1_conflict_language_check.php
 *
 * PHP 7.3 compatible. Offline.
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
function mtucAud009F03R1_assert($condition, $message)
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

/**
 * @param string $path
 * @return array<string, string>
 */
function mtucAud009F03R1_loadLanguage($path)
{
    $_ = array();
    require $path;

    return $_;
}

$bgPath = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR
    . 'language' . DIRECTORY_SEPARATOR . 'bg-bg' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR
    . 'payment' . DIRECTORY_SEPARATOR . 'mt_uni_credit.php';
$enPath = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR
    . 'language' . DIRECTORY_SEPARATOR . 'en-gb' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR
    . 'payment' . DIRECTORY_SEPARATOR . 'mt_uni_credit.php';

mtucAud009F03R1_assert(is_file($bgPath), 'BG language file exists');
mtucAud009F03R1_assert(is_file($enPath), 'EN language file exists');

$bg = mtucAud009F03R1_loadLanguage($bgPath);
$en = mtucAud009F03R1_loadLanguage($enPath);

mtucAud009F03R1_assert(
    isset($bg['text_prepared_conflict']) && is_string($bg['text_prepared_conflict']),
    'BG defines text_prepared_conflict'
);
mtucAud009F03R1_assert(
    isset($en['text_prepared_conflict']) && is_string($en['text_prepared_conflict']),
    'EN defines text_prepared_conflict'
);

$bgText = isset($bg['text_prepared_conflict']) ? trim((string) $bg['text_prepared_conflict']) : '';
$enText = isset($en['text_prepared_conflict']) ? trim((string) $en['text_prepared_conflict']) : '';

mtucAud009F03R1_assert($bgText !== '', 'BG text_prepared_conflict non-empty');
mtucAud009F03R1_assert($enText !== '', 'EN text_prepared_conflict non-empty');
mtucAud009F03R1_assert(
    $bgText !== 'text_prepared_conflict',
    'BG does not resolve to raw key'
);
mtucAud009F03R1_assert(
    $enText !== 'text_prepared_conflict',
    'EN does not resolve to raw key'
);

$bgLower = mb_strtolower($bgText, 'UTF-8');
$enLower = strtolower($enText);

mtucAud009F03R1_assert(
    strpos($bgLower, 'опитайте отново') === false
        && strpos($bgLower, 'изпратете отново') === false
        && strpos($bgLower, 'не е създадена') === false,
    'BG avoids retry / not-created wording'
);
mtucAud009F03R1_assert(
    strpos($enLower, 'try again') === false
        && strpos($enLower, 'not created') === false
        && strpos($enLower, 'not sent') === false,
    'EN avoids retry / not-created wording'
);

// Prepared-view key remains MODE_CONFLICT → text_prepared_conflict (no rename).
require_once $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR
    . 'library' . DIRECTORY_SEPARATOR . 'mt_uni_credit' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$view = MtUniCreditCheckoutPreparedViewState::fromAttempt(array(
    'state' => MtUniCreditFinancingAttemptState::CP_EXISTING_CONFLICT,
));
mtucAud009F03R1_assert(
    $view['mode'] === MtUniCreditCheckoutPreparedViewState::MODE_CONFLICT,
    'MODE_CONFLICT unchanged'
);
mtucAud009F03R1_assert(empty($view['can_submit']), 'can_submit=false unchanged');
mtucAud009F03R1_assert(
    isset($view['message_key']) && $view['message_key'] === 'text_prepared_conflict',
    'message_key remains text_prepared_conflict'
);

if (count($failures) > 0) {
    echo 'AUD-009 F03-R1: FAIL (' . count($failures) . ' failures, ' . $passes . ' passes)' . PHP_EOL;
    exit(1);
}

echo 'AUD-009 F03-R1: PASS (' . $passes . ' passes)' . PHP_EOL;
exit(0);
