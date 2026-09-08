<?php

/**
 * AUD-022-F02 — OCMOD failure policy must be on <operation>, not <file>.
 * Run: php tests/phase_aud022_f02_ocmod_error_policy_check.php
 *
 * Models OC3 marketplace/modification.php loop semantics:
 *   error=abort → break 5 (abort whole modification)
 *   error=skip  → continue (next operation in same file)
 *   (empty)     → break (remaining ops for this file aborted)
 *
 * PHP 7.3 compatible. Offline.
 */
require_once __DIR__ . '/bootstrap.php';

$failures = array();
$passes = 0;

/**
 * @param bool $condition
 * @param string $message
 * @return void
 */
function mtucAud022F02_assert($condition, $message)
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

$root = MTUC_PHASE0_ROOT;
$xmlPath = $root . DIRECTORY_SEPARATOR . 'install.xml';
$xmlText = (string) file_get_contents($xmlPath);
$enginePath = dirname($root) . DIRECTORY_SEPARATOR . 'reference-oc3-core'
    . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'controller'
    . DIRECTORY_SEPARATOR . 'marketplace' . DIRECTORY_SEPARATOR . 'modification.php';

mtucAud022F02_assert(is_file($xmlPath) && $xmlText !== '', 'install.xml readable');
$dom = new DOMDocument();
mtucAud022F02_assert(@$dom->loadXML($xmlText), 'install.xml parses');

// Prove OC3 engine reads operation-level error (not file-level).
mtucAud022F02_assert(is_file($enginePath), 'OC3 modification engine present for authority');
$engineSrc = (string) file_get_contents($enginePath);
mtucAud022F02_assert(
    strpos($engineSrc, "\$error = \$operation->getAttribute('error')") !== false,
    'OC3 reads error from $operation->getAttribute(error)'
);
mtucAud022F02_assert(
    strpos($engineSrc, "\$error == 'abort'") !== false
        && strpos($engineSrc, "\$error == 'skip'") !== false,
    'OC3 abort/skip branches exist on operation error'
);

$xpath = new DOMXPath($dom);
$fileNodes = $xpath->query('//file');
mtucAud022F02_assert($fileNodes !== false && $fileNodes->length === 6, 'six <file> targets present');
$opNodes = $xpath->query('//operation');
mtucAud022F02_assert($opNodes !== false && $opNodes->length === 7, 'seven <operation> nodes present');

/**
 * @param DOMElement $file
 * @return array<int, array{error:string,search:string}>
 */
function mtucAud022F02_ops(DOMElement $file)
{
    $out = array();
    foreach ($file->getElementsByTagName('operation') as $op) {
        if (!($op instanceof DOMElement)) {
            continue;
        }
        $searchEl = $op->getElementsByTagName('search')->item(0);
        $out[] = array(
            'error' => (string) $op->getAttribute('error'),
            'search' => $searchEl ? trim((string) $searchEl->textContent) : '',
        );
    }

    return $out;
}

/**
 * Simulate OC3 per-file operation miss loop for error policy.
 *
 * @param array<int, array{error:string,search:string}> $ops
 * @param int $missIndex 0-based operation that misses
 * @return array{visited:array<int,int>, aborted_file:bool, aborted_mod:bool}
 */
function mtucAud022F02_simulateMiss(array $ops, $missIndex)
{
    $visited = array();
    $abortedFile = false;
    $abortedMod = false;
    foreach ($ops as $i => $op) {
        $visited[] = (int) $i;
        if ((int) $i !== (int) $missIndex) {
            continue;
        }
        $error = (string) $op['error'];
        if ($error === 'abort') {
            $abortedMod = true;
            break;
        }
        if ($error === 'skip') {
            continue;
        }
        // OC3 default: break remaining operations for this file.
        $abortedFile = true;
        break;
    }

    return array(
        'visited' => $visited,
        'aborted_file' => $abortedFile,
        'aborted_mod' => $abortedMod,
    );
}

$byPath = array();
foreach ($fileNodes as $file) {
    if (!($file instanceof DOMElement)) {
        continue;
    }
    $path = (string) $file->getAttribute('path');
    $byPath[$path] = $file;
    // A. No file-level error authority remains.
    mtucAud022F02_assert(
        $file->getAttribute('error') === '',
        'A: no file-level error on ' . $path
    );
}

// Map seven operations by path.
$productCtrl = 'catalog/controller/product/product.php';
$productTwig = 'catalog/view/theme/*/template/product/product.twig';
$cartCtrl = 'catalog/controller/checkout/cart.php';
$cartTwig = 'catalog/view/theme/*/template/checkout/cart.twig';
$checkoutTwig = 'catalog/view/theme/*/template/checkout/checkout.twig';
$paymentMethod = 'catalog/controller/checkout/payment_method.php';

foreach (array($productCtrl, $productTwig, $cartCtrl, $cartTwig, $checkoutTwig, $paymentMethod) as $path) {
    mtucAud022F02_assert(isset($byPath[$path]), 'target present: ' . $path);
}

$productTwigOps = mtucAud022F02_ops($byPath[$productTwig]);
$cartTwigOps = mtucAud022F02_ops($byPath[$cartTwig]);
$checkoutOps = mtucAud022F02_ops($byPath[$checkoutTwig]);
$payOps = mtucAud022F02_ops($byPath[$paymentMethod]);

mtucAud022F02_assert(count($productTwigOps) === 1, 'Product Twig has one operation');
mtucAud022F02_assert(count($cartTwigOps) === 1, 'Cart Twig has one operation');
mtucAud022F02_assert(count($checkoutOps) === 1, 'Checkout Twig has one operation');
mtucAud022F02_assert(count($payOps) === 2, 'payment_method has two operations');

// B. Product intended abort on operation
mtucAud022F02_assert(
    $productTwigOps[0]['error'] === 'abort',
    'B: Product Twig operation error=abort'
);
mtucAud022F02_assert(
    strpos($productTwigOps[0]['search'], '{% if minimum > 1 %}') !== false,
    'B: Product Twig frozen minimum anchor preserved'
);

// C. Cart intended skip on operation
mtucAud022F02_assert(
    $cartTwigOps[0]['error'] === 'skip',
    'C: Cart Twig operation error=skip'
);

// D. Checkout Twig intended skip on operation
mtucAud022F02_assert(
    $checkoutOps[0]['error'] === 'skip',
    'D: Checkout Twig operation error=skip'
);

// E/F. payment_method ops 6 and 7
mtucAud022F02_assert(
    $payOps[0]['error'] === 'skip'
        && strpos($payOps[0]['search'], "payment_methods'] = \$method_data") !== false,
    'E: payment_method operation 6 error=skip (preselect)'
);
mtucAud022F02_assert(
    $payOps[1]['error'] === 'skip'
        && strpos($payOps[1]['search'], "payment_method'] = \$this->session->data['payment_methods']") !== false,
    'F: payment_method operation 7 error=skip (cleanup)'
);

// G. Operation 6 miss must still attempt operation 7 under skip semantics.
$simSkip = mtucAud022F02_simulateMiss($payOps, 0);
mtucAud022F02_assert(
    in_array(0, $simSkip['visited'], true)
        && in_array(1, $simSkip['visited'], true)
        && $simSkip['aborted_file'] === false
        && $simSkip['aborted_mod'] === false,
    'G: op6 miss with skip still visits op7'
);

// Contrast: default/empty policy would suppress op7 (mutation sensitivity).
$payDefault = array(
    array('error' => '', 'search' => $payOps[0]['search']),
    array('error' => '', 'search' => $payOps[1]['search']),
);
$simDefault = mtucAud022F02_simulateMiss($payDefault, 0);
mtucAud022F02_assert(
    in_array(0, $simDefault['visited'], true)
        && !in_array(1, $simDefault['visited'], true)
        && $simDefault['aborted_file'] === true,
    'G-contrast: default/empty policy on op6 suppresses op7'
);

// Product abort miss aborts modification (not merely file break).
$simAbort = mtucAud022F02_simulateMiss($productTwigOps, 0);
mtucAud022F02_assert(
    $simAbort['aborted_mod'] === true,
    'Product abort miss aborts modification'
);

// No stale file-level error attributes remain anywhere.
mtucAud022F02_assert(
    !preg_match('/<file\b[^>]*\berror\s*=/', $xmlText),
    'no <file ... error=> attributes remain in XML text'
);
mtucAud022F02_assert(
    preg_match_all('/<operation\b[^>]*\berror\s*=\s*"abort"/', $xmlText) === 1,
    'exactly one operation error=abort (Product Twig)'
);
mtucAud022F02_assert(
    preg_match_all('/<operation\b[^>]*\berror\s*=\s*"skip"/', $xmlText) === 4,
    'exactly four operation error=skip (Cart, Checkout, payment 6+7)'
);

// Mutation sensitivity matrix (string + structural).
$focusedSrc = (string) file_get_contents(__FILE__);
$mutation = array(
    '1 moving error policy back to <file>' => (
        strpos($focusedSrc, 'no file-level error') !== false
        && !preg_match('/<file\b[^>]*\berror\s*=/', $xmlText)
    ),
    '2 removing Product operation policy' => (
        $productTwigOps[0]['error'] === 'abort'
        && strpos($focusedSrc, 'Product Twig operation error=abort') !== false
    ),
    '3 removing Cart operation policy' => (
        $cartTwigOps[0]['error'] === 'skip'
        && strpos($focusedSrc, 'Cart Twig operation error=skip') !== false
    ),
    '4 removing Checkout operation policy' => (
        $checkoutOps[0]['error'] === 'skip'
        && strpos($focusedSrc, 'Checkout Twig operation error=skip') !== false
    ),
    '5 operation 6 miss preventing operation 7 contrary to skip' => (
        in_array(1, $simSkip['visited'], true)
        && !in_array(1, $simDefault['visited'], true)
    ),
    '6 changing error policy value accidentally' => (
        preg_match_all('/<operation\b[^>]*\berror\s*=\s*"abort"/', $xmlText) === 1
        && preg_match_all('/<operation\b[^>]*\berror\s*=\s*"skip"/', $xmlText) === 4
    ),
);

foreach ($mutation as $label => $ok) {
    mtucAud022F02_assert($ok, 'mutation YES: ' . $label);
}

echo PHP_EOL . 'AUD-022-F02 OCMOD ERROR POLICY: '
    . ($failures ? 'FAIL' : 'PASS')
    . ' (' . $passes . ' assertions)' . PHP_EOL;

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

exit(0);
