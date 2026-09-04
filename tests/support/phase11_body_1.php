<?php

/**
 * Included from mtuc11_run() — inherits that function scope.
 *
 * @var string $root
 * @var string $lib
 */

// ---------------------------------------------------------------------------
// EventFakeDb (presentation event upsert / remove) — Phase 10 pattern
// ---------------------------------------------------------------------------
final class Mtuc11EventFakeDb
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
     * @return object|true
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
            if (!preg_match("/`code` = '([^']+)'/", $sql, $mCode)) {
                return (object) array('num_rows' => 0, 'row' => array(), 'rows' => array());
            }
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

            return (object) array('num_rows' => 0, 'row' => array(), 'rows' => array());
        }
        if (stripos($sql, 'UPDATE') === 0) {
            if (preg_match('/WHERE `event_id` = (\d+)/', $sql, $mId)) {
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
            }

            return (object) array('num_rows' => 0, 'row' => array(), 'rows' => array());
        }
        if (stripos($sql, 'DELETE') === 0) {
            if (preg_match('/WHERE `event_id` = (\d+)/', $sql, $mId)) {
                $id = (int) $mId[1];
                $this->rows = array_values(array_filter($this->rows, function ($row) use ($id) {
                    return (int) $row['event_id'] !== $id;
                }));
            } elseif (
                stripos($sql, 'mt_uni_credit_checkout_success') !== false
                || stripos($sql, 'mt_uni_credit_mail_order') !== false
                || stripos($sql, 'mt_uni_credit_admin_order') !== false
                || stripos($sql, 'mt_uni_credit_home') !== false
            ) {
                $keepIn = array();
                if (preg_match('/NOT IN \(([^)]+)\)/', $sql, $mIn)) {
                    if (preg_match_all("/'([^']+)'/", $mIn[1], $mCodes)) {
                        foreach ($mCodes[1] as $code) {
                            $keepIn[stripslashes($code)] = true;
                        }
                    }
                }
                $this->rows = array_values(array_filter($this->rows, function ($row) use ($keepIn) {
                    $code = (string) $row['code'];
                    $isManaged = (strpos($code, 'mt_uni_credit_checkout_success') === 0)
                        || (strpos($code, 'mt_uni_credit_mail_order') === 0)
                        || (strpos($code, 'mt_uni_credit_admin_order') === 0)
                        || (strpos($code, 'mt_uni_credit_home') === 0);
                    if (!$isManaged) {
                        return true;
                    }
                    if ($keepIn !== array()) {
                        return isset($keepIn[$code]);
                    }

                    return false;
                }));
            }

            return (object) array('num_rows' => 0, 'row' => array(), 'rows' => array());
        }

        return (object) array('num_rows' => 0, 'row' => array(), 'rows' => array());
    }
}

// ---------------------------------------------------------------------------
// 1) Event registry — exactly 10 defs + Phase 11 OC3 triggers/actions
// ---------------------------------------------------------------------------
$defs = MtUniCreditCatalogEventRegistry::definitions();
mtuc11_assert(count($defs) === 10, 'events: exactly 10 definitions');

$byCode = array();
foreach ($defs as $def) {
    $byCode[$def['code']] = $def;
}

$expected = array(
    'mt_uni_credit_admin_order_list_before' => array(
        'trigger' => 'admin/view/sale/order_list/before',
        'action' => 'extension/mt_uni_credit/admin_order/beforeOrderList',
    ),
    'mt_uni_credit_admin_order_list_after' => array(
        'trigger' => 'admin/view/sale/order_list/after',
        'action' => 'extension/mt_uni_credit/admin_order/afterOrderList',
    ),
    'mt_uni_credit_admin_order_info_after' => array(
        'trigger' => 'admin/view/sale/order_info/after',
        'action' => 'extension/mt_uni_credit/admin_order/afterOrderInfo',
    ),
    'mt_uni_credit_home_controller_before' => array(
        'trigger' => 'catalog/controller/common/home/before',
        'action' => 'extension/mt_uni_credit/home/beforeHome',
    ),
    'mt_uni_credit_home_footer_after' => array(
        'trigger' => 'catalog/view/common/footer/after',
        'action' => 'extension/mt_uni_credit/home/afterFooter',
    ),
);
foreach ($expected as $code => $pair) {
    mtuc11_assert(isset($byCode[$code]), 'events: registry has ' . $code);
    if (!isset($byCode[$code])) {
        continue;
    }
    mtuc11_assert(
        $byCode[$code]['trigger'] === $pair['trigger'],
        'events: trigger ' . $code
    );
    mtuc11_assert(
        $byCode[$code]['action'] === $pair['action'],
        'events: action ' . $code
    );
}

// ---------------------------------------------------------------------------
// 2) ensureCatalogEvents / removeCatalogEvents
// ---------------------------------------------------------------------------
$fakeDb = new Mtuc11EventFakeDb();
$repair = MtUniCreditInstaller::ensureCatalogEvents($fakeDb);
mtuc11_assert(!empty($repair['healthy']), 'events: ensureCatalogEvents healthy');
mtuc11_assert((int) $repair['inserted'] === 10, 'events: ensure inserts 10');
mtuc11_assert(count($fakeDb->rows) === 10, 'events: 10 rows after ensure');
$health = MtUniCreditCatalogEventHealth::report($fakeDb, 'oc_');
mtuc11_assert(!empty($health['ok']), 'events: health ok after ensure');

$repairAgain = MtUniCreditInstaller::ensureCatalogEvents($fakeDb);
mtuc11_assert(count($fakeDb->rows) === 10, 'events: repeated ensure no duplicates');
mtuc11_assert((int) $repairAgain['inserted'] === 0, 'events: repeated ensure inserts 0');
mtuc11_assert(!empty($repairAgain['healthy']), 'events: repeated ensure still healthy');

$fakeDb->rows[] = array(
    'event_id' => 77771,
    'code' => 'unrelated_store_event',
    'trigger' => 'catalog/model/checkout/order/addOrder/after',
    'action' => 'extension/other/hook',
    'status' => 1,
    'sort_order' => 0,
);
$removed = MtUniCreditInstaller::removeCatalogEvents($fakeDb);
mtuc11_assert(!empty($removed['removed']), 'events: removeCatalogEvents reports removed');
mtuc11_assert(count($fakeDb->rows) === 1, 'events: remove leaves unrelated only');
mtuc11_assert($fakeDb->rows[0]['code'] === 'unrelated_store_event', 'events: unrelated survives remove');

// ---------------------------------------------------------------------------
// 3) bankStatusLabelsForOrders — mixed + store_id isolation
// ---------------------------------------------------------------------------
$storeA = Phase5TestHarness::STORE_A;
$storeB = Phase5TestHarness::STORE_B;
$memoryDb = new Phase2MemoryDb();
$dbAdapter = new MtUniCreditDbAdapter($memoryDb, 'oc_');
$bankRepo = new MtUniCreditOrderBankStatusRepository($dbAdapter);
$presentationRepo = new MtUniCreditFinancingPresentationRepository($dbAdapter);

// Phase2MemoryDb orders are keyed by order_id only — seed/update store A first,
// then overwrite the shared order_id for store B (bank_status remains store-scoped).
Phase9TestHarness::seedBankOrder($memoryDb, 11001, $storeA);
Phase9TestHarness::seedBankOrder($memoryDb, 11002, $storeA);
Phase9TestHarness::seedBankOrder($memoryDb, 11003, $storeA);
$bankRepo->updateByOrderIdentifier(
    $storeA,
    '11002',
    MtUniCreditBankStatus::SENT_PROCESS1,
    MtUniCreditBankStatus::LABEL_SENT_PROCESS1
);
$bankRepo->updateByOrderIdentifier(
    $storeA,
    '11003',
    MtUniCreditBankStatus::SENT_PROCESS2,
    MtUniCreditBankStatus::LABEL_SENT_PROCESS2
);
Phase9TestHarness::seedBankOrder($memoryDb, 11002, $storeB);
$bankRepo->updateByOrderIdentifier(
    $storeB,
    '11002',
    MtUniCreditBankStatus::SEND_FAILED_SMARTUCF,
    MtUniCreditBankStatus::LABEL_SEND_FAILED_SMARTUCF
);

$orders = array(
    array('order_id' => 11001, 'store_id' => $storeA),
    array('order_id' => 11002, 'store_id' => $storeA),
    array('order_id' => 11003, 'store_id' => $storeA),
    array('order_id' => 11002, 'store_id' => $storeB),
);
$labels = $presentationRepo->bankStatusLabelsForOrders($orders, $storeA);
mtuc11_assert($labels[0] === '', 'bank labels: normal order empty');
mtuc11_assert(
    $labels[1] === MtUniCreditBankStatus::LABEL_SENT_PROCESS1,
    'bank labels: process1 from status_label'
);
mtuc11_assert(
    $labels[2] === MtUniCreditBankStatus::LABEL_SENT_PROCESS2,
    'bank labels: process2 from status_label'
);
mtuc11_assert(
    $labels[3] === MtUniCreditBankStatus::LABEL_SEND_FAILED_SMARTUCF,
    'bank labels: failed from status_label + store B isolation'
);
mtuc11_assert(
    $labels[1] !== $labels[3],
    'bank labels: same order_id differs by store_id'
);

// ---------------------------------------------------------------------------
// 4) htmlForOrder ADMIN_PANEL includes EGN; CUSTOMER never
// ---------------------------------------------------------------------------
$transport = new Phase4FakeCpHttpTransport();
Phase9TestHarness::enqueueCpCreateSuccess($transport);
$stack = Phase9TestHarness::stack($transport, null, null, $storeA, array('uni_proces' => 1));
$orderId = 11101;
Phase9TestHarness::seedBankOrder($stack['memoryDb'], $orderId, $stack['storeId']);
$snap = new MtUniCreditFinancingPresentationSnapshot(
    $orderId,
    888,
    true,
    12,
    'KOPSTD',
    100.0,
    500.0,
    45.0,
    640.0,
    5.5,
    6.1
);
$attemptRow = $stack['attempts']->findOrCreateAttempt(
    $stack['storeId'],
    $orderId,
    Phase4TestHarness::TEST_UNICID,
    hash('sha256', 'phase11-admin'),
    hash('sha256', 'sel11'),
    hash('sha256', 'fp11'),
    MtUniCreditOperationEntryPoint::PRODUCT
);
$lifecycleRepo = new MtUniCreditProcessTwoLifecycleRepository(
    new MtUniCreditDbAdapter($stack['memoryDb'], 'oc_')
);
$lifecycleRepo->persistLeasingPresentationJson((int) $attemptRow['attempt_id'], json_encode($snap->toArray()));
$cipher = new MtUniCreditProcessTwoSensitiveCipher(MtUniCreditEncryptionKeyProvider::testSecretInput());
$enc = $cipher->encrypt(new MtUniCreditProcessTwoSensitiveData('1990010112', '+35988111111'));
$lifecycleRepo->persistSensitiveEncrypted((int) $attemptRow['attempt_id'], $enc);
(new MtUniCreditOrderBankStatusRepository(new MtUniCreditDbAdapter($stack['memoryDb'], 'oc_')))
    ->updateByOrderIdentifier(
        $stack['storeId'],
        (string) $orderId,
        MtUniCreditBankStatus::SENT_PROCESS2,
        MtUniCreditBankStatus::LABEL_SENT_PROCESS2
    );

$svc = new MtUniCreditFinancingPresentationService(
    new MtUniCreditFinancingPresentationRepository(
        new MtUniCreditDbAdapter($stack['memoryDb'], 'oc_')
    )
);
$adminHtml = $svc->htmlForOrder(
    $stack['storeId'],
    $orderId,
    MtUniCreditFinancingPresentationAudience::ADMIN_PANEL
);
mtuc11_assert($adminHtml !== '', 'admin panel: htmlForOrder non-empty');
mtuc11_assert(
    strpos($adminHtml, MtUniCreditFinancingLeasingPresenter::LABEL_EGN) !== false,
    'admin panel: EGN label present when process2 sensitive available'
);
mtuc11_assert(strpos($adminHtml, '1990010112') !== false, 'admin panel: EGN value present');
mtuc11_assert(
    strpos($adminHtml, MtUniCreditFinancingLeasingPresenter::ADMIN_TITLE) !== false,
    'admin panel: ADMIN_TITLE used'
);

$customerHtml = $svc->htmlForOrder(
    $stack['storeId'],
    $orderId,
    MtUniCreditFinancingPresentationAudience::CUSTOMER
);
mtuc11_assert($customerHtml !== '', 'customer: htmlForOrder non-empty');
mtuc11_assert(
    strpos($customerHtml, MtUniCreditFinancingLeasingPresenter::LABEL_EGN) === false,
    'customer: EGN label never present'
);
mtuc11_assert(strpos($customerHtml, '1990010112') === false, 'customer: raw EGN digits absent');

// ---------------------------------------------------------------------------
// 5) HomepageAdvertisingPresenter — URLs, shop flags, route gate
// ---------------------------------------------------------------------------
$presenter = new MtUniCreditHomepageAdvertisingPresenter();
$gate = new MtUniCreditHomepageAdvertisingGate();
mtuc11_assert($gate->allowsPage('common/home'), 'homepage gate: common/home allowed');
mtuc11_assert($gate->allowsPage(''), 'homepage gate: empty route allowed');
mtuc11_assert(!$gate->allowsPage('product/product'), 'homepage gate: product route rejected');

mtuc11_assert(
    $presenter->httpUrl('https://cdn.example/pic.png') === 'https://cdn.example/pic.png',
    'advertising: https URL accepted'
);
mtuc11_assert(
    $presenter->httpUrl('http://cdn.example/pic.png') === 'http://cdn.example/pic.png',
    'advertising: http URL accepted'
);
mtuc11_assert($presenter->httpUrl('javascript:alert(1)') === '', 'advertising: javascript: rejected');
mtuc11_assert($presenter->httpUrl('data:text/html,hi') === '', 'advertising: data: rejected');

$missingFlags = $presenter->present(
    array('uni_status' => 0, 'uni_container_status' => 1, 'uni_backurl' => 'https://ok.example/'),
    false,
    'https://cdn.example/logo.png'
);
mtuc11_assert($missingFlags === null, 'advertising: missing shop flags → null');

$missingContainer = $presenter->present(
    array('uni_status' => 1, 'uni_container_status' => 0, 'uni_backurl' => 'https://ok.example/'),
    false,
    'https://cdn.example/logo.png'
);
mtuc11_assert($missingContainer === null, 'advertising: uni_container_status off → null');

$okShop = $presenter->present(
    array(
        'uni_status' => 1,
        'uni_container_status' => 'yes',
        'uni_backurl' => 'https://ok.example/offer',
        'uni_container_txt1' => 'Hello',
        'uni_container_txt2' => 'World',
        'uni_picturem' => 'https://cdn.example/m.png',
    ),
    false,
    'https://cdn.example/logo.png'
);
mtuc11_assert(is_array($okShop), 'advertising: valid shop flags produce payload');
mtuc11_assert(
    is_array($okShop) && $okShop['backurl'] === 'https://ok.example/offer',
    'advertising: backurl http(s) kept'
);
mtuc11_assert(
    is_array($okShop) && $okShop['backurl'] !== '' && $presenter->httpUrl('javascript:x') === '',
    'advertising: gate keeps only http(s) for graphic/back URLs'
);

// ---------------------------------------------------------------------------
// 6) Controllers exist + methods; no isset($this->db)
// ---------------------------------------------------------------------------
/** @var string $root */
/** @var string $lib */
$adminOrderPath = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR
    . 'controller' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'mt_uni_credit'
    . DIRECTORY_SEPARATOR . 'admin_order.php';
$homeCtrlPath = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR
    . 'controller' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'mt_uni_credit'
    . DIRECTORY_SEPARATOR . 'home.php';
mtuc11_assert(is_file($adminOrderPath), 'wiring: admin_order controller exists');
mtuc11_assert(is_file($homeCtrlPath), 'wiring: home controller exists');
$adminSrc = mtuc11_read($adminOrderPath);
$homeSrc = mtuc11_read($homeCtrlPath);
mtuc11_assert(strpos($adminSrc, 'function beforeOrderList') !== false, 'admin: beforeOrderList');
mtuc11_assert(strpos($adminSrc, 'function afterOrderList') !== false, 'admin: afterOrderList');
mtuc11_assert(strpos($adminSrc, 'function afterOrderInfo') !== false, 'admin: afterOrderInfo');
mtuc11_assert(strpos($homeSrc, 'function beforeHome') !== false, 'home: beforeHome');
mtuc11_assert(strpos($homeSrc, 'function afterFooter') !== false, 'home: afterFooter');
mtuc11_assert(strpos($adminSrc, 'isset($this->db)') === false, 'admin: no isset($this->db)');
mtuc11_assert(strpos($homeSrc, 'isset($this->db)') === false, 'home: no isset($this->db)');

// ---------------------------------------------------------------------------
// 7) PHP 8-only scan on Phase 11 new PHP files
// ---------------------------------------------------------------------------
$phase11Php = array(
    $lib . DIRECTORY_SEPARATOR . 'homepage_advertising_gate.php',
    $lib . DIRECTORY_SEPARATOR . 'homepage_advertising_presenter.php',
    $lib . DIRECTORY_SEPARATOR . 'homepage_advertising_context_resolver.php',
    $adminOrderPath,
    $homeCtrlPath,
);
$forbiddenTokens = array(
    'str_contains',
    'str_starts_with',
    '?' . '->',
    'fn' . '(',
    'public ' . 'string',
    'string|',
    ':' . ' mixed',
);
foreach ($phase11Php as $path) {
    $base = basename($path);
    mtuc11_assert(is_file($path), 'phase11 file present: ' . $base);
    $src = mtuc11_read($path);
    foreach ($forbiddenTokens as $forbidden) {
        mtuc11_assert(strpos($src, $forbidden) === false, 'PHP 7.3: ' . $base . ' free of ' . $forbidden);
    }
    mtuc11_assert(
        !preg_match('/(?<![\w$])match\s*\(/', $src),
        'PHP 7.3: ' . $base . ' free of match expression'
    );
}

// ---------------------------------------------------------------------------
// 8) Thank You / Process1 / Process2 smoke constants
// ---------------------------------------------------------------------------
mtuc11_assert(
    MtUniCreditBankStatus::LABEL_SENT_PROCESS1 !== '',
    'smoke: LABEL_SENT_PROCESS1 exists'
);
mtuc11_assert(
    MtUniCreditBankStatus::LABEL_SENT_PROCESS2 !== '',
    'smoke: LABEL_SENT_PROCESS2 exists'
);
mtuc11_assert(
    MtUniCreditBankStatus::LABEL_SEND_FAILED_SMARTUCF !== '',
    'smoke: LABEL_SEND_FAILED_SMARTUCF exists'
);
mtuc11_assert(
    MtUniCreditFinancingPresentationAudience::ADMIN_PANEL === 'admin_panel',
    'smoke: ADMIN_PANEL audience constant'
);

// ---------------------------------------------------------------------------
// 9) Assets: css / js / twig / language en+bg
// ---------------------------------------------------------------------------
$themeDir = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR
    . 'view' . DIRECTORY_SEPARATOR . 'theme' . DIRECTORY_SEPARATOR . 'default' . DIRECTORY_SEPARATOR
    . 'template' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'mt_uni_credit';
$langEn = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR
    . 'language' . DIRECTORY_SEPARATOR . 'en-gb' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR
    . 'mt_uni_credit' . DIRECTORY_SEPARATOR . 'home.php';
$langBg = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR
    . 'language' . DIRECTORY_SEPARATOR . 'bg-bg' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR
    . 'mt_uni_credit' . DIRECTORY_SEPARATOR . 'home.php';
mtuc11_assert(
    is_file($themeDir . DIRECTORY_SEPARATOR . 'mt_uni_credit_homepage_advertising.css'),
    'assets: advertising css'
);
mtuc11_assert(
    is_file($themeDir . DIRECTORY_SEPARATOR . 'mt_uni_credit_homepage_advertising.js'),
    'assets: advertising js'
);
mtuc11_assert(
    is_file($themeDir . DIRECTORY_SEPARATOR . 'homepage_advertising.twig'),
    'assets: advertising twig'
);
mtuc11_assert(is_file($langEn), 'assets: language en-gb home.php');
mtuc11_assert(is_file($langBg), 'assets: language bg-bg home.php');

$twigSrc = mtuc11_read($themeDir . DIRECTORY_SEPARATOR . 'homepage_advertising.twig');
$jsSrc = mtuc11_read($themeDir . DIRECTORY_SEPARATOR . 'mt_uni_credit_homepage_advertising.js');
$cssSrc = mtuc11_read($themeDir . DIRECTORY_SEPARATOR . 'mt_uni_credit_homepage_advertising.css');
$langBgSrc = mtuc11_read($langBg);
$langEnSrc = mtuc11_read($langEn);
$homeCtrlSrc = mtuc11_read($homeCtrlPath);

$ctaExact = 'ИНФОРМАЦИЯ ЗА ОНЛАЙН ПАЗАРУВАНЕ НА КРЕДИТ!';
mtuc11_assert(
    strpos($langBgSrc, $ctaExact) !== false,
    'advertising UX: BG CTA exact string in language file'
);
mtuc11_assert(
    strpos($langBgSrc, 'Научете повече') === false,
    'advertising UX: old BG CTA removed from language'
);
mtuc11_assert(
    strpos($twigSrc, 'text_panel_cta') !== false,
    'advertising UX: twig renders text_panel_cta'
);
mtuc11_assert(
    strpos($twigSrc, 'text_panel_cta }}!') === false
        && strpos($twigSrc, 'text_panel_cta}}!') === false,
    'advertising UX: twig does not append extra bang after CTA'
);

mtuc11_assert(
    strpos($twigSrc, 'mt-uni-credit-advertising__close') === false,
    'advertising UX: twig has no close button class'
);
mtuc11_assert(
    strpos($twigSrc, 'data-mt-uni-credit-advertising-close') === false,
    'advertising UX: twig has no close data attribute'
);
mtuc11_assert(
    strpos($twigSrc, '&times;') === false && strpos($twigSrc, '×') === false,
    'advertising UX: twig has no X close glyph'
);
mtuc11_assert(
    strpos($twigSrc, 'text_close') === false,
    'advertising UX: twig has no text_close ARIA label'
);
mtuc11_assert(
    strpos($jsSrc, 'data-mt-uni-credit-advertising-close') === false,
    'advertising UX: JS has no close-button selector'
);
mtuc11_assert(
    strpos($cssSrc, 'advertising__close') === false,
    'advertising UX: CSS has no close-button rules'
);
mtuc11_assert(
    strpos($homeCtrlSrc, 'text_close') === false,
    'advertising UX: home controller does not pass text_close'
);
mtuc11_assert(
    strpos($jsSrc, 'Escape') !== false || strpos($jsSrc, 'keyCode === 27') !== false,
    'advertising UX: Escape close behaviour preserved'
);
mtuc11_assert(
    strpos($langBgSrc, "text_close") === false && strpos($langEnSrc, "text_close") === false,
    'advertising UX: language files drop unused text_close'
);
