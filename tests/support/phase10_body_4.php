<?php
// ---------------------------------------------------------------------------
// L. Event registration health + repair / stale / missing fixtures
// ---------------------------------------------------------------------------
final class Mtuc10EventFakeDb
{
    /** @var array<int, array<string, mixed>> */
    public $rows = array();
    /** @var int */
    private $nextId = 1;

    public function escape($value)
    {
        return addslashes((string) $value);
    }

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
            }

            return (object) array('num_rows' => 0, 'row' => array(), 'rows' => array());
        }

        return (object) array('num_rows' => 0, 'row' => array(), 'rows' => array());
    }
}

$defsCount = count(MtUniCreditCatalogEventRegistry::definitions());
mtuc10_assert($defsCount === 5, 'events: exactly 5 presentation definitions');

$fakeDb = new Mtuc10EventFakeDb();
$fakeModel = (object) array('db' => $fakeDb);
MtUniCreditInstaller::ensureCatalogEvents($fakeModel);
mtuc10_assert(count($fakeDb->rows) === 5, 'events: missing table repaired to 5 rows');
$health = MtUniCreditCatalogEventHealth::report($fakeDb, 'oc_');
mtuc10_assert(!empty($health['ok']), 'events: health ok after insert');
mtuc10_assert(
    isset($health['summary']['thankyou_stash'], $health['summary']['mail_customer'], $health['summary']['mail_admin']),
    'events: health summary keys present'
);
MtUniCreditInstaller::ensureCatalogEvents($fakeModel);
mtuc10_assert(count($fakeDb->rows) === 5, 'events: repeated upsert creates no duplicates');

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
MtUniCreditInstaller::ensureCatalogEvents($fakeModel);
$mailCodes = array_filter($fakeDb->rows, function ($row) {
    return $row['code'] === 'mt_uni_credit_mail_order_add';
});
mtuc10_assert(count($mailCodes) === 1, 'events: stale duplicate mail_order_add collapsed to 1');
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
mtuc10_assert(strpos($moduleCtrl, 'repairCatalogEvents') !== false, 'wiring: Module admin self-heals events');
mtuc10_assert(strpos($moduleCtrl, 'assignEventHealth') !== false, 'wiring: Module admin shows event health');

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


