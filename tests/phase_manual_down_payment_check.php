<?php

/**
 * Offline Product/Cart controller-builder to consumer regression.
 * Run: php tests/phase_manual_down_payment_check.php
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/support/phase2_memory_db.php';
require_once __DIR__ . '/support/oc3_registry_controller.php';

define('DIR_SYSTEM', MTUC_PHASE0_ROOT . '/upload/system/');
mtuc_test_define_dir_storage('mtuc-manual-down-payment');
define('DB_PASSWORD', 'offline-manual-down-payment-fixture');
require_once DIR_SYSTEM . 'library/mt_uni_credit/bootstrap.php';
foreach (array('phase4_harness', 'phase5_harness', 'phase7_harness', 'phase9_harness') as $support) {
    require_once __DIR__ . '/support/' . $support . '.php';
}
require_once __DIR__ . '/fixtures/cp_shop_snapshot.php';
foreach (array('product', 'cart', 'order_mail') as $controller) {
    require_once MTUC_PHASE0_ROOT . '/upload/catalog/controller/extension/mt_uni_credit/' . $controller . '.php';
}
require_once MTUC_PHASE0_ROOT . '/upload/admin/controller/extension/mt_uni_credit/admin_order.php';

function mtucDownPaymentAssert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$GLOBALS['mtucDownPaymentAssertions'];
}

function mtucDownPaymentInvoke($controller, $name, array $arguments)
{
    $method = new ReflectionMethod($controller, $name);
    $method->setAccessible(true);

    return $method->invokeArgs($controller, $arguments);
}

function mtucDownPaymentFinancial(array $values, array $expected, $label)
{
    foreach ($expected as $key => $value) {
        mtucDownPaymentAssert(isset($values[$key]) && abs((float) $values[$key] - $value) < 0.00001, "$label $key");
    }
}

$mtucDownPaymentAssertions = 0;
$caseCount = 0;
$edgeInputs = array(
    'missing' => null, 'empty' => '', 'array' => array('200'), 'malformed' => '200junk',
    'fractional' => '200,125', 'negative' => '-10.25', 'full' => '1000', 'above' => '1000.01',
    'invalid' => '200', 'ambiguous' => '200',
);
foreach (array(1, 2) as $process) {
    foreach (array('product', 'cart', 'checkout') as $entry) {
        $policies = array('manual', 'zero', 'locked', 'hidden');
        if ($entry !== 'checkout') {
            $policies = array_merge($policies, array_keys($edgeInputs));
        }
        foreach ($policies as $policy) {
            foreach ($entry !== 'checkout' && $policy === 'manual' ? array(1000.0, 1100.0) : array(1000.0) as $savedTotal) {
                $label = "$entry P$process $policy / $savedTotal";
                $orderId = 123400 + ++$caseCount;
                $storeId = Phase5TestHarness::STORE_A;
                $overrides = array(
                    'uni_proces' => $process - 1,
                    'uni_first_vnoska' => $policy === 'hidden' ? 0 : 1,
                    'coeff_list' => array(array(
                        'onlineProductCode' => 'KOPSTD', 'installmentCount' => 12,
                        'coeff' => 0.095, 'interestPercent' => 5.5,
                    )),
                );
                if ($policy === 'locked') {
                    $overrides['uni_typekop'] = 1;
                    $overrides['kop'] = array('by_schema' => array('filters' => array(array(
                        'id' => 11, 'product_id' => 42, 'category_id' => null,
                        'uni_meseci' => '12', 'uni_promo' => 0, 'uni_parva' => 1, 'uni_kop' => 'KOPSTD',
                    ))));
                }
                if ($policy === 'ambiguous') {
                    $overrides['coeff_list'][] = array_replace($overrides['coeff_list'][0], array('coeff' => 0.10));
                }
                $transport = new Phase4FakeCpHttpTransport();
                Phase9TestHarness::enqueueCpCreateSuccess($transport);
                $transport->failStatusPatch = $entry !== 'checkout' && $policy === 'manual' && $savedTotal === 1100.0;
                $stack = Phase9TestHarness::stack($transport, null, null, $storeId, $overrides);
                $shop = mtuc4_valid_shop_snapshot($overrides);
                $consent = (new MtUniCreditStorefrontConsentResolver())->context($shop, 'KOPSTD', 12, array());
                $cart = new MtUniCreditCartContext(array(
                    new MtUniCreditCartLine(new MtUniCreditProductContext(42, array(7), 1000.0), 0, 1, 1000.0),
                ), 1000.0);
                $order = Phase7TestHarness::orderRow($orderId, $storeId, $savedTotal);
                $order['store_email'] = 'store@example.test';
                $products = array(array(
                    'product_id' => 42, 'name' => 'Example', 'model' => 'EX', 'quantity' => 1,
                    'price' => 1000.0, 'total' => 1000.0, 'tax_class_id' => 0,
                ));
                $db = $stack['memoryDb'];
                $native = new class($db, $order) {
                    private $db;
                    public $order;
                    public $creations = 0;
                    public $history = 0;
                    public $draftTotal;
                    public function __construct($db, array $order) { $this->db = $db; $this->order = $order; }
                    public function addOrder(array $draft)
                    {
                        ++$this->creations;
                        $this->draftTotal = (float) $draft['total'];
                        $this->db->seedOrder((int) $this->order['order_id'], (int) $this->order['store_id']);
                        return (int) $this->order['order_id'];
                    }
                    public function getOrder($id) { return $this->order; }
                    public function addOrderHistory($id, $status) { ++$this->history; }
                };
                $config = new class($storeId) {
                    private $settings;
                    public function __construct($storeId)
                    {
                        $this->settings = array(
                            'config_store_id' => $storeId, 'config_url' => 'https://shop.example.test/',
                            MtUniCreditConstants::PAYMENT_SETTING_ORDER_STATUS_ID => 2,
                        );
                    }
                    public function get($key) { return $this->settings[$key] ?? null; }
                };
                $requested = $policy === 'zero' ? '0' : ($entry === 'cart' ? '200,00' : '200.00');
                if (array_key_exists($policy, $edgeInputs)) {
                    $requested = $edgeInputs[$policy];
                }
                $db->orderStatuses[2] = true;
                $registry = new class {
                    public $values = array();
                    public function get($key) { return $this->values[$key] ?? null; }
                };
                $registry->values = array('db' => $db, 'config' => $config);
                if ($entry === 'checkout') {
                    $input = Phase9TestHarness::submitInput($orderId, $storeId);
                    $input['order'] = $order;
                    $input['order']['order_id'] = (string) $orderId;
                    $input['order_products'] = $products;
                    $input['cart_context'] = $cart;
                    $input['first_installment'] = (float) $requested;
                    $input['consent_config_hash'] = $consent['config_hash'];
                    $db->seedOrder($orderId, $storeId);
                    $result = $stack['submission']->submit($input);
                } else {
                    $line = new MtUniCreditProductLine(42, 'Example', 'EX', array(7), 1, 1000.0, 1000.0, 1000.0, 0, array(), 0);
                    $fingerprint = MtUniCreditStorefrontOperationIdentity::cartFingerprintFromContext($cart, 'EUR');
                    $identity = $entry === 'product'
                        ? MtUniCreditStorefrontOperationIdentity::productHash($storeId, 42, array(), 1, 'EUR')
                        : MtUniCreditStorefrontOperationIdentity::cartHash($storeId, 'EUR', $fingerprint);
                    $session = array();
                    $token = MtUniCreditStorefrontApplicationToken::issue($session, $storeId, $entry, $identity);
                    $request = (object) array('server' => array('REQUEST_METHOD' => 'POST'), 'post' => array(
                        'application_token' => $token, 'scheme_key' => 'standard|KOPSTD|12',
                        'consent' => array(1), 'consent_config_hash' => $consent['config_hash'],
                        'firstname' => 'Example', 'lastname' => 'Customer', 'email' => $order['email'],
                        'phone' => $order['telephone'], 'address' => $order['payment_address_1'],
                        'first_installment' => $requested,
                    ));
                    if ($policy === 'missing') {
                        unset($request->post['first_installment']);
                    }
                    if ($policy === 'invalid') {
                        $request->post['scheme_key'] = 'standard|UNAVAILABLE|12';
                    }
                    $registry->values += array(
                        'request' => $request,
                        'session' => (object) array('data' => $session),
                        'customer' => new class { public function isLogged() { return false; } },
                        'currency' => new class {
                            public function getId($code) { return 1; }
                            public function getValue($code) { return 1.0; }
                        },
                        'load' => new class { public function model($route) {} },
                        'model_checkout_order' => $native,
                        'cart' => new class($products) {
                            private $products;
                            public function __construct(array $products) { $this->products = $products; }
                            public function getProducts() { return $this->products; }
                            public function clear() { $this->products = array(); }
                            public function restore(array $products) { $this->products = $products; }
                        },
                        'tax' => new class { public function calculate($price, $taxClass, $enabled) { return $price; } },
                        'url' => new class {
                            public function link($route, $args, $secure) { return 'https://shop.example.test/?route=' . $route; }
                        },
                        'response' => new class {
                            public $output;
                            public function addHeader($header) {}
                            public function setOutput($output) { $this->output = $output; }
                        },
                    );
                    $class = 'ControllerExtensionMtUniCredit' . ucfirst($entry);
                    $controller = new $class($registry);
                    $builder = $entry === 'product' ? 'buildProductSubmitInput' : 'buildCartSubmissionInput';
                    $args = $entry === 'product' ? array($line, 'EUR') : array($cart, 'EUR', $fingerprint);
                    $input = mtucDownPaymentInvoke($controller, $builder, $args);
                    $expectedRequested = array(
                        'manual' => 200.0, 'locked' => 200.0, 'hidden' => 200.0, 'fractional' => 200.125,
                        'negative' => -10.25, 'full' => 1000.0, 'above' => 1000.01, 'invalid' => 200.0, 'ambiguous' => 200.0,
                    );
                    mtucDownPaymentAssert(($input['first_installment'] ?? null) === ($expectedRequested[$policy] ?? 0.0), "$label controller propagation");
                    $result = $stack['storefront']->submit($input);
                    if (in_array($policy, array('full', 'above', 'invalid', 'ambiguous'), true)) {
                        mtucDownPaymentAssert(empty($result['success']) && $native->creations === 0, "$label rejected before order");
                        mtucDownPaymentAssert(Phase7TestHarness::countOrderPosts($transport) === 0 && Phase9TestHarness::smartUcfCallCount($stack['smartUcfProbe']) === 0, "$label rejected before outbound");
                        echo "PASS $label\n";
                        continue;
                    }
                    mtucDownPaymentAssert($native->creations === 1 && $native->draftTotal === 1000.0, "$label original native draft total");
                    $responder = 'respond' . ucfirst($entry) . 'SubmitResult';
                    mtucDownPaymentInvoke($controller, $responder, array($result, array()));
                    $response = json_decode($registry->values['response']->output, true);
                    mtucDownPaymentAssert($response['success'] && $response['bank_redirect'] === ($process === 1)
                        && $response['redirect'] !== '', "$label controller redirect");
                    mtucDownPaymentAssert($native->history === 1, "$label native history");
                    // Restore the fixture items when simulating a repeat of the original Cart POST.
                    $registry->values['cart']->restore($products);
                }
                mtucDownPaymentAssert(!empty($result['success']), "$label submission: " . ($result['error'] ?? ''));
                $savedOrder = $entry === 'checkout' ? $input['order'] : $native->getOrder($orderId);
                mtucDownPaymentAssert((float) $savedOrder['total'] === $savedTotal, "$label saved native order total");
                $first = $policy === 'locked' ? 83.33 : ($policy === 'manual' ? 200.0 : 0.0);
                $principal = $savedTotal - $first;
                $monthly = $policy === 'locked' ? 87.08 : ($policy === 'manual' ? ($savedTotal === 1000.0 ? 76.0 : 85.50) : 95.0);
                if ($policy === 'fractional') {
                    $first = 200.13;
                    $principal = 799.87;
                    $monthly = 75.99;
                }

                $row = $stack['attempts']->findByStoreOrder($storeId, $orderId);
                $snapshot = json_decode($row['application_snapshot_json'], true);
                $presentationRepo = new MtUniCreditProcessTwoLifecycleRepository($stack['db']);
                $presentationRow = $presentationRepo->findByAttempt((int) $row['attempt_id']);
                $presentation = json_decode($presentationRow['leasing_presentation_json'], true);
                $financial = array('first_installment' => $first, 'financed_amount' => $principal,
                    'monthly_installment' => $monthly, 'total_payable' => round($monthly * 12, 2));
                $gpr = array('locked' => 27.95, 'fractional' => 27.97)[$policy] ?? 27.96;
                $financial['gpr'] = $gpr;
                mtucDownPaymentFinancial($snapshot['financial'], $financial + array('price' => $savedTotal), "$label application");
                mtucDownPaymentFinancial($presentation, $financial, "$label presentation");
                mtucDownPaymentAssert($snapshot['scheme']['kop_code'] === 'KOPSTD' && (int) $snapshot['scheme']['months'] === 12, "$label snapshot scheme");

                $posts = array_values(array_filter($transport->requests, function (array $request) {
                    return $request['method'] === 'POST' && substr($request['url'], -7) === '/orders';
                }));
                mtucDownPaymentAssert(count($posts) === 1, "$label CP create");
                $cp = $posts[0]['payload'];
                if (is_string($cp)) {
                    $cp = json_decode($cp, true);
                }
                mtucDownPaymentFinancial($cp, array('parva' => $first, 'price' => $savedTotal, 'vnoska' => $monthly,
                    'vnoski' => 12, 'gpr' => $gpr), "$label CP");
                mtucDownPaymentAssert($cp['currency'] === 'EUR' && $cp['order_id'] === (string) $orderId, "$label CP currency and identity");
                mtucDownPaymentAssert(Phase9TestHarness::smartUcfCallCount($stack['smartUcfProbe']) === ($process === 1 ? 1 : 0), "$label SmartUCF calls");
                mtucDownPaymentAssert(!empty($result['bank_redirect']) === ($process === 1), "$label bank redirect");
                if ($process === 1) {
                    $bank = Phase9TestHarness::smartUcfPayloadAt($stack['smartUcfProbe']);
                    mtucDownPaymentFinancial($bank, array('totalPrice' => $savedTotal, 'initialPayment' => $first,
                        'monthlyPayment' => $monthly, 'installmentCount' => 12), "$label SmartUCF");
                    mtucDownPaymentAssert($bank['onlineProductCode'] === 'KOPSTD', "$label SmartUCF KOP");
                }

                $data = array('order_id' => $orderId, 'store_id' => $storeId);
                $mail = new ControllerExtensionMtUniCreditOrderMail($registry);
                $admin = new ControllerExtensionMtUniCreditAdminOrder($registry);
                foreach (array('afterOrderAdd', 'afterOrderAlert', 'afterOrderInfo') as $hook) {
                    $route = '';
                    $output = '<body>Test order</body>';
                    $target = $hook === 'afterOrderInfo' ? $admin : $mail;
                    $target->$hook($route, $data, $output);
                    foreach (array(
                        MtUniCreditFinancingLeasingPresenter::LABEL_FIRST => $first,
                        MtUniCreditFinancingLeasingPresenter::LABEL_LOAN => $principal,
                        MtUniCreditFinancingLeasingPresenter::LABEL_MONTHLY => $monthly,
                    ) as $rowLabel => $amount) {
                        $value = number_format($amount, 2, '.', '');
                        $rendered = preg_replace('~</th>\s*<td[^>]*>~i', ': ', $output);
                        mtucDownPaymentAssert(strpos(strip_tags($rendered), $rowLabel . ': ' . $value) !== false, "$label $hook $rowLabel");
                    }
                }

                if ($entry !== 'checkout' && in_array($policy, array('manual', 'zero'), true)) {
                    $recovering = $transport->failStatusPatch;
                    $patchCount = Phase9TestHarness::countStatusPatches($transport);
                    $transport->failStatusPatch = false;
                    foreach (array($requested, '350.00') as $replayedAmount) {
                        $registry->values['session']->data = $result['session'];
                        $request->post['first_installment'] = $replayedAmount;
                        $replay = $stack['storefront']->submit(mtucDownPaymentInvoke($controller, $builder, $args));
                        mtucDownPaymentAssert(!empty($replay['success']) && (int) $replay['order_id'] === $orderId, "$label replay");
                        mtucDownPaymentAssert($native->creations === 1 && Phase7TestHarness::countOrderPosts($transport) === 1, "$label replay no duplicate order");
                        mtucDownPaymentAssert(Phase9TestHarness::smartUcfCallCount($stack['smartUcfProbe']) === ($process === 1 ? 1 : 0), "$label replay SmartUCF calls");
                        mtucDownPaymentInvoke($controller, $responder, array($replay, array()));
                        mtucDownPaymentAssert($native->history === 1, "$label replay native history");
                        $after = $stack['attempts']->findByStoreOrder($storeId, $orderId);
                        mtucDownPaymentAssert($after['application_snapshot_json'] === $row['application_snapshot_json']
                            && $presentationRepo->findByAttempt((int) $row['attempt_id'])['leasing_presentation_json'] === $presentationRow['leasing_presentation_json'], "$label frozen replay values");
                    }
                    if ($recovering) {
                        mtucDownPaymentAssert(Phase9TestHarness::countStatusPatches($transport) > $patchCount, "$label pending status retry");
                    }
                }
                echo "PASS $label\n";
            }
        }
    }
}

foreach (array(
    array('200.125', 200.125), array('200,125', 200.125), array(null, 0.0), array('', 0.0),
    array('0', 0.0), array('200junk', 0.0), array("200\0", 0.0), array(array('200'), 0.0), array(true, 0.0),
    array('1e999', 0.0), array('-10.25', -10.25), array('1000.00', 1000.0), array('1000.01', 1000.01),
) as $case) {
    mtucDownPaymentAssert(MtUniCreditStorefrontFinancingSubmissionService::normalizeRequestedFirstInstallment($case[0]) === $case[1], 'numeric request normalization');
}

echo "$caseCount cases, $mtucDownPaymentAssertions assertions passed\n";
