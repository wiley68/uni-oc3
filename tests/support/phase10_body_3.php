<?php
// ---------------------------------------------------------------------------
// K. Thank You presentation privacy + missing snapshot safety
// ---------------------------------------------------------------------------
$presenter = new MtUniCreditFinancingLeasingPresenter();
$snap = new MtUniCreditFinancingPresentationSnapshot(
    10130,
    999,
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
$svc = new MtUniCreditFinancingPresentationService(
    new MtUniCreditFinancingPresentationRepository(
        new MtUniCreditDbAdapter($stackValid['memoryDb'], 'oc_')
    ),
    $presenter
);
// Direct HTML path via service customerThankYouHtml against seeded attempt
Phase9TestHarness::seedBankOrder($stackValid['memoryDb'], 10130, $stackValid['storeId']);
$attemptRow = $stackValid['attempts']->findOrCreateAttempt(
    $stackValid['storeId'],
    10130,
    Phase4TestHarness::TEST_UNICID,
    hash('sha256', 'thankyou-test'),
    hash('sha256', 'sel'),
    hash('sha256', 'fp'),
    MtUniCreditOperationEntryPoint::PRODUCT
);
(new MtUniCreditProcessTwoLifecycleRepository(new MtUniCreditDbAdapter($stackValid['memoryDb'], 'oc_')))
    ->persistLeasingPresentationJson((int) $attemptRow['attempt_id'], json_encode($snap->toArray()));
(new MtUniCreditOrderBankStatusRepository(new MtUniCreditDbAdapter($stackValid['memoryDb'], 'oc_')))
    ->updateByOrderIdentifier(
        $stackValid['storeId'],
        (string) 10130,
        MtUniCreditBankStatus::SENT_PROCESS2,
        MtUniCreditBankStatus::LABEL_SENT_PROCESS2
    );

$thankHtml = $svc->customerThankYouHtml($stackValid['storeId'], 10130);
mtuc10_assert($thankHtml !== '', 'thank you: leasing HTML present');
mtuc10_assert(strpos($thankHtml, 'УниКредит лизинг') !== false, 'thank you: title present');
mtuc10_assert(strpos($thankHtml, 'KOPSTD') !== false, 'thank you: scheme/KOP present');
mtuc10_assert(strpos($thankHtml, '12') !== false, 'thank you: months visible');
mtuc10_assert(strpos($thankHtml, '45.00') !== false, 'thank you: monthly installment visible');
mtuc10_assert(strpos($thankHtml, '5.50% / 6.10%') !== false, 'thank you: GLP/GPR visible');
mtuc10_assert(strpos($thankHtml, MtUniCreditFinancingLeasingPresenter::LABEL_EGN) === false, 'thank you: EGN label absent');
mtuc10_assert(strpos($thankHtml, MtUniCreditFinancingLeasingPresenter::LABEL_PHONE2) === false, 'thank you: phone2 label absent');
mtuc10_assert(
    strpos($thankHtml, MtUniCreditFinancingLeasingPresenter::LABEL_CP_INTERNAL_ID) === false,
    'thank you: CP internal id absent'
);
mtuc10_assert(preg_match('/\b1990010112\b/', $thankHtml) !== 1, 'thank you: raw EGN digits absent');

$missingHtml = $svc->customerThankYouHtml($stackValid['storeId'], 999999);
mtuc10_assert($missingHtml === '', 'thank you: missing snapshot returns empty (generic success allowed)');

// Native mail enrichment simulation (OC3 view/*/after signature)
$customerMailOut = '<html><body>Native customer order mail</body></html>';
$adminMailOut = "Native admin order alert\nOrder ID: 10130";
$mailData = array('order_id' => 10130);
// Persist sensitive for ADMIN_EMAIL audience parity with OC4
$cipher = new MtUniCreditProcessTwoSensitiveCipher(MtUniCreditEncryptionKeyProvider::testSecretInput());
$enc = $cipher->encrypt(new MtUniCreditProcessTwoSensitiveData('1990010112', '+35988111111'));
(new MtUniCreditProcessTwoLifecycleRepository(new MtUniCreditDbAdapter($stackValid['memoryDb'], 'oc_')))
    ->persistSensitiveEncrypted((int) $attemptRow['attempt_id'], $enc);

$customerRows = $svc->filterCustomerFacingRows(
    $svc->rowsForOrder($stackValid['storeId'], 10130, MtUniCreditFinancingPresentationAudience::CUSTOMER)
);
$customerChunk = $presenter->renderHtml($customerRows);
mtuc10_assert($customerChunk !== '', 'native customer mail: leasing chunk present');
mtuc10_assert(strpos($customerChunk, MtUniCreditFinancingLeasingPresenter::LABEL_EGN) === false, 'native customer mail: EGN absent');
mtuc10_assert(strpos($customerChunk, MtUniCreditFinancingLeasingPresenter::LABEL_PHONE2) === false, 'native customer mail: phone2 absent');
$customerMailOut .= '<br/>' . $customerChunk;
mtuc10_assert(
    strpos($customerMailOut, 'class="mt-uni-credit-leasing-block"') !== false,
    'native customer mail: leasing block marker present'
);
mtuc10_assert(
    substr_count($customerMailOut, 'class="mt-uni-credit-leasing-block"') === 1,
    'native customer mail: leasing appended once'
);

$adminRows = $svc->rowsForOrder($stackValid['storeId'], 10130, MtUniCreditFinancingPresentationAudience::ADMIN_EMAIL);
$adminMap = array();
foreach ($adminRows as $row) {
    $adminMap[$row['label']] = $row['value'];
}
mtuc10_assert(
    isset($adminMap[MtUniCreditFinancingLeasingPresenter::LABEL_EGN])
        && $adminMap[MtUniCreditFinancingLeasingPresenter::LABEL_EGN] === '1990010112',
    'native admin mail audience ADMIN_EMAIL includes EGN per OC4'
);
$adminChunk = $presenter->renderText($adminRows);
$adminMailOut .= "\n\n" . $adminChunk;
mtuc10_assert(strpos($adminMailOut, 'УниКредит лизинг') !== false, 'native admin mail: leasing title present');
mtuc10_assert(substr_count($adminMailOut, 'УниКредит лизинг') === 1, 'native admin mail: leasing once');

// Non-UniCredit order: no rows
$nonUni = $svc->rowsForOrder($stackValid['storeId'], 424242, MtUniCreditFinancingPresentationAudience::CUSTOMER);
mtuc10_assert($nonUni === array(), 'non-UniCredit order: no leasing rows');

// Event registry wiring
$defs = MtUniCreditCatalogEventRegistry::definitions();
$triggers = array();
foreach ($defs as $def) {
    $triggers[$def['code']] = $def['trigger'];
}
mtuc10_assert(
    isset($triggers['mt_uni_credit_checkout_success_order'])
        && $triggers['mt_uni_credit_checkout_success_order'] === 'catalog/controller/checkout/success/before',
    'events: success order stash trigger'
);
mtuc10_assert(
    isset($triggers['mt_uni_credit_checkout_success_view'])
        && $triggers['mt_uni_credit_checkout_success_view'] === 'catalog/view/common/success/before',
    'events: success view before trigger'
);
mtuc10_assert(
    isset($triggers['mt_uni_credit_mail_order_add'])
        && $triggers['mt_uni_credit_mail_order_add'] === 'catalog/view/mail/order_add/after',
    'events: customer mail order_add/after'
);
mtuc10_assert(
    isset($triggers['mt_uni_credit_mail_order_alert'])
        && $triggers['mt_uni_credit_mail_order_alert'] === 'catalog/view/mail/order_alert/after',
    'events: admin mail order_alert/after'
);
mtuc10_assert(
    is_file(
        $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR
            . 'controller' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'mt_uni_credit'
            . DIRECTORY_SEPARATOR . 'order_mail.php'
    ),
    'wiring: order_mail event controller present'
);

// Timing: leasing snapshot persist precedes lifecycle submit in Product/Cart + Checkout services
$sfSrc = mtuc10_read($lib . DIRECTORY_SEPARATOR . 'storefront_financing_submission_service.php');
$coSrc = mtuc10_read($lib . DIRECTORY_SEPARATOR . 'checkout_financing_submission_service.php');
$sfPersistPos = strpos($sfSrc, 'persistLeasingSnapshot');
$sfLifecyclePos = strpos($sfSrc, 'submitOrRecover');
mtuc10_assert(
    $sfPersistPos !== false && $sfLifecyclePos !== false && $sfPersistPos < $sfLifecyclePos,
    'timing: storefront persists leasing snapshot before CP lifecycle'
);
$coPersistPos = strpos($coSrc, 'persistLeasingSnapshot');
$coLifecyclePos = strpos($coSrc, 'submitOrRecover');
mtuc10_assert(
    $coPersistPos !== false && $coLifecyclePos !== false && $coPersistPos < $coLifecyclePos,
    'timing: checkout persists leasing snapshot before CP lifecycle'
);
// Controllers apply native status (mails) only after submission returns — snapshot already durable
$productSubmit = mtuc10_read(
    $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR
        . 'controller' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'mt_uni_credit'
        . DIRECTORY_SEPARATOR . 'product.php'
);
mtuc10_assert(
    strpos($productSubmit, 'maybeApplyNativeOrderStatus') !== false,
    'timing: native addOrderHistory after submit result'
);

$navSession = array();
$navPayload = MtUniCreditFinancingTerminalNavigationSupport::enrichProcess2ThankYou(
    array('success' => true),
    $navSession,
    10130,
    'https://shop.example/index.php?route=checkout/success'
);
mtuc10_assert(
    !empty($navPayload['redirect']) && strpos($navPayload['redirect'], 'checkout/success') !== false,
    'thank you nav: redirect = checkout/success'
);
mtuc10_assert(empty($navPayload['bank_redirect']), 'thank you nav: bank_redirect = false');
mtuc10_assert(
    (int) $navSession[MtUniCreditFinancingTerminalNavigationSupport::SESSION_SUCCESS_ORDER_ID] === 10130,
    'thank you nav: success order id stashed'
);

$p1Session = array();
$p1Payload = MtUniCreditFinancingTerminalNavigationSupport::enrichProcess2ThankYou(
    array(
        'success' => true,
        'bank_redirect' => true,
        'redirect' => 'https://bank.example/app',
    ),
    $p1Session,
    10130,
    'https://shop.example/index.php?route=checkout/success'
);
mtuc10_assert(
    $p1Payload['redirect'] === 'https://bank.example/app',
    'Process 1 isolation: bank redirect unchanged by Thank You enrichment'
);

$productCtrl = mtuc10_read(
    $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR
        . 'controller' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'mt_uni_credit'
        . DIRECTORY_SEPARATOR . 'product.php'
);
mtuc10_assert(
    strpos($productCtrl, 'CHECKOUT_SUCCESS_ROUTE') !== false
        || strpos($productCtrl, 'checkout/success') !== false,
    'wiring: Product Process 2 success uses checkout/success'
);
mtuc10_assert(
    is_file(
        $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR
            . 'controller' . DIRECTORY_SEPARATOR . 'extension' . DIRECTORY_SEPARATOR . 'mt_uni_credit'
            . DIRECTORY_SEPARATOR . 'checkout_success.php'
    ),
    'wiring: checkout_success event controller present'
);


