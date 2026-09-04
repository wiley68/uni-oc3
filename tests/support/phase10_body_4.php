<?php

/**
 * Included from mtuc10_run() — inherits that function scope (bodies 1–3 run first).
 *
 * @var string $root
 * @var string $lib
 * @var array<string, mixed> $stackValid
 * @var MtUniCreditFinancingPresentationService $svc
 * @var MtUniCreditFinancingLeasingPresenter $presenter
 */

// ---------------------------------------------------------------------------
// L. Event registration health + repair / stale / missing fixtures
// ---------------------------------------------------------------------------
final class Mtuc10EventFakeDb
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
            ) {
                $keepIn = array();
                if (preg_match('/NOT IN \(([^)]+)\)/', $sql, $mIn)) {
                    if (preg_match_all("/'([^']+)'/", $mIn[1], $mCodes)) {
                        foreach ($mCodes[1] as $code) {
                            $keepIn[stripslashes($code)] = true;
                        }
                    }
                }
                $this->rows = array_values(array_filter($this->rows, function ($row) use ($sql, $keepIn) {
                    $code = (string) $row['code'];
                    $isManaged = (strpos($code, 'mt_uni_credit_checkout_success') === 0)
                        || (strpos($code, 'mt_uni_credit_mail_order') === 0);
                    if (!$isManaged) {
                        return true;
                    }
                    if ($keepIn !== array()) {
                        return isset($keepIn[$code]);
                    }

                    // Full removeCatalogEvents path (no NOT IN).
                    return false;
                }));
            }

            return (object) array('num_rows' => 0, 'row' => array(), 'rows' => array());
        }

        return (object) array('num_rows' => 0, 'row' => array(), 'rows' => array());
    }
}

/**
 * Models OC3 Model/Controller Registry access: __get without __isset.
 */
final class Mtuc10Oc3MagicRegistry
{
    /** @var array<string, mixed> */
    private $data = array();

    /**
     * @param string $key
     * @return mixed
     */
    public function get($key)
    {
        return isset($this->data[$key]) ? $this->data[$key] : null;
    }

    /**
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function set($key, $value)
    {
        $this->data[$key] = $value;
    }
}

final class Mtuc10Oc3MagicHost
{
    /** @var Mtuc10Oc3MagicRegistry */
    private $registry;

    public function __construct(Mtuc10Oc3MagicRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * @param string $key
     * @return mixed
     */
    public function __get($key)
    {
        return $this->registry->get($key);
    }

    /**
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function __set($key, $value)
    {
        $this->registry->set($key, $value);
    }
}

$defsCount = count(MtUniCreditCatalogEventRegistry::definitions());
mtuc10_assert($defsCount === 5, 'events: exactly 5 presentation definitions');

$expectedCodes = array(
    'mt_uni_credit_checkout_success_order',
    'mt_uni_credit_checkout_success_view',
    'mt_uni_credit_checkout_success_view_after',
    'mt_uni_credit_mail_order_add',
    'mt_uni_credit_mail_order_alert',
);

// Prove OC3 magic isset trap (old guard would early-return).
$magicDb = new Mtuc10EventFakeDb();
$magicReg = new Mtuc10Oc3MagicRegistry();
$magicReg->set('db', $magicDb);
$magicHost = new Mtuc10Oc3MagicHost($magicReg);
mtuc10_assert(is_object($magicHost->db) && method_exists($magicHost->db, 'query'), 'oc3 magic: $host->db works');
mtuc10_assert(!isset($magicHost->db), 'oc3 magic: isset($host->db) === false without __isset');
$oldGuardWouldReturn = !isset($magicHost->db)
    || !is_object($magicHost->db)
    || !method_exists($magicHost->db, 'query');
mtuc10_assert($oldGuardWouldReturn, 'oc3 magic: old isset($model->db) guard would early-return');
$oldWouldInsert = count($magicDb->rows);
mtuc10_assert($oldWouldInsert === 0, 'oc3 magic: no rows before explicit-$db repair');

$repairMagic = MtUniCreditInstaller::ensureCatalogEvents($magicHost->db);
mtuc10_assert(!empty($repairMagic['healthy']), 'oc3 magic: ensureCatalogEvents($db) healthy');
mtuc10_assert((int) $repairMagic['inserted'] === 5, 'oc3 magic: inserted 5 rows');
mtuc10_assert(count($magicDb->rows) === 5, 'oc3 magic: 5 event rows after repair');
foreach ($expectedCodes as $code) {
    $found = null;
    foreach ($magicDb->rows as $row) {
        if ($row['code'] === $code) {
            $found = $row;
            break;
        }
    }
    mtuc10_assert(
        is_array($found) && (int) $found['status'] === 1,
        'oc3 magic: code present+enabled ' . $code
    );
}

$fakeDb = new Mtuc10EventFakeDb();
$repairEmpty = MtUniCreditInstaller::ensureCatalogEvents($fakeDb);
mtuc10_assert(count($fakeDb->rows) === 5, 'events: missing table repaired to 5 rows');
mtuc10_assert(!empty($repairEmpty['healthy']), 'events: repair result healthy after insert');
mtuc10_assert((int) $repairEmpty['inserted'] === 5, 'events: repair reports inserted=5');
$health = MtUniCreditCatalogEventHealth::report($fakeDb, 'oc_');
mtuc10_assert(!empty($health['ok']), 'events: health ok after insert');
mtuc10_assert(
    isset($health['summary']['thankyou_stash'], $health['summary']['mail_customer'], $health['summary']['mail_admin']),
    'events: health summary keys present'
);
foreach ($health['events'] as $eventRow) {
    mtuc10_assert(
        $eventRow['registered'] === 'yes'
            && $eventRow['enabled'] === 'yes'
            && (int) $eventRow['duplicate_count'] === 1
            && $eventRow['healthy'] === 'yes',
        'events: each row registered/enabled/dup=1/healthy ' . $eventRow['code']
    );
}
$repairAgain = MtUniCreditInstaller::ensureCatalogEvents($fakeDb);
mtuc10_assert(count($fakeDb->rows) === 5, 'events: repeated upsert creates no duplicates');
mtuc10_assert(!empty($repairAgain['healthy']), 'events: repeated repair still healthy');
mtuc10_assert((int) $repairAgain['inserted'] === 0, 'events: repeated repair inserts 0');

$fakeDb->rows[] = array(
    'event_id' => $fakeDb->rows[0]['event_id'] + 100,
    'code' => 'mt_uni_credit_mail_order_add',
    'trigger' => 'catalog/view/mail/order_add/before',
    'action' => 'extension/mt_uni_credit/order_mail/wrong',
    'status' => 0,
    'sort_order' => 9,
);
foreach ($fakeDb->rows as &$row) {
    if ($row['code'] === 'mt_uni_credit_checkout_success_view') {
        $row['trigger'] = 'catalog/view/common/success/wrong';
        $row['action'] = 'extension/mt_uni_credit/checkout_success/wrong';
        $row['status'] = 0;
    }
}
unset($row);
$repairStale = MtUniCreditInstaller::ensureCatalogEvents($fakeDb);
$mailCodes = array_filter($fakeDb->rows, function ($row) {
    return $row['code'] === 'mt_uni_credit_mail_order_add';
});
mtuc10_assert(count($mailCodes) === 1, 'events: stale duplicate mail_order_add collapsed to 1');
mtuc10_assert((int) $repairStale['deleted_duplicates'] >= 1, 'events: repair reports deleted_duplicates');
$viewRow = null;
foreach ($fakeDb->rows as $row) {
    if ($row['code'] === 'mt_uni_credit_checkout_success_view') {
        $viewRow = $row;
    }
}
mtuc10_assert(
    is_array($viewRow)
        && $viewRow['trigger'] === 'catalog/view/common/success/before'
        && $viewRow['action'] === 'extension/mt_uni_credit/checkout_success/beforeView'
        && (int) $viewRow['status'] === 1,
    'events: stale thankyou_before repaired to expected trigger/action/status'
);
$health2 = MtUniCreditCatalogEventHealth::report($fakeDb, 'oc_');
mtuc10_assert(!empty($health2['ok']), 'events: health ok after stale repair');
mtuc10_assert(!empty($repairStale['healthy']), 'events: repair result healthy after stale');

$fakeDb->rows[] = array(
    'event_id' => 99991,
    'code' => 'mt_uni_credit_checkout_success_legacy',
    'trigger' => 'old',
    'action' => 'old',
    'status' => 1,
    'sort_order' => 0,
);
$fakeDb->rows[] = array(
    'event_id' => 99992,
    'code' => 'unrelated_store_event',
    'trigger' => 'catalog/model/checkout/order/addOrder/after',
    'action' => 'extension/other/hook',
    'status' => 1,
    'sort_order' => 0,
);
MtUniCreditInstaller::ensureCatalogEvents($fakeDb);
$legacyLeft = array_filter($fakeDb->rows, function ($row) {
    return $row['code'] === 'mt_uni_credit_checkout_success_legacy';
});
$unrelatedLeft = array_filter($fakeDb->rows, function ($row) {
    return $row['code'] === 'unrelated_store_event';
});
mtuc10_assert(count($legacyLeft) === 0, 'events: obsolete managed legacy code removed on ensure');
mtuc10_assert(count($unrelatedLeft) === 1, 'events: unrelated OC events untouched by ensure');

$removeDb = new Mtuc10EventFakeDb();
MtUniCreditInstaller::ensureCatalogEvents($removeDb);
$removeDb->rows[] = array(
    'event_id' => 88881,
    'code' => 'unrelated_store_event',
    'trigger' => 'x',
    'action' => 'y',
    'status' => 1,
    'sort_order' => 0,
);
$removed = MtUniCreditInstaller::removeCatalogEvents($removeDb);
mtuc10_assert(!empty($removed['removed']), 'events: removeCatalogEvents reports removed');
mtuc10_assert(count($removeDb->rows) === 1, 'events: uninstall removes only managed presentation events');
mtuc10_assert($removeDb->rows[0]['code'] === 'unrelated_store_event', 'events: unrelated remains after uninstall');

/** @var mixed $invalidDb */
$invalidDb = null;
$invalid = MtUniCreditInstaller::ensureCatalogEvents($invalidDb);
mtuc10_assert($invalid['error'] === 'invalid_db', 'events: invalid db returns error');
mtuc10_assert(empty($invalid['healthy']), 'events: invalid db not healthy');

/** @var array<string, mixed> $stackValid */
/** @var MtUniCreditFinancingPresentationService $svc */
/** @var MtUniCreditFinancingLeasingPresenter $presenter */
/** @var string $root */
/** @var string $lib */
$tyRows = $svc->customerThankYouRows($stackValid['storeId'], 10130);
$tyBlock = $svc->renderCustomerThankYouHtml($tyRows);
mtuc10_assert($tyBlock !== '', 'thankyou integration: leasing HTML from CUSTOMER rows');
$tyMessage = 'Thanks.' . $tyBlock;
mtuc10_assert(strpos($tyMessage, 'mt-uni-credit-leasing-block') !== false, 'thankyou integration: marker in text_message');
mtuc10_assert(strpos($tyMessage, MtUniCreditFinancingLeasingPresenter::LABEL_MONTHS) !== false, 'thankyou integration: months');
mtuc10_assert(strpos($tyMessage, MtUniCreditFinancingLeasingPresenter::LABEL_MONTHLY) !== false, 'thankyou integration: monthly');
mtuc10_assert(strpos($tyMessage, MtUniCreditFinancingLeasingPresenter::LABEL_GLP_GPR) !== false, 'thankyou integration: GLP/GPR');
mtuc10_assert(strpos($tyMessage, MtUniCreditFinancingLeasingPresenter::LABEL_EGN) === false, 'thankyou integration: no EGN');
mtuc10_assert(strpos($tyMessage, MtUniCreditFinancingLeasingPresenter::LABEL_PHONE2) === false, 'thankyou integration: no phone2');

$rendered = '<div id="content"><p>Thanks.</p><div class="buttons"><a>Continue</a></div></div>';
$rendered = str_replace('<div class="buttons">', $tyBlock . '<div class="buttons">', $rendered);
mtuc10_assert(substr_count($rendered, 'class="mt-uni-credit-leasing-block"') === 1, 'thankyou afterView: single block');

$customerOut = '<html>native customer</html>';
$customerRows2 = $svc->filterCustomerFacingRows(
    $svc->rowsForOrder($stackValid['storeId'], 10130, MtUniCreditFinancingPresentationAudience::CUSTOMER)
);
$customerOut .= '<br/>' . $presenter->renderHtml($customerRows2);
mtuc10_assert(substr_count($customerOut, 'class="mt-uni-credit-leasing-block"') === 1, 'customer mail integration: one block');
mtuc10_assert(strpos($customerOut, 'native customer') !== false, 'customer mail integration: native preserved');
mtuc10_assert(strpos($customerOut, MtUniCreditFinancingLeasingPresenter::LABEL_EGN) === false, 'customer mail integration: no EGN');

$adminOut = "native admin alert\n";
$adminRows2 = $svc->rowsForOrder($stackValid['storeId'], 10130, MtUniCreditFinancingPresentationAudience::ADMIN_EMAIL);
$adminOut .= "\n\n" . $presenter->renderText($adminRows2);
mtuc10_assert(strpos($adminOut, 'native admin alert') !== false, 'admin mail integration: native preserved');
mtuc10_assert(substr_count($adminOut, 'УниКредит лизинг') === 1, 'admin mail integration: leasing once');
mtuc10_assert(strpos($adminOut, MtUniCreditFinancingLeasingPresenter::LABEL_EGN) !== false, 'admin mail integration: ADMIN_EMAIL may include EGN');

mtuc10_assert(
    is_file($lib . DIRECTORY_SEPARATOR . 'catalog_event_health.php'),
    'wiring: catalog_event_health present'
);
$moduleCtrl = mtuc10_read(
    $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR
        . 'controller' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'module'
        . DIRECTORY_SEPARATOR . 'mt_uni_credit.php'
);
$moduleModel = mtuc10_read(
    $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR
        . 'model' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'module'
        . DIRECTORY_SEPARATOR . 'mt_uni_credit.php'
);
$installerSrc = mtuc10_read($lib . DIRECTORY_SEPARATOR . 'installer.php');
mtuc10_assert(strpos($moduleCtrl, 'repairCatalogEvents') !== false, 'wiring: Module admin self-heals events');
mtuc10_assert(strpos($moduleCtrl, 'assignEventHealth') !== false, 'wiring: Module admin shows event health');
mtuc10_assert(strpos($moduleCtrl, 'event_health_repair_failed') !== false, 'wiring: admin distinguishes repair failure');
mtuc10_assert(strpos($moduleCtrl, 'text_event_health_repair_failed') !== false, 'wiring: failure language key wired');
mtuc10_assert(strpos($moduleModel, 'ensureCatalogEvents($this->db)') !== false, 'wiring: module passes $this->db');
mtuc10_assert(strpos($moduleModel, 'removeCatalogEvents($this->db)') !== false, 'wiring: uninstall passes $this->db');
mtuc10_assert(strpos($installerSrc, 'isset($model->db)') === false, 'wiring: installer no longer uses isset($model->db)');
mtuc10_assert(strpos($installerSrc, 'function ensureCatalogEvents($db)') !== false, 'wiring: ensureCatalogEvents($db) contract');
mtuc10_assert(strpos($installerSrc, 'function removeCatalogEvents($db)') !== false, 'wiring: removeCatalogEvents($db) contract');

$csSrc = mtuc10_read(
    $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR
        . 'controller' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'mt_uni_credit'
        . DIRECTORY_SEPARATOR . 'checkout_success.php'
);
$omSrc = mtuc10_read(
    $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR
        . 'controller' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'mt_uni_credit'
        . DIRECTORY_SEPARATOR . 'order_mail.php'
);
mtuc10_assert(strpos($csSrc, 'function before(&$route, &$data)') !== false, 'OC3 signature: thankyou stash');
mtuc10_assert(strpos($csSrc, 'function beforeView(&$route, &$data, &$code)') !== false, 'OC3 signature: thankyou beforeView');
mtuc10_assert(strpos($csSrc, 'function afterView(&$route, &$data, &$output)') !== false, 'OC3 signature: thankyou afterView');
mtuc10_assert(strpos($omSrc, 'function afterOrderAdd(&$route, &$data, &$output)') !== false, 'OC3 signature: customer mail');
mtuc10_assert(strpos($omSrc, 'function afterOrderAlert(&$route, &$data, &$output)') !== false, 'OC3 signature: admin mail');
