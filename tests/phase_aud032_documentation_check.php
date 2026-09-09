<?php

/**
 * AUD-032 — documentation consistency + real in-memory mutation probes.
 * Run: php tests/phase_aud032_documentation_check.php
 *
 * Docs only. Does not rebuild ZIP or touch runtime / docs on disk.
 * PHP 7.3 compatible. Offline.
 */
require_once __DIR__ . '/bootstrap.php';

$root = MTUC_PHASE0_ROOT;
$contracts = (string) file_get_contents($root . '/docs/CONTRACTS.md');
$runtime = (string) file_get_contents($root . '/docs/RUNTIME_VERIFICATION.md');
$active = $contracts . "\n" . $runtime;

$failures = array();
$passes = 0;

/**
 * @param bool $condition
 * @param string $message
 * @return void
 */
function mtucAud032_assert($condition, $message)
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
 * @param string $haystack
 * @param string $needle
 * @return bool
 */
function mtucAud032_has($haystack, $needle)
{
    return stripos($haystack, $needle) !== false;
}

/**
 * Semantic check helpers — operate on in-memory doc strings only.
 *
 * @param string $contracts
 * @param string $runtime
 * @return bool
 */
function mtucAud032_check01($contracts, $runtime)
{
    return mtucAud032_has($contracts, 'managed UniCredit catalog events')
        && mtucAud032_has($contracts, 'Module uninstall');
}

/**
 * @param string $contracts
 * @param string $runtime
 * @return bool
 */
function mtucAud032_check02($contracts, $runtime)
{
    return mtucAud032_has($contracts, 'does **not** remove shared module catalog events')
        || mtucAud032_has($contracts, 'does not remove shared module catalog events')
        || mtucAud032_has($runtime, 'does **not** remove shared module catalog events')
        || mtucAud032_has($runtime, 'does not remove shared module catalog events')
        || mtucAud032_has($runtime, 'shared events retained');
}

/**
 * @param string $contracts
 * @param string $runtime
 * @return bool
 */
function mtucAud032_check03($contracts, $runtime)
{
    // Retention rule uses "Neither uninstall drops..." — do not treat that as removal.
    $saysRetained = (
        mtucAud032_has($contracts, 'Neither uninstall')
        && mtucAud032_has($contracts, 'persistence tables')
        && (
            mtucAud032_has($contracts, 'not database cleanup')
            || mtucAud032_has($contracts, 'Preserve financing')
            || mtucAud032_has($contracts, 'No ordinary')
        )
    )
        || mtucAud032_has($runtime, 'persistence tables are preserved')
        || mtucAud032_has($runtime, 'financing evidence are **preserved**');
    $combined = $contracts . "\n" . $runtime;
    $saysRemovedOnUninstall = (bool) preg_match(
        '/(persistence tables[^\n]{0,120}(are removed|removed on uninstall)|removes UniCredit persistence tables|Do not preserve financing\/audit persistence)/i',
        $combined
    );

    return $saysRetained && !$saysRemovedOnUninstall;
}

/**
 * @param string $contracts
 * @param string $runtime
 * @return bool
 */
function mtucAud032_check04($contracts, $runtime)
{
    $stale = mtucAud032_has($contracts, 'full stated retention policy is fully implemented')
        || mtucAud032_has($contracts, 'documented **target** policy')
        || preg_match('/RETENTION-001[^\n]*\n(?:.*\n){0,12}.*(pending|not implemented|target policy)/i', $contracts);

    return !$stale
        && mtucAud032_has($contracts, 'RETENTION-001 — Windows (implemented)');
}

/**
 * @param string $contracts
 * @param string $runtime
 * @return bool
 */
function mtucAud032_check05($contracts, $runtime)
{
    return (bool) preg_match('/\*\*180\*\*\s*days/i', $contracts)
        && mtucAud032_has($contracts, 'process2_sensitive_created_at');
}

/**
 * @param string $contracts
 * @param string $runtime
 * @return bool
 */
function mtucAud032_check06($contracts, $runtime)
{
    return (bool) preg_match('/\*\*183\*\*\s*days/i', $contracts)
        && mtucAud032_has($contracts, 'leasing_presentation_created_at');
}

/**
 * @param string $contracts
 * @param string $runtime
 * @return bool
 */
function mtucAud032_check07($contracts, $runtime)
{
    return mtucAud032_has($contracts, '90 days')
        && !preg_match('/Diagnostic journal\s*\|\s*\*\*3 months\*\*/i', $contracts);
}

/**
 * @param string $contracts
 * @param string $runtime
 * @return bool
 */
function mtucAud032_check08($contracts, $runtime)
{
    return mtucAud032_has($contracts, 'idempotent')
        && mtucAud032_has($contracts, 'partial failure');
}

/**
 * @param string $contracts
 * @param string $runtime
 * @return bool
 */
function mtucAud032_check09($contracts, $runtime)
{
    $forbidsAutoDelete = mtucAud032_has($contracts, 'must **not** automatically delete duplicate financial rows')
        || mtucAud032_has($contracts, 'must not automatically delete duplicate financial rows');
    $permitsAutoDelete = (bool) preg_match(
        '/automatically delete duplicate financial rows/i',
        $contracts
    ) && !(bool) preg_match(
        '/must\s+(\*\*)?not(\*\*)?\s+automatically delete duplicate financial rows/i',
        $contracts
    );

    return $forbidsAutoDelete && !$permitsAutoDelete;
}

/**
 * @param string $contracts
 * @param string $runtime
 * @return bool
 */
function mtucAud032_check10($contracts, $runtime)
{
    return mtucAud032_has($contracts, 'DB_PREFIX')
        && mtucAud032_has($contracts, 'non-default');
}

/**
 * @param string $contracts
 * @param string $runtime
 * @return bool
 */
function mtucAud032_check11($contracts, $runtime)
{
    return mtucAud032_has($contracts, 'aborts installation visibly')
        && mtucAud032_has($contracts, 'event registration failure');
}

/**
 * @param string $contracts
 * @param string $runtime
 * @return bool
 */
function mtucAud032_check12($contracts, $runtime)
{
    $hasScript = mtucAud032_has($contracts, 'scripts/package.ps1')
        || mtucAud032_has($runtime, 'scripts/package.ps1');
    $manualZipOk = mtucAud032_has($contracts, 'manually zip the repository')
        && !(mtucAud032_has($contracts, 'Do **not** manually zip')
            || mtucAud032_has($contracts, 'Do not manually zip')
            || mtucAud032_has($contracts, 'never hand-zip'));

    return $hasScript && !$manualZipOk;
}

/**
 * @param string $contracts
 * @param string $runtime
 * @return bool
 */
function mtucAud032_check13($contracts, $runtime)
{
    return mtucAud032_has($contracts, 'manifest')
        && mtucAud032_has($contracts, 'SHA256');
}

/**
 * @param string $contracts
 * @param string $runtime
 * @return bool
 */
function mtucAud032_check14($contracts, $runtime)
{
    $active = $contracts . "\n" . $runtime;

    return mtucAud032_has($active, 'c9203bbf78a103184077c401485293abf41876b7');
}

/**
 * @param string $contracts
 * @param string $runtime
 * @return bool
 */
function mtucAud032_check15($contracts, $runtime)
{
    $active = $contracts . "\n" . $runtime;

    return mtucAud032_has($active, 'F80655ED4E81BABDC56ED1FC5481C3DBDB487CDBBE280CDC2ACD68CE6CD53BA8');
}

/**
 * @param string $contracts
 * @param string $runtime
 * @return bool
 */
function mtucAud032_check16($contracts, $runtime)
{
    return mtucAud032_has($contracts, 'one-shot')
        && mtucAud032_has($contracts, 'cart_clear_state')
        && mtucAud032_has($contracts, 'attempt-specific');
}

/**
 * @param string $contracts
 * @param string $runtime
 * @return bool
 */
function mtucAud032_check17($contracts, $runtime)
{
    $ok = mtucAud032_has($contracts, 'persisted prepared')
        && mtucAud032_has($contracts, 'first installment');
    $bad = (bool) preg_match(
        '/(posted|recalculated).{0,40}(selection|alternatives).{0,80}(may replace|can replace|are authoritative)/i',
        $contracts
    );

    return $ok && !$bad;
}

/**
 * @param string $contracts
 * @param string $runtime
 * @return bool
 */
function mtucAud032_check18($contracts, $runtime)
{
    $ok = mtucAud032_has($contracts, 'already-applied finalization')
        && mtucAud032_has($contracts, 'addOrderHistory');
    $bad = (bool) preg_match(
        '/(blind|manual).{0,40}(replay|repeat).{0,60}(finalization|addOrderHistory)/i',
        $contracts
    ) && !mtucAud032_has($contracts, 'not a blind manual retry')
        && !mtucAud032_has($contracts, 'must not be repeated');

    return $ok && !$bad;
}

/**
 * @param string $contracts
 * @param string $runtime
 * @return bool
 */
function mtucAud032_check19($contracts, $runtime)
{
    return mtucAud032_has($contracts, 'Process 1 transports neither EGN nor phone2')
        || (mtucAud032_has($contracts, 'neither EGN nor phone2') && mtucAud032_has($contracts, 'Process 1'));
}

/**
 * @param string $contracts
 * @param string $runtime
 * @return bool
 */
function mtucAud032_check20($contracts, $runtime)
{
    return mtucAud032_has($contracts, 'neither EGN nor phone2');
}

/**
 * @param string $contracts
 * @param string $runtime
 * @return bool
 */
function mtucAud032_check21($contracts, $runtime)
{
    $prohibits = mtucAud032_has($contracts, 'Unknown CP or SmartUCF outcome')
        && mtucAud032_has($contracts, 'safe resend');
    $allows = (bool) preg_match(
        '/unknown CP or SmartUCF outcome.{0,80}(may be safely resent|safe to resend|safely resent)/i',
        $contracts
    );

    return $prohibits && !$allows;
}

/**
 * @param string $contracts
 * @param string $runtime
 * @return bool
 */
function mtucAud032_check22($contracts, $runtime)
{
    $ok = mtucAud032_has($contracts, 'Must not call `addOrder()`')
        && mtucAud032_has($contracts, 'session.order_id');
    $bad = (bool) preg_match(
        '/UniCredit.{0,80}(creates|create) a second native checkout order/i',
        $contracts
    );

    return $ok && !$bad;
}

/**
 * Apply mutation, require focused check to FAIL on mutated text, PASS on original.
 *
 * @param int $id
 * @param string $contracts
 * @param string $runtime
 * @param callable $mutator function(string $c, string $r): array{0:string,1:string}
 * @param callable $checker function(string $c, string $r): bool
 * @return void
 */
function mtucAud032_expectMutationDetected($id, $contracts, $runtime, $mutator, $checker)
{
    $label = sprintf('Mutation %02d', $id);
    if (!$checker($contracts, $runtime)) {
        mtucAud032_assert(false, $label . ': NO (baseline assertion already failing)');
        echo $label . ': NO' . PHP_EOL;

        return;
    }

    $pair = $mutator($contracts, $runtime);
    $mutContracts = $pair[0];
    $mutRuntime = $pair[1];
    $stillPasses = $checker($mutContracts, $mutRuntime);
    if ($stillPasses) {
        mtucAud032_assert(false, $label . ': NO (mutated docs still pass focused assertion)');
        echo $label . ': NO' . PHP_EOL;

        return;
    }

    mtucAud032_assert(true, $label . ': YES (mutated docs fail focused assertion)');
    echo $label . ': YES' . PHP_EOL;
}

// ---------------------------------------------------------------------------
// Ordinary documentation assertions (unchanged coverage)
// ---------------------------------------------------------------------------
mtucAud032_assert(mtucAud032_check01($contracts, $runtime), '1 Module uninstall mentions managed event removal');
mtucAud032_assert(mtucAud032_check02($contracts, $runtime), '2 Payment uninstall preserves shared events');
mtucAud032_assert(mtucAud032_check03($contracts, $runtime), '3 Persistence tables retained on uninstall');
mtucAud032_assert(mtucAud032_check04($contracts, $runtime), '4 Retention no longer described as unimplemented');
mtucAud032_assert(mtucAud032_check05($contracts, $runtime), '5 Sensitive retention states 180-day policy');
mtucAud032_assert(mtucAud032_check06($contracts, $runtime), '6 Presentation retention states 183-day policy');
mtucAud032_assert(mtucAud032_check07($contracts, $runtime), '7 Diagnostic retention states 90 days');
mtucAud032_assert(mtucAud032_check08($contracts, $runtime), '8 Partial install rerun documented safe');
mtucAud032_assert(mtucAud032_check09($contracts, $runtime), '9 Duplicate financial rows must not be automatically deleted');
mtucAud032_assert(mtucAud032_check10($contracts, $runtime), '10 Non-default DB prefix support documented');
mtucAud032_assert(mtucAud032_check11($contracts, $runtime), '11 Required schema/event install failures documented as visible');
mtucAud032_assert(mtucAud032_check12($contracts, $runtime), '12 Package must use package.ps1');
mtucAud032_assert(mtucAud032_check13($contracts, $runtime), '13 Manifest/hash policy documented');
mtucAud032_assert(mtucAud032_check14($contracts, $runtime), '14 Frozen source HEAD recorded correctly');
mtucAud032_assert(mtucAud032_check15($contracts, $runtime), '15 Frozen ZIP SHA256 recorded correctly');
mtucAud032_assert(mtucAud032_check16($contracts, $runtime), '16 Cart-clear one-shot rule documented');
mtucAud032_assert(mtucAud032_check17($contracts, $runtime), '17 Prepared selection authority documented');
mtucAud032_assert(mtucAud032_check18($contracts, $runtime), '18 Native finalization no-blind-replay rule documented');
mtucAud032_assert(mtucAud032_check19($contracts, $runtime), '19 P1 excludes EGN');
mtucAud032_assert(mtucAud032_check20($contracts, $runtime), '20 P1 excludes phone2');
mtucAud032_assert(mtucAud032_check21($contracts, $runtime), '21 Ambiguous CP/SmartUCF resend remains prohibited');
mtucAud032_assert(mtucAud032_check22($contracts, $runtime), '22 Native order reuse remains documented');

mtucAud032_assert(
    mtucAud032_has($contracts, 'bank_sent_process1')
        && mtucAud032_has($contracts, 'bank_sent_process2')
        && mtucAud032_has($contracts, 'bank_send_failed_smartucf')
        && mtucAud032_has($contracts, 'bank_send_failed_cp'),
    'CP/SmartUCF semantics table retained'
);
mtucAud032_assert(
    mtucAud032_has($contracts, 'status-0')
        || mtucAud032_has($contracts, 'status **0**'),
    'status-0 draft wording retained'
);
mtucAud032_assert(
    mtucAud032_has($runtime, 'testability')
        && mtucAud032_has($runtime, 'not product failure'),
    'remote definitive CP exception labelled testability, not product failure'
);

// ---------------------------------------------------------------------------
// Real in-memory mutation probes (independent of ordinary PASS aggregate)
// ---------------------------------------------------------------------------
echo PHP_EOL . '--- Mutation probes (in-memory only) ---' . PHP_EOL;

mtucAud032_expectMutationDetected(
    1,
    $contracts,
    $runtime,
    function ($c, $r) {
        $c = str_ireplace('managed UniCredit catalog events', 'module settings only', $c);

        return array($c, $r);
    },
    'mtucAud032_check01'
);

mtucAud032_expectMutationDetected(
    2,
    $contracts,
    $runtime,
    function ($c, $r) {
        $c = str_ireplace(
            'does **not** remove shared module catalog events',
            'removes shared module catalog events',
            $c
        );
        $c = str_ireplace(
            'does not remove shared module catalog events',
            'removes shared module catalog events',
            $c
        );
        $r = str_ireplace(
            'does **not** remove shared module catalog events',
            'removes shared module catalog events',
            $r
        );
        $r = str_ireplace('shared events retained', 'shared events removed', $r);

        return array($c, $r);
    },
    'mtucAud032_check02'
);

mtucAud032_expectMutationDetected(
    3,
    $contracts,
    $runtime,
    function ($c, $r) {
        $c = preg_replace(
            '/\*\*Neither uninstall\*\*[^\n]+/',
            '**Module uninstall** removes UniCredit persistence tables / financing evidence are removed on uninstall.',
            $c,
            1
        );
        $c = str_ireplace('Uninstall is not database cleanup.', 'Uninstall performs database cleanup.', $c);
        $c = str_ireplace('Preserve financing/audit persistence tables by default.', 'Do not preserve financing/audit persistence tables.', $c);

        return array($c, $r);
    },
    'mtucAud032_check03'
);

mtucAud032_expectMutationDetected(
    4,
    $contracts,
    $runtime,
    function ($c, $r) {
        $c = str_replace(
            'RETENTION-001 — Windows (implemented)',
            'RETENTION-001 — Windows (pending / target / not implemented)',
            $c
        );
        $c = str_replace(
            '| Leasing presentation JSON               | **183** days via dedicated `leasing_presentation_created_at` (182/183 retained; 184 cleared) |',
            '| Leasing presentation JSON               | **183** days (documented **target** policy) |',
            $c
        );

        return array($c, $r);
    },
    'mtucAud032_check04'
);

mtucAud032_expectMutationDetected(
    5,
    $contracts,
    $runtime,
    function ($c, $r) {
        $c = preg_replace('/\*\*180\*\*\s*days/', '**179** days', $c, 1);

        return array($c, $r);
    },
    'mtucAud032_check05'
);

mtucAud032_expectMutationDetected(
    6,
    $contracts,
    $runtime,
    function ($c, $r) {
        $c = preg_replace('/\*\*183\*\*\s*days/', '**150** days', $c, 1);

        return array($c, $r);
    },
    'mtucAud032_check06'
);

mtucAud032_expectMutationDetected(
    7,
    $contracts,
    $runtime,
    function ($c, $r) {
        $c = str_replace('**90 days**', '**3 months**', $c);
        $c = preg_replace(
            '/\|\s*Diagnostic journal\s*\|\s*[^\n]+/',
            '| Diagnostic journal                      | **3 months**                                |',
            $c,
            1
        );

        return array($c, $r);
    },
    'mtucAud032_check07'
);

mtucAud032_expectMutationDetected(
    8,
    $contracts,
    $runtime,
    function ($c, $r) {
        $c = str_ireplace('idempotent', 'one-shot only', $c);
        $c = str_ireplace('partial failure', 'complete wipe required', $c);

        return array($c, $r);
    },
    'mtucAud032_check08'
);

mtucAud032_expectMutationDetected(
    9,
    $contracts,
    $runtime,
    function ($c, $r) {
        $c = str_ireplace(
            'must **not** automatically delete duplicate financial rows',
            'should automatically delete duplicate financial rows',
            $c
        );
        $c = str_ireplace(
            'must not automatically delete duplicate financial rows',
            'should automatically delete duplicate financial rows',
            $c
        );

        return array($c, $r);
    },
    'mtucAud032_check09'
);

mtucAud032_expectMutationDetected(
    10,
    $contracts,
    $runtime,
    function ($c, $r) {
        $c = str_ireplace('non-default `DB_PREFIX` is supported', 'only default oc_ prefix is supported', $c);
        $c = str_ireplace('non-default', 'default-only', $c);

        return array($c, $r);
    },
    'mtucAud032_check10'
);

mtucAud032_expectMutationDetected(
    11,
    $contracts,
    $runtime,
    function ($c, $r) {
        $c = str_ireplace('aborts installation visibly', 'is logged quietly and install continues', $c);
        $c = str_ireplace('event registration failure', 'event registration soft-warning', $c);

        return array($c, $r);
    },
    'mtucAud032_check11'
);

mtucAud032_expectMutationDetected(
    12,
    $contracts,
    $runtime,
    function ($c, $r) {
        $c = str_ireplace('scripts/package.ps1', 'manual repository ZIP', $c);
        $c = str_ireplace('Do **not** manually zip', 'Operators may manually zip', $c);
        $c = str_ireplace('Do not manually zip', 'Operators may manually zip', $c);
        $r = str_ireplace('scripts/package.ps1', 'manual repository ZIP', $r);
        $r = str_ireplace('never hand-zip', 'may hand-zip', $r);

        return array($c, $r);
    },
    'mtucAud032_check12'
);

mtucAud032_expectMutationDetected(
    13,
    $contracts,
    $runtime,
    function ($c, $r) {
        $c = str_ireplace('manifest', 'file list hint', $c);
        $c = str_ireplace('SHA256', 'size check', $c);

        return array($c, $r);
    },
    'mtucAud032_check13'
);

mtucAud032_expectMutationDetected(
    14,
    $contracts,
    $runtime,
    function ($c, $r) {
        $bad = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $c = str_replace('c9203bbf78a103184077c401485293abf41876b7', $bad, $c);
        $r = str_replace('c9203bbf78a103184077c401485293abf41876b7', $bad, $r);

        return array($c, $r);
    },
    'mtucAud032_check14'
);

mtucAud032_expectMutationDetected(
    15,
    $contracts,
    $runtime,
    function ($c, $r) {
        $bad = '0000000000000000000000000000000000000000000000000000000000000000';
        $c = str_replace('F80655ED4E81BABDC56ED1FC5481C3DBDB487CDBBE280CDC2ACD68CE6CD53BA8', $bad, $c);
        $r = str_replace('F80655ED4E81BABDC56ED1FC5481C3DBDB487CDBBE280CDC2ACD68CE6CD53BA8', $bad, $r);

        return array($c, $r);
    },
    'mtucAud032_check15'
);

mtucAud032_expectMutationDetected(
    16,
    $contracts,
    $runtime,
    function ($c, $r) {
        $c = str_ireplace('one-shot', 'repeatable', $c);
        $c = str_ireplace('cart_clear_state', 'cart_session_flag', $c);
        $c = str_ireplace('attempt-specific', 'global', $c);

        return array($c, $r);
    },
    'mtucAud032_check16'
);

mtucAud032_expectMutationDetected(
    17,
    $contracts,
    $runtime,
    function ($c, $r) {
        $c = str_ireplace('persisted prepared', 'ephemeral browser', $c);
        $c .= "\n\nPosted/recalculated selection may replace the prepared selection during submit/recovery.\n";

        return array($c, $r);
    },
    'mtucAud032_check17'
);

mtucAud032_expectMutationDetected(
    18,
    $contracts,
    $runtime,
    function ($c, $r) {
        $c = str_ireplace('already-applied finalization must not be repeated', 'already-applied finalization may be repeated', $c);
        $c = str_ireplace('not a blind manual retry candidate', 'is a blind manual retry candidate', $c);
        $c .= "\n\nOperators may blind replay native finalization via repeated addOrderHistory().\n";

        return array($c, $r);
    },
    'mtucAud032_check18'
);

mtucAud032_expectMutationDetected(
    19,
    $contracts,
    $runtime,
    function ($c, $r) {
        $c = str_ireplace(
            'Process 1 transports neither EGN nor phone2',
            'Process 1 may transport EGN when required',
            $c
        );
        $c = str_ireplace('neither EGN nor phone2', 'EGN on Process 1 is allowed', $c);

        return array($c, $r);
    },
    'mtucAud032_check19'
);

mtucAud032_expectMutationDetected(
    20,
    $contracts,
    $runtime,
    function ($c, $r) {
        $c = str_ireplace(
            'Process 1 transports neither EGN nor phone2',
            'Process 1 may transport phone2 when required',
            $c
        );
        $c = str_ireplace('neither EGN nor phone2', 'phone2 on Process 1 is allowed', $c);

        return array($c, $r);
    },
    'mtucAud032_check20'
);

mtucAud032_expectMutationDetected(
    21,
    $contracts,
    $runtime,
    function ($c, $r) {
        $c = str_ireplace(
            'Unknown CP or SmartUCF outcome **≠** safe resend',
            'Unknown CP or SmartUCF outcome may be safely resent',
            $c
        );
        $c = str_ireplace(
            'Unknown CP or SmartUCF outcome',
            'Unknown CP or SmartUCF outcome may be safely resent; former note:',
            $c
        );

        return array($c, $r);
    },
    'mtucAud032_check21'
);

mtucAud032_expectMutationDetected(
    22,
    $contracts,
    $runtime,
    function ($c, $r) {
        $c = str_ireplace('Must not call `addOrder()`', 'May call `addOrder()` again', $c);
        $c .= "\n\nUniCredit creates a second native checkout order instead of reusing session.order_id.\n";

        return array($c, $r);
    },
    'mtucAud032_check22'
);

echo PHP_EOL . 'AUD-032 documentation: ' . $passes . ' PASS, ' . count($failures) . ' FAIL' . PHP_EOL;
if ($failures !== array()) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

exit(0);
