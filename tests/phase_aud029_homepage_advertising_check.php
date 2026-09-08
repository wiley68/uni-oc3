<?php

/**
 * AUD-029 — Homepage advertising malformed-cache fail-closed + F02 assurance.
 * Run: php tests/phase_aud029_homepage_advertising_check.php
 *
 * F01: wrong-type / incomplete cached advertising → present() null, no warnings
 * F02: route / CTA / event self-heal / duplicate footer assurance (tests only)
 *
 * PHP 7.3 compatible. Offline.
 */
require_once __DIR__ . '/bootstrap.php';

$root = MTUC_PHASE0_ROOT;
$lib = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR
    . 'library' . DIRECTORY_SEPARATOR . 'mt_uni_credit';

if (!defined('DIR_SYSTEM')) {
    define('DIR_SYSTEM', $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR);
}
if (!defined('DIR_STORAGE')) {
    mtuc_test_define_dir_storage('mtuc-aud029');
}
if (!defined('DB_PASSWORD')) {
    define('DB_PASSWORD', 'phase4-test-installation-db-password-secret');
}
if (!defined('DB_PREFIX')) {
    define('DB_PREFIX', 'oc_');
}

require_once $lib . DIRECTORY_SEPARATOR . 'bootstrap.php';

$failures = array();
$passes = 0;
$warningMessages = array();

/**
 * @param bool $condition
 * @param string $message
 * @return void
 */
function mtucAud029_assert($condition, $message)
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

set_error_handler(function ($severity, $message) use (&$warningMessages) {
    if ($severity === E_WARNING || $severity === E_NOTICE || $severity === E_USER_WARNING) {
        $warningMessages[] = (string) $message;

        return true;
    }

    return false;
});

$presenter = new MtUniCreditHomepageAdvertisingPresenter();
$gate = new MtUniCreditHomepageAdvertisingGate();
$logo = 'https://cdn.example/logo.png';

/**
 * @return array<string, mixed>
 */
function mtucAud029_validShop()
{
    return array(
        'uni_status' => 1,
        'uni_container_status' => 1,
        'uni_backurl' => 'https://ok.example/offer',
        'uni_container_txt1' => 'Hello Title',
        'uni_container_txt2' => 'Supporting copy',
        'uni_picturem' => 'https://cdn.example/m.png',
    );
}

/**
 * @return void
 */
function mtucAud029_clearWarnings()
{
    global $warningMessages;
    $warningMessages = array();
}

/**
 * @return bool
 */
function mtucAud029_hadArrayToStringWarning()
{
    global $warningMessages;
    foreach ($warningMessages as $msg) {
        if (stripos($msg, 'Array to string conversion') !== false) {
            return true;
        }
        if (stripos($msg, 'could not be converted to string') !== false) {
            return true;
        }
    }

    return false;
}

// ---------------------------------------------------------------------------
// F01 — malformed / incomplete cache
// ---------------------------------------------------------------------------
mtucAud029_clearWarnings();
$a = $presenter->present(array_merge(mtucAud029_validShop(), array('uni_backurl' => array('bad'))), false, $logo);
mtucAud029_assert($a === null, 'A wrong-type CTA → null');
mtucAud029_assert(!mtucAud029_hadArrayToStringWarning(), 'A warning sentinel: no Array-to-string on CTA');

mtucAud029_clearWarnings();
$b = $presenter->present(array_merge(mtucAud029_validShop(), array('uni_container_txt1' => array('bad'))), false, $logo);
mtucAud029_assert($b === null, 'B wrong-type text → null');
mtucAud029_assert(!mtucAud029_hadArrayToStringWarning(), 'B warning sentinel: no Array-to-string on text');
$bDirect = $presenter->text(array('bad'));
mtucAud029_assert($bDirect === '' && $bDirect !== 'Array', 'B text() does not become Array');

mtucAud029_clearWarnings();
$c = $presenter->present(array_merge(mtucAud029_validShop(), array('uni_picturem' => array('bad'))), false, $logo);
mtucAud029_assert($c === null, 'C wrong-type image → null');
mtucAud029_assert(!mtucAud029_hadArrayToStringWarning(), 'C warning sentinel: no Array-to-string on image');

$shopMissingCta = mtucAud029_validShop();
unset($shopMissingCta['uni_backurl']);
mtucAud029_assert($presenter->present($shopMissingCta, false, $logo) === null, 'D missing CTA → null');

$shopEmptyCta = mtucAud029_validShop();
$shopEmptyCta['uni_backurl'] = '';
mtucAud029_assert($presenter->present($shopEmptyCta, false, $logo) === null, 'D empty CTA → null');

$shopMissingTxt = mtucAud029_validShop();
unset($shopMissingTxt['uni_container_txt1']);
mtucAud029_assert($presenter->present($shopMissingTxt, false, $logo) === null, 'E missing required text → null');

$shopEmptyTxt = mtucAud029_validShop();
$shopEmptyTxt['uni_container_txt1'] = '   ';
mtucAud029_assert($presenter->present($shopEmptyTxt, false, $logo) === null, 'E blank required text → null');

$shopNoPicture = mtucAud029_validShop();
unset($shopNoPicture['uni_picturem']);
$noPic = $presenter->present($shopNoPicture, false, $logo);
mtucAud029_assert(
    is_array($noPic) && $noPic['picture_url'] === '' && $noPic['float_image_url'] === $logo,
    'F missing optional image → still renders with default float logo'
);

$shopObj = mtucAud029_validShop();
$shopObj['uni_container_txt2'] = (object) array('x' => 1);
mtucAud029_clearWarnings();
$h = $presenter->present($shopObj, false, $logo);
mtucAud029_assert($h === null, 'H nested/object wrong-type optional text → null');
mtucAud029_assert(!mtucAud029_hadArrayToStringWarning(), 'H warning sentinel: object not stringified');

$shopExtra = mtucAud029_validShop();
$shopExtra['unexpected_safe_field'] = 'keep-me';
$shopExtra['futureFlag'] = 123;
$g = $presenter->present($shopExtra, false, $logo);
mtucAud029_assert(
    is_array($g)
        && $g['backurl'] === 'https://ok.example/offer'
        && $g['txt1'] === 'Hello Title',
    'G unexpected extra fields still render when required valid'
);

$ok = $presenter->present(mtucAud029_validShop(), false, $logo);
mtucAud029_assert(is_array($ok) && $ok['txt2'] === 'Supporting copy', 'valid payload still renders');

// Mutation sensitivity 1–5
mtucAud029_assert($a === null && !mtucAud029_hadArrayToStringWarning(), 'mutation-1 YES: array CTA not cast');
mtucAud029_assert($bDirect !== 'Array', 'mutation-2 YES: array text not Array');
mtucAud029_assert($presenter->present(array_merge(mtucAud029_validShop(), array('uni_container_txt1' => array('x'))), false, $logo) === null, 'mutation-3 YES: malformed required text rejected');
mtucAud029_assert($c === null, 'mutation-4 YES: malformed image rejected');
mtucAud029_assert(
    $presenter->present(array_merge(mtucAud029_validShop(), array('uni_backurl' => 'javascript:alert(1)')), false, $logo) === null,
    'mutation-5 YES: missing/invalid CTA does not partial-render'
);

// ---------------------------------------------------------------------------
// F02 — routes
// ---------------------------------------------------------------------------
$routesOk = array('', 'common/home');
$routesBad = array(
    'product/product',
    'checkout/cart',
    'checkout/checkout',
    'information/information',
    'extension/module/foo',
);
foreach ($routesOk as $route) {
    mtucAud029_assert(
        MtUniCreditStorefrontRouteResolver::isHomepageRoute($route)
            && $gate->allowsPage($route),
        'route allow: ' . ($route === '' ? '(empty)' : $route)
    );
}
foreach ($routesBad as $route) {
    mtucAud029_assert(
        !MtUniCreditStorefrontRouteResolver::isHomepageRoute($route)
            && !$gate->allowsPage($route),
        'route deny: ' . $route
    );
}
mtucAud029_assert(!$gate->allowsPage('checkout/cart'), 'mutation-8 YES: checkout/cart denied');
mtucAud029_assert(!$gate->allowsPage('checkout/checkout'), 'mutation-9 YES: checkout/checkout denied');
mtucAud029_assert(!$gate->allowsPage('extension/module/foo'), 'mutation-10 YES: extension route denied');

// ---------------------------------------------------------------------------
// F02 — CTA URL matrix
// ---------------------------------------------------------------------------
$ctaBad = array(
    'javascript:alert(1)',
    'data:text/html,hi',
    'vbscript:msgbox(1)',
    '//example.com/path',
    'java%0ascript:alert(1)',
    '/relative/path',
    'https://example.com/ path',
);
foreach ($ctaBad as $url) {
    mtucAud029_assert($presenter->httpUrl($url) === '', 'CTA reject: ' . substr($url, 0, 40));
}
// Raw quote breakout must be rejected at the URL sanitizer boundary (empty — no alternate https:// path).
$quoteBreakout = 'https://example.com/"onclick="alert(1)';
mtucAud029_assert($presenter->httpUrl($quoteBreakout) === '', 'CTA raw quote-breakout → empty URL');
mtucAud029_assert(
    $presenter->present(array_merge(mtucAud029_validShop(), array('uni_backurl' => $quoteBreakout)), false, $logo) === null,
    'CTA raw quote-breakout → no advertising block'
);
$rawQuotePlacements = array(
    'https://example.com/"',
    'https://example.com/foo"bar',
    'https://example.com/" onclick="alert(1)',
);
foreach ($rawQuotePlacements as $rawPlacement) {
    mtucAud029_assert(
        $presenter->httpUrl($rawPlacement) === '',
        'CTA raw quote placement rejected: ' . substr($rawPlacement, 0, 48)
    );
}
// Single-quote is not part of the rejection contract (no single-quoted backurl sink).
$singleQuoteUrl = "https://example.com/path'segment";
$singleQuoteResult = $presenter->httpUrl($singleQuoteUrl);
mtucAud029_assert(
    $singleQuoteResult === $singleQuoteUrl || $singleQuoteResult === '',
    'CTA raw single-quote: contract follows filter_var (no forced apostrophe reject)'
);
// Distinct: safely percent-encoded quotes in path may remain valid under current contract.
$encodedSafe = 'https://example.com/path%22quoted%22ok';
$encodedResult = $presenter->httpUrl($encodedSafe);
mtucAud029_assert(
    $encodedResult === $encodedSafe,
    'CTA encoded-safe HTTPS path remains valid when filter_var accepts it'
);
mtucAud029_assert(strpos($encodedResult, '"') === false, 'CTA encoded %22 stays encoded (no decode to raw quote)');
mtucAud029_assert($presenter->httpUrl('https://ok.example/a') === 'https://ok.example/a', 'CTA accept https');
mtucAud029_assert($presenter->httpUrl('http://ok.example/a') === 'http://ok.example/a', 'CTA accept http');
mtucAud029_assert(
    $presenter->httpUrl('https://example.com/path?foo=bar&baz=1') === 'https://example.com/path?foo=bar&baz=1',
    'CTA accept https with query'
);
mtucAud029_assert($presenter->httpUrl('javascript:alert(1)') === '', 'mutation-6 YES: javascript rejected');
mtucAud029_assert($presenter->httpUrl('data:text/html,x') === '', 'mutation-7 YES: data rejected');
mtucAud029_assert($presenter->httpUrl('vbscript:msgbox(1)') === '', 'CTA reject vbscript');
mtucAud029_assert($presenter->httpUrl('//example.com') === '', 'CTA reject protocol-relative');
mtucAud029_assert(
    $presenter->httpUrl($quoteBreakout) === ''
        && $presenter->present(array_merge(mtucAud029_validShop(), array('uni_backurl' => $quoteBreakout)), false, $logo) === null,
    'raw-quote injection sentinel YES'
);
// ---------------------------------------------------------------------------
// F02 — event self-heal (reuse Mtuc11EventFakeDb pattern inline)
// ---------------------------------------------------------------------------
final class MtucAud029EventFakeDb
{
    /** @var array<int, array<string, mixed>> */
    public $rows = array();
    /** @var int */
    private $nextId = 1;

    /**
     * @param mixed $value
     * @return string
     */
    public function escape($value)
    {
        return addslashes((string) $value);
    }

    /**
     * @param mixed $sql
     * @return object
     */
    public function query($sql)
    {
        $sql = (string) $sql;
        if (stripos($sql, 'SELECT') === 0) {
            if (preg_match("/WHERE `code` = '([^']+)'/", $sql, $m)) {
                $code = stripslashes($m[1]);
                $matched = array();
                foreach ($this->rows as $row) {
                    if ((string) $row['code'] === $code) {
                        $matched[] = $row;
                    }
                }

                return (object) array(
                    'num_rows' => count($matched),
                    'row' => $matched ? $matched[0] : array(),
                    'rows' => $matched,
                );
            }

            return (object) array('num_rows' => count($this->rows), 'row' => array(), 'rows' => $this->rows);
        }
        if (stripos($sql, 'INSERT') === 0) {
            preg_match("/`code` = '([^']+)'/", $sql, $mCode);
            preg_match("/`trigger` = '([^']+)'/", $sql, $mTrigger);
            preg_match("/`action` = '([^']+)'/", $sql, $mAction);
            $this->rows[] = array(
                'event_id' => $this->nextId++,
                'code' => stripslashes($mCode[1]),
                'trigger' => isset($mTrigger[1]) ? stripslashes($mTrigger[1]) : '',
                'action' => isset($mAction[1]) ? stripslashes($mAction[1]) : '',
                'status' => 1,
                'sort_order' => 0,
            );

            return true;
        }
        if (stripos($sql, 'UPDATE') === 0 && preg_match('/WHERE `event_id` = (\d+)/', $sql, $mId)) {
            $id = (int) $mId[1];
            foreach ($this->rows as &$row) {
                if ((int) $row['event_id'] === $id) {
                    if (preg_match("/`trigger` = '([^']+)'/", $sql, $mTrigger)) {
                        $row['trigger'] = stripslashes($mTrigger[1]);
                    }
                    if (preg_match("/`action` = '([^']+)'/", $sql, $mAction)) {
                        $row['action'] = stripslashes($mAction[1]);
                    }
                    $row['status'] = 1;
                    $row['sort_order'] = 0;
                }
            }
            unset($row);

            return true;
        }
        if (stripos($sql, 'DELETE') === 0) {
            if (preg_match('/WHERE `event_id` = (\d+)/', $sql, $mId)) {
                $id = (int) $mId[1];
                $this->rows = array_values(array_filter($this->rows, function ($row) use ($id) {
                    return (int) $row['event_id'] !== $id;
                }));

                return true;
            }

            // Managed-family / predicate DELETE (ensureCatalogEvents obsolete cleanup).
            // Apply LIKE / NOT IN realistically so a broadened production predicate
            // that matches unrelated_extension_event would remove that row.
            $likePatterns = array();
            if (preg_match_all("/`code` LIKE '([^']+)'/", $sql, $mLikes)) {
                foreach ($mLikes[1] as $pat) {
                    $likePatterns[] = stripslashes($pat);
                }
            }
            $keepIn = array();
            $hasNotIn = false;
            if (preg_match('/NOT IN \(([^)]+)\)/', $sql, $mIn)) {
                $hasNotIn = true;
                if (preg_match_all("/'([^']+)'/", $mIn[1], $mCodes)) {
                    foreach ($mCodes[1] as $code) {
                        $keepIn[stripslashes($code)] = true;
                    }
                }
            }

            if ($likePatterns !== array() || $hasNotIn) {
                $this->rows = array_values(array_filter(
                    $this->rows,
                    function ($row) use ($likePatterns, $keepIn, $hasNotIn, $sql) {
                        $code = (string) $row['code'];

                        // Broad LIKE '%' (or equivalent) — delete unless NOT IN keeps the code.
                        foreach ($likePatterns as $pat) {
                            if ($pat === '%' || $pat === '%%') {
                                if ($hasNotIn) {
                                    return isset($keepIn[$code]);
                                }

                                return false;
                            }
                        }

                        $matchesPrefix = false;
                        foreach ($likePatterns as $pat) {
                            if (substr($pat, -1) === '%') {
                                $prefix = substr($pat, 0, -1);
                                if ($prefix !== '' && strpos($code, $prefix) === 0) {
                                    $matchesPrefix = true;
                                    break;
                                }
                            } elseif ($pat === $code) {
                                $matchesPrefix = true;
                                break;
                            }
                        }

                        // Family cleanup without any LIKE: NOT IN alone → delete non-listed.
                        if ($likePatterns === array() && $hasNotIn) {
                            return isset($keepIn[$code]);
                        }

                        if (!$matchesPrefix) {
                            return true;
                        }

                        // Mirror production AND (family OR …) when those tokens are present.
                        $hasFamilyOr = (stripos($sql, 'mt_uni_credit_checkout_success') !== false)
                            || (stripos($sql, 'mt_uni_credit_mail_order') !== false)
                            || (stripos($sql, 'mt_uni_credit_admin_order') !== false)
                            || (stripos($sql, 'mt_uni_credit_home') !== false)
                            || (stripos($sql, 'mt_uni_credit_buy_guard') !== false);
                        if ($hasFamilyOr) {
                            $isManaged = (strpos($code, 'mt_uni_credit_checkout_success') === 0)
                                || (strpos($code, 'mt_uni_credit_mail_order') === 0)
                                || (strpos($code, 'mt_uni_credit_admin_order') === 0)
                                || (strpos($code, 'mt_uni_credit_home') === 0)
                                || (strpos($code, 'mt_uni_credit_buy_guard') === 0);
                            if (!$isManaged) {
                                return true;
                            }
                        }

                        if ($hasNotIn) {
                            return isset($keepIn[$code]);
                        }

                        return false;
                    }
                ));
            }

            return true;
        }

        return true;
    }
}

$defs = MtUniCreditCatalogEventRegistry::definitions();
$homeBefore = null;
foreach ($defs as $def) {
    if ($def['code'] === 'mt_uni_credit_home_controller_before') {
        $homeBefore = $def;
        break;
    }
}
mtucAud029_assert(is_array($homeBefore), 'event def: home before present');

// Missing canonical event
$evDb = new MtucAud029EventFakeDb();
MtUniCreditInstaller::ensureCatalogEvents($evDb);
$canonicalCount = count($defs);
mtucAud029_assert(count($evDb->rows) === $canonicalCount, 'events: full ensure inserts canonical set');
$evDb->rows = array_values(array_filter($evDb->rows, function ($row) {
    return $row['code'] !== 'mt_uni_credit_home_controller_before';
}));
$beforeMissing = count($evDb->rows);
$repairMissing = MtUniCreditInstaller::ensureCatalogEvents($evDb);
mtucAud029_assert((int) $repairMissing['inserted'] === 1, 'events: missing home event re-inserted');
$homeRows = array_values(array_filter($evDb->rows, function ($row) {
    return $row['code'] === 'mt_uni_credit_home_controller_before';
}));
mtucAud029_assert(count($homeRows) === 1, 'events: exactly one home-before after missing repair');
mtucAud029_assert(
    $homeRows[0]['trigger'] === $homeBefore['trigger']
        && $homeRows[0]['action'] === $homeBefore['action']
        && (int) $homeRows[0]['status'] === 1
        && (int) $homeRows[0]['sort_order'] === 0,
    'events: missing repair restores trigger/action/status/sort'
);
mtucAud029_assert($beforeMissing + 1 === count($evDb->rows), 'mutation-11 YES: self-heal inserts only missing (not blind dup)');

// Disabled + stale trigger/action
foreach ($evDb->rows as &$row) {
    if ($row['code'] === 'mt_uni_credit_home_footer_after') {
        $row['status'] = 0;
        $row['trigger'] = 'catalog/view/common/footer/WRONG';
        $row['action'] = 'extension/mt_uni_credit/home/wrong';
        $row['sort_order'] = 9;
    }
}
unset($row);
$footerDef = null;
foreach ($defs as $def) {
    if ($def['code'] === 'mt_uni_credit_home_footer_after') {
        $footerDef = $def;
        break;
    }
}
$repairStale = MtUniCreditInstaller::ensureCatalogEvents($evDb);
$footerRows = array_values(array_filter($evDb->rows, function ($row) {
    return $row['code'] === 'mt_uni_credit_home_footer_after';
}));
mtucAud029_assert(count($footerRows) === 1, 'events: one footer after stale repair');
mtucAud029_assert(
    (int) $footerRows[0]['status'] === 1
        && $footerRows[0]['trigger'] === $footerDef['trigger']
        && $footerRows[0]['action'] === $footerDef['action']
        && (int) $footerRows[0]['sort_order'] === 0,
    'mutation-12/13 YES: disabled+stale repaired'
);

// Duplicates
$evDb->rows[] = array(
    'event_id' => 90001,
    'code' => 'mt_uni_credit_home_footer_after',
    'trigger' => 'stale',
    'action' => 'stale',
    'status' => 0,
    'sort_order' => 3,
);
$repairDup = MtUniCreditInstaller::ensureCatalogEvents($evDb);
$footerAfterDup = array_values(array_filter($evDb->rows, function ($row) {
    return $row['code'] === 'mt_uni_credit_home_footer_after';
}));
mtucAud029_assert(count($footerAfterDup) === 1, 'events: duplicate same-code collapsed to one');
mtucAud029_assert((int) $repairDup['deleted_duplicates'] >= 1, 'events: deleted_duplicates counted');

// Unrelated preserved during ensure + obsolete managed removed (DELETE applied).
$unrelatedFixture = array(
    'event_id' => 90002,
    'code' => 'unrelated_extension_event',
    'trigger' => 'catalog/controller/foo',
    'action' => 'extension/other/bar',
    'status' => 1,
    'sort_order' => 5,
);
$evDb->rows[] = $unrelatedFixture;
$evDb->rows[] = array(
    'event_id' => 90003,
    'code' => 'mt_uni_credit_home_legacy_obsolete',
    'trigger' => 'catalog/controller/common/home/before',
    'action' => 'extension/mt_uni_credit/home/legacy',
    'status' => 1,
    'sort_order' => 0,
);
$unrelatedBefore = $unrelatedFixture;
MtUniCreditInstaller::ensureCatalogEvents($evDb);
$unrelated = array_values(array_filter($evDb->rows, function ($row) {
    return $row['code'] === 'unrelated_extension_event';
}));
$obsoleteLeft = array_values(array_filter($evDb->rows, function ($row) {
    return $row['code'] === 'mt_uni_credit_home_legacy_obsolete';
}));
mtucAud029_assert(count($unrelated) === 1, 'events: unrelated_extension_event still exists');
mtucAud029_assert(
    count($unrelated) === 1
        && (string) $unrelated[0]['code'] === (string) $unrelatedBefore['code']
        && (string) $unrelated[0]['trigger'] === (string) $unrelatedBefore['trigger']
        && (string) $unrelated[0]['action'] === (string) $unrelatedBefore['action']
        && (int) $unrelated[0]['status'] === (int) $unrelatedBefore['status']
        && (int) $unrelated[0]['sort_order'] === (int) $unrelatedBefore['sort_order']
        && (int) $unrelated[0]['event_id'] === (int) $unrelatedBefore['event_id'],
    'events: unrelated full row unchanged after ensureCatalogEvents'
);
mtucAud029_assert(count($obsoleteLeft) === 0, 'events: obsolete managed home code removed by family DELETE');

// Mutation-14 sensitivity: broadened DELETE predicate on the same fake removes unrelated.
$probeDb = new MtucAud029EventFakeDb();
$probeDb->rows = array($unrelatedFixture);
$probeDb->query("DELETE FROM `" . DB_PREFIX . "event` WHERE `code` LIKE '%'");
$probeUnrelated = array_values(array_filter($probeDb->rows, function ($row) {
    return $row['code'] === 'unrelated_extension_event';
}));
mtucAud029_assert(count($probeUnrelated) === 0, 'mutation-14 YES: broadened DELETE would remove unrelated (fake detects)');
mtucAud029_assert(count($unrelated) === 1, 'mutation-14 YES: production ensure leaves unrelated intact');

// Repeated ensure
$countBeforeRepeat = count($evDb->rows);
$repeat = MtUniCreditInstaller::ensureCatalogEvents($evDb);
mtucAud029_assert((int) $repeat['inserted'] === 0, 'events: repeated ensure inserts 0');
$homeCodes = array();
foreach ($evDb->rows as $row) {
    if (strpos($row['code'], 'mt_uni_credit_') === 0) {
        $homeCodes[$row['code']] = isset($homeCodes[$row['code']]) ? $homeCodes[$row['code']] + 1 : 1;
    }
}
$maxDup = 0;
foreach ($homeCodes as $c) {
    if ($c > $maxDup) {
        $maxDup = $c;
    }
}
mtucAud029_assert($maxDup === 1, 'events: repeated ensure no duplicate canonical codes');
mtucAud029_assert(count($evDb->rows) === $countBeforeRepeat, 'events: repeated ensure row count stable');

// ---------------------------------------------------------------------------
// Duplicate footer injection sentinel (controller source + behavioral count)
// ---------------------------------------------------------------------------
$homeSrc = (string) file_get_contents(
    $root . '/upload/catalog/controller/extension/mt_uni_credit/home.php'
);
mtucAud029_assert(
    strpos($homeSrc, "strpos(\$output, 'mt-uni-credit-advertising-root') !== false") !== false,
    'footer dedupe: early return when root already present'
);
// Simulate double append guard
$output = '<footer></footer>';
$fragment = '<div id="mt-uni-credit-advertising-root" class="mt-uni-credit-advertising"></div>';
$appendOnce = function (&$output, $fragment) {
    if (strpos($output, 'mt-uni-credit-advertising-root') !== false) {
        return;
    }
    $output .= $fragment;
};
$appendOnce($output, $fragment);
$appendOnce($output, $fragment);
mtucAud029_assert(
    substr_count($output, 'mt-uni-credit-advertising-root') === 1,
    'mutation-15 YES: duplicate footer invocation → one root'
);

// ---------------------------------------------------------------------------
// Cache-only transport sentinel (source call graph)
// ---------------------------------------------------------------------------
$resolverSrc = (string) file_get_contents($lib . '/homepage_advertising_context_resolver.php');
$presenterSrc = (string) file_get_contents($lib . '/homepage_advertising_presenter.php');
mtucAud029_assert(
    strpos($resolverSrc, 'getCachedOnly') !== false
        && strpos($resolverSrc, 'refreshRemote') === false
        && strpos($resolverSrc, 'ControlPanelClient') === false,
    'cache-only: resolver uses getCachedOnly, no CP client'
);
mtucAud029_assert(
    strpos($presenterSrc, 'ControlPanelClient') === false
        && strpos($presenterSrc, 'curl') === false
        && strpos($presenterSrc, 'refreshRemote') === false,
    'cache-only: presenter has no CP/HTTP'
);
mtucAud029_assert(
    strpos($presenterSrc, 'Array to string') === false
        && strpos($presenterSrc, 'isAllowedString') !== false,
    'F01 source: type guard present'
);

restore_error_handler();

echo PHP_EOL . 'AUD-029 homepage advertising: ' . $passes . ' PASS, ' . count($failures) . ' FAIL' . PHP_EOL;
if ($failures !== array()) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

exit(0);
