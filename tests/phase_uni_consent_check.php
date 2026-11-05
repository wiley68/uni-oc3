<?php
/** UNI-CONSENT-001: isolated memory DB, fake HTTP/SmartUCF/mail, repository-owned temp storage. */
require_once __DIR__ . '/bootstrap.php';
define('DIR_SYSTEM', dirname(__DIR__) . '/upload/system/');
mtuc_test_define_dir_storage('mtuc-consent');
define('DB_PASSWORD', 'phase4-test-installation-db-password-secret');
define('DB_PREFIX', 'oc_');
require_once DIR_SYSTEM . 'library/mt_uni_credit/bootstrap.php';
foreach (array('phase2_memory_db', 'phase4_harness', 'phase5_harness', 'phase6_harness', 'phase7_harness', 'phase9_harness', 'recording_process_two_mailer') as $file) {
    require_once __DIR__ . '/support/' . $file . '.php';
}
$passes = 0;
function consentAssert($ok, $label) {
    global $passes;
    if (!$ok) { throw new RuntimeException('FAIL ' . $label); }
    $passes++; echo 'PASS ' . $label . PHP_EOL;
}
function consentReject($call, $label) {
    try { $call(); } catch (Throwable $error) { consentAssert(true, $label); return; }
    consentAssert(false, $label);
}
$r = new MtUniCreditStorefrontConsentResolver();
$descriptors = array(
    array('id' => 1, 'name' => 'All', 'url' => 'https://example.test/all', 'mandatory' => 1, 'interest_scope' => 'all'),
    array('id' => 2, 'name' => 'Zero', 'mandatory' => 1, 'interest_scope' => 'zero_interest'),
    array('id' => 3, 'name' => 'Nonzero information', 'mandatory' => 0, 'interest_scope' => 'nonzero_interest'),
);
foreach (array(null, false, 0, array(), 'invalid', 'ALL', '') as $bad) {
    $c = $descriptors[0]; $c['interest_scope'] = $bad;
    consentAssert($r->normalize(array('consents' => array($c)))[0]['interest_scope'] === 'all', 'scope fallback ' . gettype($bad));
}
consentAssert($r->normalize(array()) === array() && $r->normalize(array('consents' => '[]')) === array(), 'empty/missing canonical list');
foreach (array(null, false, '{}', 'null', 'bad', array(array('name' => 'no ID')), array(array('id' => 1, 'name' => 'a'), array('id' => 1, 'name' => 'b'))) as $bad) {
    consentReject(function () use ($r, $bad) { $r->normalize(array('consents' => $bad)); }, 'malformed consent configuration');
}
foreach (array(true, false, -1, 0, 1.2, '01', '+1', '1.0', '1abc', ' 1', array(1)) as $id) {
    consentReject(function () use ($r, $id) { $r->acceptedIds(array($id)); }, 'strict accepted ID ' . gettype($id));
}
consentReject(function () use ($r) { $r->acceptedIds('1,2'); }, 'no CSV coercion');
consentAssert($r->acceptedIds(array('2', 1, '2')) === array(1, 2), 'sorted unique IDs');
foreach (array(-0.00001, 0, 0.0000099, 0.00001, '0') as $glp) {
    consentAssert(MtUniCreditStorefrontConsentResolver::classify(array('coeff' => 0.1, 'interestPercent' => $glp)) === 'zero', 'epsilon zero');
}
foreach (array(-0.0000101, 0.0000101, 0.00002, 5.5) as $glp) {
    consentAssert(MtUniCreditStorefrontConsentResolver::classify(array('coeff' => '0.1', 'interestPercent' => $glp)) === 'nonzero', 'raw nonzero despite rounded display');
}
foreach (array(null, true, array(), '', 'not numeric', INF, NAN) as $bad) {
    consentAssert(MtUniCreditStorefrontConsentResolver::classify(array('coeff' => 0.1, 'interestPercent' => $bad)) === 'unknown', 'invalid raw interest');
    consentAssert(MtUniCreditStorefrontConsentResolver::classify(array('coeff' => $bad, 'interestPercent' => 0)) === 'unknown', 'invalid coefficient');
}
foreach (array(0, -0.1) as $bad) { consentAssert(MtUniCreditStorefrontConsentResolver::classify(array('coeff' => $bad, 'interestPercent' => 0)) === 'unknown', 'positive coefficient required'); }
$c = $r->context(array('consents' => $descriptors), 'A', 12, array('coeff' => 0.1, 'interestPercent' => 0));
consentAssert($c['applicable_ids'] === array(1, 2) && $c['mandatory_ids'] === array(1, 2), 'zero applicability');
consentAssert($r->validate($c, array(1, 2, 3), $c['config_hash']) === array(1, 2), 'informational IDs never evidence of express acceptance');
consentReject(function () use ($r, $c) { $r->validate($c, array(1), $c['config_hash']); }, 'mandatory omission');
consentReject(function () use ($r, $c) { $r->validate($c, array(1, 2), 'stale'); }, 'stale HTML hash');
$unknown = $r->context(array('consents' => array($descriptors[2])), 'A', 12, array());
consentReject(function () use ($r, $unknown) { $r->validate($unknown, array()); }, 'informational scoped unknown blocks');
consentAssert($r->isSatisfied(array(), array()), 'empty does not synthesize legacy acceptance');
consentAssert($r->legacyConsentAccepted('1'), 'explicit-only compatibility utility retained');
$all = $r->context(array('consents' => array($descriptors[0])), 'A', 12, array());
consentAssert($r->validate($all, array(1), $all['config_hash']) === array(1), 'unknown all-only adds no classification gate');

// Service boundaries, frozen replay and immutable evidence: all ports are recording fakes.
foreach (array('checkout', 'product', 'cart') as $entry) {
    $transport = new Phase4FakeCpHttpTransport(); Phase9TestHarness::enqueueCpCreateSuccess($transport);
    $stack = Phase9TestHarness::stack($transport);
    $orderId = $entry === 'checkout' ? 98001 : ($entry === 'product' ? 98002 : 98003);
    if ($entry === 'checkout') {
        Phase9TestHarness::seedBankOrder($stack['memoryDb'], $orderId, $stack['storeId']);
        $input = Phase9TestHarness::submitInput($orderId, $stack['storeId']); $service = $stack['submission'];
    } else {
        $input = $entry === 'product' ? Phase9TestHarness::productStorefrontInput($stack, $orderId) : Phase9TestHarness::cartStorefrontInput($stack, $orderId);
        $service = $stack['storefront'];
    }
    $bad = $input; $bad['consent'] = array();
    $refused = $service->submit($bad);
    consentAssert(empty($refused['success']) && $refused['error'] === 'consent_required', $entry . ' service rejects missing acceptance');
    consentAssert(Phase7TestHarness::countOrderPosts($transport) === 0 && Phase9TestHarness::smartUcfCallCount($stack['smartUcfProbe']) === 0, $entry . ' refusal has zero external creates');
    consentAssert($stack['attempts']->findByStoreOrder($stack['storeId'], $orderId) === null, $entry . ' refusal creates no attempt');
    if ($entry !== 'checkout') {
        $identity = $entry === 'product'
            ? MtUniCreditStorefrontOperationIdentity::productHash($stack['storeId'], $input['product_line']->productId, $input['product_line']->options, $input['product_line']->quantity, 'EUR')
            : MtUniCreditStorefrontOperationIdentity::cartHash($stack['storeId'], 'EUR', $input['cart_fingerprint']);
        $operation = MtUniCreditStorefrontApplicationToken::bindKey($identity, $input['application_token']);
        consentAssert((new MtUniCreditOperationOrderClaimRepository($stack['db']))->find($stack['storeId'], $entry, $operation) === null, $entry . ' refused preflight creates no durable claim');
    }
    $result = $service->submit($input);
    consentAssert(!empty($result['success']), $entry . ' accepted submission succeeds');
    $row = $stack['attempts']->findByStoreOrder($stack['storeId'], $orderId);
    $snapshot = MtUniCreditConsentGate::snapshot($row);
    consentAssert($snapshot['version'] === 2 && $snapshot['consent_evidence']['accepted_ids'] === array(1), $entry . ' durable v2 evidence');
    $hash = $row['application_snapshot_hash'];
    foreach (array('accepted_at', 'accepted_ids', 'binding', 'coefficient', 'interest_percent', 'context') as $field) {
        $tampered = $snapshot; $tampered['consent_evidence'][$field] = null;
        $tamperedRow = $row; $tamperedRow['application_snapshot_json'] = MtUniCreditApplicationSnapshot::encode($tampered);
        consentAssert(!MtUniCreditConsentGate::permits($tamperedRow), 'full hash covers evidence ' . $field);
    }
    $shopChanged = mtuc4_valid_shop_snapshot(array('consents' => array(array('id' => 99, 'name' => 'New policy', 'mandatory' => 1, 'interest_scope' => 'zero_interest'))));
    $clock = new MtUniCreditPersistenceClock(function () { return Phase9TestHarness::NOW; });
    (new MtUniCreditShopCacheRepository($stack['db'], $clock))->replaceValidated($stack['storeId'], Phase4TestHarness::TEST_UNICID, $shopChanged);
    $input['scheme_key'] = 'standard|POST-B|24'; $input['consent'] = array(); $input['consent_config_hash'] = '';
    if (isset($result['session'])) { $input['session'] = $result['session']; }
    $replay = $service->submit($input);
    consentAssert(!empty($replay['success']), $entry . ' frozen A wins over posted B and newer policy');
    consentAssert($stack['attempts']->findByStoreOrder($stack['storeId'], $orderId)['application_snapshot_hash'] === $hash, $entry . ' replay never rewrites evidence');
    consentAssert(Phase7TestHarness::countOrderPosts($transport) === 1 && Phase9TestHarness::smartUcfCallCount($stack['smartUcfProbe']) === 1, $entry . ' replay creates no duplicate external operation');
    // Full integrity and semantic validation are separate: even a rehashed damaged v2 is rejected.
    $damaged = $snapshot; unset($damaged['consent_evidence']);
    $badRow = $row; $badRow['application_snapshot_json'] = MtUniCreditApplicationSnapshot::encode($damaged); $badRow['application_snapshot_hash'] = MtUniCreditApplicationSnapshot::hash($damaged);
    consentAssert(!MtUniCreditConsentGate::permits($badRow, $shopChanged), $entry . ' damaged v2 cannot become legacy');
    $damaged = $snapshot; $damaged['consent_evidence']['accepted_ids'] = array();
    $badRow['application_snapshot_json'] = MtUniCreditApplicationSnapshot::encode($damaged); $badRow['application_snapshot_hash'] = MtUniCreditApplicationSnapshot::hash($damaged);
    consentAssert(!MtUniCreditConsentGate::permits($badRow, $shopChanged), $entry . ' rehashed inconsistent evidence refuses');
    // Actual lifecycle/coordinator guard with corrupt stored record, no new effects or failure states.
    $stack['db']->query("UPDATE `oc_mt_uni_credit_financing_attempt` SET `application_snapshot_json` = '" . $stack['db']->escape($badRow['application_snapshot_json']) . "', `application_snapshot_hash` = '" . $badRow['application_snapshot_hash'] . "' WHERE `attempt_id` = " . $row['attempt_id']);
    $calc = MtUniCreditApplicationSnapshot::toCalculationResult($snapshot);
    $blocked = $stack['lifecycle']->submitOrRecover($row, Phase7TestHarness::orderRow($orderId, $stack['storeId']), Phase7TestHarness::orderProducts(), $calc, $shopChanged, MtUniCreditLockOwnerTokenGenerator::generate());
    consentAssert(!$blocked->success && $blocked->errorClass === 'consent_evidence', $entry . ' CP_CREATED shortcut guard');
    $p1 = $stack['process1']->run($row['attempt_id'], $shopChanged, Phase7TestHarness::orderRow($orderId, $stack['storeId']), Phase7TestHarness::orderProducts(), $calc, $orderId, $row['control_panel_order_id']);
    consentAssert($p1->errorClass() === 'consent_evidence', $entry . ' direct P1 gate');
    $p2 = $stack['process2']->run($row['attempt_id'], $stack['storeId'], $orderId, $shopChanged, array('native_order' => Phase7TestHarness::orderRow($orderId, $stack['storeId'])));
    consentAssert(empty($p2['success']) && $p2['error'] === 'consent_evidence', $entry . ' direct P2 gate');
    consentAssert(!$stack['process1']->recoverMissingProcess1TargetAfterCreatedSmartUcf($row['attempt_id'], $stack['storeId'], $orderId, Phase4TestHarness::TEST_UNICID), $entry . ' direct recovery gate');
    consentAssert($stack['attempts']->findByStoreOrder($stack['storeId'], $orderId)['state'] === $row['state'], $entry . ' refusal preserves bank lifecycle state');
    // Legacy evidence remains byte-identical. Current applicability does not invent historical acceptance.
    $legacy = MtUniCreditApplicationSnapshot::intentProjection($snapshot);
    $legacyJson = MtUniCreditApplicationSnapshot::encode($legacy); $legacyHash = hash('sha256', $legacyJson);
    $legacyRow = $row; $legacyRow['application_snapshot_json'] = $legacyJson; $legacyRow['application_snapshot_hash'] = $legacyHash;
    consentAssert(!MtUniCreditConsentGate::permits($legacyRow, mtuc4_valid_shop_snapshot()), 'v1 mandatory needs current-request acceptance');
    MtUniCreditConsentGate::authorizeLegacy($legacyRow, mtuc4_valid_shop_snapshot(), array('consent' => array(1), 'consent_config_hash' => $r->context(mtuc4_valid_shop_snapshot(), '', 0, array())['config_hash']));
    consentAssert(MtUniCreditConsentGate::permits($legacyRow, mtuc4_valid_shop_snapshot()) && $legacyRow['application_snapshot_json'] === $legacyJson && $legacyRow['application_snapshot_hash'] === $legacyHash, 'v1 request-only authorization never rewrites bytes/hash');
    $unknownShop = array('consents' => array($descriptors[2]));
    consentReject(function () use ($legacyRow, $unknownShop) { MtUniCreditConsentGate::authorizeLegacy($legacyRow, $unknownShop, array()); }, 'v1 unknown scoped blocks without historical fabrication');
}

// v1 byte oracle captured from the approved baseline implementation (not regenerated by this test).
$oracleShop = mtuc4_valid_shop_snapshot();
$oracleScheme = new MtUniCreditAvailableScheme('standard', 'KOPSTD', 12, 0, null, $oracleShop['coeff_list'][0]);
$oracleCalc = (new MtUniCreditCalculator())->calculateScheme($oracleShop, 500, $oracleScheme, 0);
$v1Live = MtUniCreditApplicationSnapshot::fromLive($oracleCalc, Phase7TestHarness::orderRow(97999, Phase5TestHarness::STORE_A), Phase7TestHarness::orderProducts(), $oracleShop, 'checkout', hash('sha256', 'v1-op'), hash('sha256', 'v1-selection'), hash('sha256', 'v1-fingerprint'));
$oracleBytes = file_get_contents(__DIR__ . '/fixtures/consent_v1_snapshot.json');
consentAssert(MtUniCreditApplicationSnapshot::encode($v1Live) === $oracleBytes && MtUniCreditApplicationSnapshot::hash($v1Live) === hash('sha256', $oracleBytes), 'v1 serialization and hash exactly match approved baseline oracle');

// Evidence is entirely local: both external builders remain byte-equivalent for v1/v2 handoff.
$v2Oracle = MtUniCreditApplicationSnapshot::withConsent($v1Live, $oracleShop, $oracleCalc,
    array('consent' => array(1), 'consent_config_hash' => $r->context($oracleShop, '', 0, array())['config_hash']), Phase5TestHarness::STORE_A, 97999);
$externalOrder = Phase7TestHarness::orderRow(97999, Phase5TestHarness::STORE_A);
$cpBuilder = new MtUniCreditControlPanelOrderPayloadBuilder();
$smartBuilder = new MtUniCreditSmartUcfPayloadBuilder();
$calcV1 = MtUniCreditApplicationSnapshot::toCalculationResult($v1Live);
$calcV2 = MtUniCreditApplicationSnapshot::toCalculationResult($v2Oracle);
consentAssert($cpBuilder->build(97999, $externalOrder, Phase7TestHarness::orderProducts(), $calcV1, $oracleShop)
    === $cpBuilder->build(97999, $externalOrder, Phase7TestHarness::orderProducts(), $calcV2, $oracleShop), 'CP payload unchanged by v2 consent evidence');
consentAssert($smartBuilder->build($oracleShop, $externalOrder, Phase7TestHarness::orderProducts(), $calcV1, 97999)
    === $smartBuilder->build($oracleShop, $externalOrder, Phase7TestHarness::orderProducts(), $calcV2, 97999), 'SmartUCF payload unchanged by v2 consent evidence');

// Crash after the order claim is bound but before atomic attempt insertion.
$crashTransport = new Phase4FakeCpHttpTransport(); Phase9TestHarness::enqueueCpCreateSuccess($crashTransport);
$crashStack = Phase9TestHarness::stack($crashTransport);
$crashInput = Phase9TestHarness::productStorefrontInput($crashStack, 98991);
$addPort = $crashInput['add_order']; $adds = 0;
$crashInput['add_order'] = function ($draft) use ($addPort, &$adds) { $adds++; return call_user_func($addPort, $draft); };
$crashStack['memoryDb']->beforeFinancingAttemptInsert = function () { throw new RuntimeException('isolated interrupted atomic insert'); };
consentReject(function () use ($crashStack, $crashInput) { $crashStack['storefront']->submit($crashInput); }, 'simulated crash before attempt INSERT');
consentAssert($adds === 1 && $crashStack['attempts']->findByStoreOrder($crashStack['storeId'], 98991) === null && Phase7TestHarness::countOrderPosts($crashTransport) === 0, 'crash leaves one bound order and no evidence-free attempt/CP create');
$crashStack['memoryDb']->beforeFinancingAttemptInsert = null;
$recovered = $crashStack['storefront']->submit($crashInput);
consentAssert(!empty($recovered['success']) && $adds === 1 && (int) $recovered['order_id'] === 98991, 'crash recovery reuses durable claim without duplicate OC order');

// Atomic insert and a competing connection winning between initial SELECT and INSERT.
class ConsentRecordingDb {
    public $memory; public $sql = array(); public $winner = null;
    public function __construct() { $this->memory = new Phase2MemoryDb(); }
    public function query($sql) {
        $this->sql[] = $sql;
        if ($this->winner !== null && strpos($sql, 'INSERT INTO `oc_mt_uni_credit_financing_attempt`') === 0) {
            $winner = $this->winner; $this->winner = null; $this->memory->seedFinancingAttempt($winner);
        }
        return $this->memory->query($sql);
    }
    public function escape($s) { return $this->memory->escape($s); }
    public function countAffected() { return $this->memory->countAffected(); }
    public function getLastId() { return $this->memory->getLastId(); }
}
$recording = new ConsentRecordingDb(); $repo = new MtUniCreditFinancingAttemptRepository(new MtUniCreditDbAdapter($recording, 'oc_'));
// Reuse a verified full snapshot as an independent insertion fixture.
$snapshot['request_fingerprint'] = '';
$snapshot['operation_key_hash'] = hash('sha256', 'atomic'); $snapshot['selection_hash'] = hash('sha256', 'selection');
$snapshot['consent_evidence']['binding'] = MtUniCreditConsentGate::binding($snapshot, 1, '99901');
$created = $repo->findOrCreateAttempt(1, '99901', 'shop', $snapshot['operation_key_hash'], $snapshot['selection_hash'], '', $snapshot['entry_point'], $snapshot);
$inserts = array_values(array_filter($recording->sql, function ($sql) { return strpos($sql, 'INSERT INTO') === 0; }));
consentAssert(count($inserts) === 1 && strpos($inserts[0], '`application_snapshot_json`') !== false && strpos($inserts[0], '`application_snapshot_hash`') !== false, 'one atomic INSERT owns v2 snapshot and hash');
consentAssert(MtUniCreditConsentGate::permits($created), 'atomic readback verified');
consentReject(function () use ($repo) { $repo->findOrCreateAttempt(1, '99902', 'shop', 'op', 'selection', '', 'checkout'); }, 'no intermediate new evidence-free attempt');
$race = new ConsentRecordingDb(); $raceRepo = new MtUniCreditFinancingAttemptRepository(new MtUniCreditDbAdapter($race, 'oc_'));
$winner = $created; unset($winner['attempt_id']); $race->winner = $winner;
$loser = $snapshot; $loser['consent_evidence']['accepted_at'] = '2020-01-01T00:00:00Z';
$won = $raceRepo->findOrCreateAttempt(1, '99901', 'shop', $snapshot['operation_key_hash'], $snapshot['selection_hash'], '', $snapshot['entry_point'], $loser);
consentAssert($won['application_snapshot_hash'] === $created['application_snapshot_hash'], 'concurrent winner evidence reused without timestamp overwrite');
$bound = MtUniCreditApplicationSnapshot::bindToAttempt($raceRepo, $won, $loser);
consentAssert($bound['ok'], 'fresh acceptance timestamp is not financial drift');
$loser['scheme']['months'] = 24;
consentAssert(!MtUniCreditApplicationSnapshot::bindToAttempt($raceRepo, $won, $loser)['ok'], 'financial selection drift still rejects');
echo 'UNI-CONSENT: ' . $passes . ' PASS' . PHP_EOL;
