<?php
/** Phase 5D: memory repositories and recording ports only; no shop, DB or network. */
require_once __DIR__ . '/bootstrap.php';
define('DIR_SYSTEM', dirname(__DIR__) . '/upload/system/');
mtuc_test_define_dir_storage('mtuc-consent-remediation');
define('DB_PASSWORD', 'phase4-test-installation-db-password-secret');
define('DB_PREFIX', 'oc_');
require_once DIR_SYSTEM . 'library/mt_uni_credit/bootstrap.php';
require_once __DIR__ . '/support/phase9_harness.php';

$passes = 0;
$failures = array();
function remediationAssert($ok, $label)
{
    global $passes, $failures;
    if ($ok) { $passes++; echo 'PASS ' . $label . PHP_EOL; }
    else { $failures[] = $label; echo 'FAIL ' . $label . PHP_EOL; }
}
function remediationRefuses($context)
{
    try {
        (new MtUniCreditStorefrontConsentResolver())->validate($context, array(1, 2), $context['config_hash']);
        return false;
    } catch (InvalidArgumentException $error) { return $error->getMessage() === 'consent_unknown'; }
}

final class ConsentRemediationSmartUcfPort
{
    public $calls = array();
    public function createSession($shop, $order, $products, $calculation, $orderId)
    {
        $this->calls[] = array('order' => $order, 'products' => $products, 'calculation' => $calculation,
            'payload' => (new MtUniCreditSmartUcfPayloadBuilder())->build($shop, $order, $products, $calculation, $orderId));
        return array('session_id' => 'remediation-session', 'redirect_url' => 'https://example.test/session', 'http_code' => 200);
    }
}

function remediationP1Fixture($legacy = false)
{
    static $nextOrderId = 99500;
    $orderId = ++$nextOrderId;
    $shop = mtuc4_valid_shop_snapshot();
    $order = Phase7TestHarness::orderRow($orderId, Phase5TestHarness::STORE_A);
    $products = Phase7TestHarness::orderProducts();
    $scheme = new MtUniCreditAvailableScheme('standard', 'KOPSTD', 12, 0, null, $shop['coeff_list'][0]);
    $calculation = (new MtUniCreditCalculator())->calculateScheme($shop, 500, $scheme, 0);
    $payload = (new MtUniCreditControlPanelOrderPayloadBuilder())->build($orderId, $order, $products, $calculation, $shop);
    $fingerprint = MtUniCreditControlPanelOrderPayloadBuilder::fingerprint($payload);
    $snapshot = MtUniCreditApplicationSnapshot::fromLive($calculation, $order, $products, $shop,
        'checkout', hash('sha256', 'checkout|' . $orderId), hash('sha256', 'selection|' . $orderId), $fingerprint);
    $context = MtUniCreditConsentGate::context($shop, $scheme);
    $accepted = array('consent' => array(1), 'consent_config_hash' => $context['config_hash']);
    if (!$legacy) {
        $snapshot = MtUniCreditApplicationSnapshot::withConsent($snapshot, $shop, $calculation, $accepted, $order['store_id'], $orderId);
    }
    $memory = new Phase2MemoryDb();
    $db = new MtUniCreditDbAdapter($memory, 'oc_');
    $row = $memory->seedFinancingAttempt(array('store_id' => $order['store_id'], 'order_id' => (string) $orderId,
        'entry_point' => $snapshot['entry_point'], 'operation_key_hash' => $snapshot['operation_key_hash'],
        'selection_hash' => $snapshot['selection_hash'], 'request_fingerprint' => $fingerprint,
        'application_snapshot_json' => MtUniCreditApplicationSnapshot::encode($snapshot),
        'application_snapshot_hash' => MtUniCreditApplicationSnapshot::hash($snapshot),
        'control_panel_order_id' => 123, 'state' => MtUniCreditFinancingAttemptState::CP_CREATED,
        'cp_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
    $port = new ConsentRemediationSmartUcfPort();
    $coordinator = new MtUniCreditSmartUcfSessionCoordinator(new MtUniCreditSmartUcfLifecycleRepository($db), $port);
    return array('shop' => $shop, 'order' => $order, 'products' => $products, 'calculation' => $calculation,
        'snapshot' => $snapshot, 'row' => $row, 'db' => $db, 'port' => $port, 'coordinator' => $coordinator, 'accepted' => $accepted);
}
function remediationRunP1(array $f, $calculation = null, $order = null, $products = null)
{
    return $f['coordinator']->run($f['row']['attempt_id'], $f['shop'], $order === null ? $f['order'] : $order,
        $products === null ? $f['products'] : $products, $calculation === null ? $f['calculation'] : $calculation,
        $f['order']['order_id'], 123);
}

if (!in_array('--f2', $argv, true)) {
    $f = remediationP1Fixture();
    $result = remediationRunP1($f);
    remediationAssert($result->isCreated() && count($f['port']->calls) === 1, 'F1 matching A/12 reaches session creation');
    remediationRunP1($f);
    remediationAssert(count($f['port']->calls) === 1, 'F1 matching replay creates no second session');

    foreach (array('kop_and_term', 'term', 'type', 'price', 'first', 'financedAmount', 'monthlyInstallment', 'totalPayable', 'glp', 'gpr', 'nonfinite') as $change) {
        $f = remediationP1Fixture();
        $before = (new MtUniCreditFinancingAttemptRepository($f['db']))->findById($f['row']['attempt_id']);
        $bad = clone $f['calculation']; $bad->scheme = clone $bad->scheme; $bad->firstInstallment = clone $bad->firstInstallment;
        if ($change === 'kop_and_term') { $bad->scheme->kopCode = 'B'; $bad->scheme->months = 24; }
        elseif ($change === 'term') { $bad->scheme->months = 24; }
        elseif ($change === 'type') { $bad->scheme->type = 'promo'; }
        elseif ($change === 'first') { $bad->firstInstallment->amount += 1; }
        elseif ($change === 'nonfinite') { $bad->monthlyInstallment = INF; }
        else { $bad->$change += 1; }
        $result = remediationRunP1($f, $bad);
        remediationAssert(!$result->isCreated() && count($f['port']->calls) === 0
            && ($change === 'price' || $result->errorClass() === 'application_drift'), 'F1 refuses ' . $change . ' before session create');
        $after = (new MtUniCreditFinancingAttemptRepository($f['db']))->findById($f['row']['attempt_id']);
        remediationAssert($after === $before && !$result->bankStatusTransitioned(), 'F1 ' . $change . ' preserves identity/evidence/lifecycle without banking failure');
    }

    $f = remediationP1Fixture();
    $order = $f['order']; $order['firstname'] = 'Changed'; $order['email'] = 'changed@example.test'; $order['payment_address_1'] = 'Changed';
    remediationRunP1($f, null, $order, array());
    $call = isset($f['port']->calls[0]) ? $f['port']->calls[0] : null;
    $frozen = MtUniCreditApplicationSnapshot::resolveHandoffInputs($f['snapshot'], $order);
    remediationAssert($call !== null && $call['order'] === $frozen['order'] && $call['products'] === $frozen['order_products'],
        'F1 direct P1 reconstructs customer/products using established frozen handoff');

    foreach (array('missing', 'damaged') as $damage) {
        $f = remediationP1Fixture();
        $bad = $f['snapshot'];
        if ($damage === 'missing') { unset($bad['consent_evidence']); }
        else { $bad['consent_evidence']['accepted_ids'] = array(); }
        $f['db']->query("UPDATE `oc_mt_uni_credit_financing_attempt` SET `application_snapshot_json` = '" . $f['db']->escape(MtUniCreditApplicationSnapshot::encode($bad))
            . "', `application_snapshot_hash` = '" . MtUniCreditApplicationSnapshot::hash($bad) . "' WHERE `attempt_id` = " . $f['row']['attempt_id']);
        $result = remediationRunP1($f);
        remediationAssert($result->errorClass() === 'consent_evidence' && count($f['port']->calls) === 0, 'F1 ' . $damage . ' rehashed evidence refuses');
    }

    $f = remediationP1Fixture(true);
    $result = remediationRunP1($f);
    remediationAssert($result->errorClass() === 'consent_evidence' && count($f['port']->calls) === 0, 'F1 legacy v1 without current acceptance refuses');
    MtUniCreditConsentGate::authorizeLegacy($f['row'], $f['shop'], $f['accepted']);
    $result = remediationRunP1($f);
    $after = (new MtUniCreditFinancingAttemptRepository($f['db']))->findById($f['row']['attempt_id']);
    remediationAssert($result->isCreated() && count($f['port']->calls) === 1, 'F1 request-authorized matching legacy v1 continues');
    remediationAssert($after['application_snapshot_json'] === $f['row']['application_snapshot_json'] && $after['application_snapshot_hash'] === $f['row']['application_snapshot_hash'], 'F1 legacy continuation keeps historical bytes/hash');
    $f = remediationP1Fixture(true);
    MtUniCreditConsentGate::authorizeLegacy($f['row'], $f['shop'], $f['accepted']);
    $bad = clone $f['calculation']; $bad->scheme = clone $bad->scheme; $bad->scheme->months = 24;
    remediationRunP1($f, $bad);
    remediationAssert(count($f['port']->calls) === 0, 'F1 legacy request authority does not authorize different financial terms');

    $f = remediationP1Fixture();
    $transport = new Phase4FakeCpHttpTransport();
    $payloads = Phase7TestHarness::loginAndOrderSuccessPayloads();
    $transport->enqueueJson(200, $payloads['login']);
    $stack = Phase9TestHarness::stack($transport);
    $stack['memoryDb']->seedFinancingAttempt($f['row']);
    foreach (array(1, 2) as $retry) {
        $result = $stack['lifecycle']->submitOrRecover($f['row'], $f['order'], $f['products'], $f['calculation'], $f['shop'], MtUniCreditLockOwnerTokenGenerator::generate());
        remediationAssert($result->success, 'F1 CP_CREATED matching continuation/replay ' . $retry);
    }
    $after = $stack['attempts']->findById($f['row']['attempt_id']);
    remediationAssert(Phase7TestHarness::countOrderPosts($transport) === 0 && Phase9TestHarness::smartUcfCallCount($stack['smartUcfProbe']) === 1 && count($stack['process2Mailer']->sent) === 0,
        'F1 CP_CREATED repeats no CP create/session/mail');
    remediationAssert($after['order_id'] === $f['row']['order_id'] && $after['operation_key_hash'] === $f['row']['operation_key_hash'] && $after['application_snapshot_hash'] === $f['row']['application_snapshot_hash'],
        'F1 CP_CREATED preserves order/attempt authority');

    $f = remediationP1Fixture();
    $f['db']->query("UPDATE `oc_mt_uni_credit_financing_attempt` SET `smartucf_state` = 'submitting', `smartucf_claimed_at` = '2000-01-01 00:00:00' WHERE `attempt_id` = " . $f['row']['attempt_id']);
    $before = (new MtUniCreditFinancingAttemptRepository($f['db']))->findById($f['row']['attempt_id']);
    $bad = clone $f['calculation']; $bad->scheme = clone $bad->scheme; $bad->scheme->months = 24;
    remediationRunP1($f, $bad);
    remediationAssert((new MtUniCreditFinancingAttemptRepository($f['db']))->findById($f['row']['attempt_id']) === $before && count($f['port']->calls) === 0,
        'F1 incompatible direct input refuses before stale-state normalization');
}

if (!in_array('--f1', $argv, true)) {
    $resolver = new MtUniCreditStorefrontConsentResolver();
    $consents = array(array('id' => 1, 'name' => 'Zero', 'mandatory' => 1, 'interest_scope' => 'zero_interest'),
        array('id' => 2, 'name' => 'Nonzero', 'mandatory' => 1, 'interest_scope' => 'nonzero_interest'));
    $zero = array('onlineProductCode' => 'A', 'installmentCount' => 12, 'coeff' => 0.1, 'interestPercent' => 0.000010000);
    $nonzero = $zero; $nonzero['interestPercent'] = 0.000010001;
    foreach (array(array($zero, $nonzero), array($nonzero, $zero)) as $index => $rows) {
        $selected = (new MtUniCreditCoefficientResolver(new MtUniCreditMonthResolver()))->find($rows, 'A', 12);
        $context = $resolver->context(array('consents' => $consents, 'coeff_list' => $rows), 'A', 12, $selected);
        remediationAssert($context['interest_class'] === 'unknown' && remediationRefuses($context), 'F2 original threshold conflict refuses order ' . $index);
    }
    foreach (array(array($zero, $zero), array($zero, array_merge($zero, array('coeff' => '0.100', 'interestPercent' => '1e-5'))),
        array($nonzero, array_merge($nonzero, array('interestPercent' => '0.000010002')))) as $index => $rows) {
        foreach (array($rows, array_reverse($rows)) as $ordered) {
            $context = $resolver->context(array('consents' => $consents, 'coeff_list' => $ordered), 'A', 12, $ordered[0]);
            $ids = $index === 2 ? array(2) : array(1);
            remediationAssert($context['valid'] && $context['mandatory_ids'] === $ids, 'F2 same-class duplicate representations preserve IDs ' . $index);
        }
    }
    foreach (array('coeff', 'interestPercent') as $field) {
        foreach (array(null, false, 'invalid', INF, NAN) as $invalid) {
            $bad = $zero; $bad[$field] = $invalid;
            foreach (array(array($zero, $bad), array($bad, $zero)) as $rows) {
                $context = $resolver->context(array('consents' => $consents, 'coeff_list' => $rows), 'A', 12, $zero);
                remediationAssert($context['interest_class'] === 'unknown' && remediationRefuses($context), 'F2 invalid matching ' . $field . ' refuses regardless of order');
            }
        }
    }
    foreach (array(0, -0.1) as $invalid) {
        $bad = $zero; $bad['coeff'] = $invalid;
        $context = $resolver->context(array('consents' => $consents, 'coeff_list' => array($zero, $bad)), 'A', 12, $zero);
        remediationAssert(remediationRefuses($context), 'F2 matching nonpositive coefficient refuses');
    }
    $otherKop = $nonzero; $otherKop['onlineProductCode'] = 'B';
    $otherTerm = $nonzero; $otherTerm['installmentCount'] = 24;
    $context = $resolver->context(array('consents' => $consents, 'coeff_list' => array($otherKop, $otherTerm, $zero)), 'A', 12, $zero);
    remediationAssert($context['valid'] && $context['mandatory_ids'] === array(1), 'F2 distinct KOP/months do not conflict');
    foreach (array(-0.00001, 0.00001, -0.000010001, 0.000010001) as $interest) {
        $entry = $zero; $entry['interestPercent'] = $interest;
        $context = $resolver->context(array('consents' => $consents, 'coeff_list' => array($entry)), 'A', 12, $entry);
        remediationAssert($context['mandatory_ids'] === (abs($interest) <= 0.00001 ? array(1) : array(2)), 'F2 raw signed epsilon boundary');
    }
    $all = array(array('id' => 1, 'name' => 'All', 'mandatory' => 1));
    $context = $resolver->context(array('consents' => $all, 'coeff_list' => array($zero, $nonzero)), 'A', 12, $zero);
    remediationAssert($resolver->validate($context, array(1), $context['config_hash']) === array(1), 'F2 all-only applicability adds no ambiguity gate');
    $context = $resolver->context(array('consents' => $all, 'coeff_list' => array($zero)), 'A', 12, array());
    remediationAssert($context['interest_class'] === 'unknown' && $resolver->validate($context, array(1), $context['config_hash']) === array(1), 'F2 all-only unknown remains acceptable');
    $f = remediationP1Fixture();
    $changedShop = $f['shop']; $changedShop['coeff_list'] = array($zero, $nonzero); $changedShop['consents'] = $consents;
    remediationAssert(MtUniCreditConsentGate::permits($f['row'], $changedShop), 'F2 frozen valid v2 evidence ignores later live coefficient ambiguity');

    $zero['onlineProductCode'] = $nonzero['onlineProductCode'] = 'KOPSTD';
    foreach (array('checkout', 'product', 'cart') as $entryPoint) {
        $transport = new Phase4FakeCpHttpTransport();
        $stack = Phase9TestHarness::stack($transport, null, null, Phase5TestHarness::STORE_A,
            array('consents' => $consents, 'coeff_list' => array($zero, $nonzero)));
        $orderId = 99700;
        if ($entryPoint === 'checkout') {
            Phase9TestHarness::seedBankOrder($stack['memoryDb'], $orderId, $stack['storeId']);
            $input = Phase9TestHarness::submitInput($orderId, $stack['storeId']);
            $service = $stack['submission'];
        } else {
            $input = $entryPoint === 'product' ? Phase9TestHarness::productStorefrontInput($stack, $orderId) : Phase9TestHarness::cartStorefrontInput($stack, $orderId);
            $service = $stack['storefront'];
        }
        $context = $resolver->context(array('consents' => $consents), 'KOPSTD', 12, $zero);
        $input['consent'] = array(1, 2); $input['consent_config_hash'] = $context['config_hash'];
        $result = $service->submit($input);
        remediationAssert(empty($result['success']) && $result['error'] === 'consent_unknown', 'F2 ' . $entryPoint . ' service refuses conflicting raw applicability');
        remediationAssert($stack['attempts']->findByStoreOrder($stack['storeId'], $orderId) === null
            && Phase7TestHarness::countOrderPosts($transport) === 0 && Phase9TestHarness::smartUcfCallCount($stack['smartUcfProbe']) === 0
            && count($stack['process2Mailer']->sent) === 0, 'F2 ' . $entryPoint . ' ambiguity creates no attempt/external effects');
    }
    $f = remediationP1Fixture(true);
    $shop = $f['shop']; $shop['consents'] = $consents; $shop['coeff_list'] = array($zero, $nonzero);
    try {
        MtUniCreditConsentGate::authorizeLegacy($f['row'], $shop, array('consent' => array(1, 2), 'consent_config_hash' => $context['config_hash']));
        remediationAssert(false, 'F2 legacy acceptance refuses ambiguous raw entries');
    } catch (InvalidArgumentException $error) {
        remediationAssert($error->getMessage() === 'consent_unknown', 'F2 legacy acceptance refuses ambiguous raw entries');
    }
}
echo 'CONSENT REMEDIATION: ' . $passes . ' PASS, ' . count($failures) . ' FAIL' . PHP_EOL;
exit($failures === array() ? 0 : 1);
