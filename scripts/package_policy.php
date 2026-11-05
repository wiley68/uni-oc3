<?php
/** Release policy shared by the CLI builder and offline security regressions. PHP 7.3+. */
function package_path_check($path)
{
    if (!is_string($path) || $path === '' || !mb_check_encoding($path, 'UTF-8') || preg_match('/[\\x00-\\x1f\\x7f\\\\:]/', $path)
        || $path[0] === '/' || preg_match('~(^|/)\.{1,2}(/|$)|//|/$~', $path)) {
        throw new RuntimeException('Unsafe package path: ' . $path);
    }
    if ($path !== 'install.xml' && strpos($path, 'upload/') !== 0) {
        throw new RuntimeException('Unexpected package root: ' . $path);
    }
    $lower = mb_strtolower($path, 'UTF-8');
    $leaf = basename($lower);
    $blockedSegments = array('.git', '.github', '.vscode', '.idea', 'tests', 'test', 'fixtures', 'coverage');
    foreach (explode('/', $lower) as $segment) {
        if (in_array($segment, $blockedSegments, true)) {
            throw new RuntimeException('Forbidden package segment: ' . $path);
        }
    }
    if (in_array($lower, package_forbidden_entries(), true) || strpos($lower, 'upload/catalog/view/image/') === 0
        || preg_match('/^\.env(?:\.|$)/', $leaf)
        || in_array($leaf, array('config.local.php', '.gitignore', '.gitattributes', 'phpunit.xml', 'phpunit.xml.dist', 'thumbs.db', 'desktop.ini', '.ds_store'), true)
        || preg_match('/(?:\.local\.php|\.local|\.code-workspace|\.sublime-project|\.sublime-workspace|\.swp|\.swo|~|\.bak|\.orig|\.rej|\.tmp|\.temp|\.log|\.pem|\.key|\.p12|\.pfx|\.jks)$/', $leaf)) {
        throw new RuntimeException('Forbidden package path: ' . $path);
    }
}

function package_content_check($path, $bytes)
{
    // Scan all bytes, including extensionless files, to avoid disguising credential/debug content.
    foreach (array('force_test_cp_create_422', 'X-UniPayment-Test-Failure', 'UNIPAYMENT_ENABLE_TEST_FAILURES', 'ShopApiTestFailure',
        '-----BEGIN PRIVATE KEY-----', '-----BEGIN RSA PRIVATE KEY-----', '-----BEGIN EC PRIVATE KEY-----', '-----BEGIN OPENSSH PRIVATE KEY-----') as $needle) {
        if (strpos($bytes, $needle) !== false) {
            throw new RuntimeException('Forbidden content in ' . $path);
        }
    }
}

/** Reject links in every absolute filesystem component, including ancestors. */
function package_safe_absolute($path)
{
    if (!is_string($path) || $path === '' || $path[0] !== '/' || preg_match('/[\\x00-\\x1f\\x7f\\\\]/', $path)) {
        throw new RuntimeException('Expected safe absolute Linux path');
    }
    $current = '';
    foreach (explode('/', substr($path, 1)) as $part) {
        if ($part === '' || $part === '.' || $part === '..') {
            throw new RuntimeException('Noncanonical filesystem path: ' . $path);
        }
        $current .= '/' . $part;
        clearstatcache(true, $current);
        if (is_link($current)) {
            throw new RuntimeException('Symlink rejected: ' . $current);
        }
    }
    return $path;
}

function package_sources($root)
{
    package_safe_absolute($root);
    if (!is_dir($root)) {
        throw new RuntimeException('Missing package root');
    }
    $files = array();
    $seen = array();
    $walk = function ($relative) use (&$walk, &$files, &$seen, $root) {
        package_path_check($relative);
        $fold = mb_strtolower($relative, 'UTF-8');
        if (isset($seen[$fold])) {
            throw new RuntimeException('Case-insensitive source collision: ' . $relative);
        }
        $seen[$fold] = true;
        $path = $root . '/' . $relative;
        package_safe_absolute($path);
        $stat = lstat($path);
        if ($stat === false || realpath($path) !== $path) {
            throw new RuntimeException('Missing or escaping source: ' . $relative);
        }
        $kind = $stat['mode'] & 0170000;
        if ($kind === 0040000 && $relative !== 'install.xml') {
            foreach (new DirectoryIterator($path) as $entry) {
                if (!$entry->isDot()) {
                    $walk($relative . '/' . $entry->getFilename());
                }
            }
        } elseif ($kind === 0100000) {
            $bytes = file_get_contents($path);
            if ($bytes === false) {
                throw new RuntimeException('Unreadable source: ' . $relative);
            }
            package_content_check($relative, $bytes);
            $files[$relative] = array('path' => $path, 'sha256' => hash('sha256', $bytes));
        } else {
            throw new RuntimeException('Non-regular source: ' . $relative);
        }
    };
    $walk('install.xml');
    // upload itself is the only directory root accepted by the traversal.
    package_safe_absolute($root . '/upload');
    if (!is_dir($root . '/upload')) {
        throw new RuntimeException('Missing upload directory');
    }
    foreach (new DirectoryIterator($root . '/upload') as $entry) {
        if (!$entry->isDot()) {
            $walk('upload/' . $entry->getFilename());
        }
    }
    ksort($files, SORT_STRING);
    foreach (package_required_entries() as $required) {
        if (!isset($files[$required])) {
            throw new RuntimeException('Missing required source: ' . $required);
        }
    }
    return $files;
}

/** Validate every entry without extracting anything to the filesystem. */
function package_validate_zip($path, array $sources)
{
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CHECKCONS) !== true) {
        throw new RuntimeException('Invalid ZIP');
    }
    try {
        $seen = array();
        $folded = array();
        foreach (range(0, $zip->numFiles - 1) as $index) {
            $name = $zip->getNameIndex($index);
            package_path_check($name);
            $fold = mb_strtolower($name, 'UTF-8');
            if (isset($folded[$fold])) {
                throw new RuntimeException('Duplicate/colliding ZIP entry: ' . $name);
            }
            $folded[$fold] = true;
            $opsys = $attributes = 0;
            if (!$zip->getExternalAttributesIndex($index, $opsys, $attributes)) {
                throw new RuntimeException('Unreadable ZIP attributes');
            }
            $kind = ($attributes >> 16) & 0170000;
            if ($opsys === ZipArchive::OPSYS_UNIX && $kind !== 0 && $kind !== 0100000) {
                throw new RuntimeException('Non-regular ZIP entry');
            }
            if (!isset($sources[$name])) {
                throw new RuntimeException('Extra or wrong-case ZIP entry: ' . $name);
            }
            $bytes = $zip->getFromIndex($index);
            if ($bytes === false || hash('sha256', $bytes) !== $sources[$name]['sha256']) {
                throw new RuntimeException('ZIP byte mismatch: ' . $name);
            }
            package_content_check($name, $bytes);
            $seen[$name] = true;
        }
        if (array_diff_key($sources, $seen)) {
            throw new RuntimeException('Missing ZIP entries');
        }
        foreach (package_required_entries() as $required) {
            if (!isset($seen[$required])) {
                throw new RuntimeException('Missing required ZIP entry: ' . $required);
            }
        }
    } finally {
        $zip->close();
    }
}

function package_required_entries()
{
    return array(
        'install.xml',
        'upload/admin/controller/extension/module/mt_uni_credit.php',
        'upload/admin/controller/extension/payment/mt_uni_credit.php',
        'upload/admin/view/image/payment/uni_logo.svg',
        'upload/admin/view/stylesheet/mt_uni_credit_module.css',
        'upload/admin/view/javascript/mt_uni_credit_module.js',
        'upload/catalog/controller/extension/payment/mt_uni_credit.php',
        'upload/catalog/controller/extension/mt_uni_credit/product.php',
        'upload/catalog/controller/extension/mt_uni_credit/cart.php',
        'upload/catalog/model/extension/payment/mt_uni_credit.php',
        'upload/catalog/view/theme/default/template/extension/payment/mt_uni_credit.twig',
        'upload/catalog/view/theme/default/template/extension/mt_uni_credit/storefront.js',
        'upload/catalog/view/theme/default/template/extension/mt_uni_credit/storefront.css',
        'upload/catalog/view/theme/default/template/extension/mt_uni_credit/storefront_fonts.css',
        'upload/catalog/view/theme/default/template/extension/mt_uni_credit/image/uni_logo.svg',
        'upload/catalog/view/theme/default/template/extension/mt_uni_credit/image/uni_logo_red.svg',
        'upload/catalog/view/theme/default/template/extension/mt_uni_credit/image/uni_mini_logo.png',
        'upload/catalog/view/theme/default/template/extension/mt_uni_credit/image/popup-calc-bg.png',
        'upload/system/library/mt_uni_credit/constants.php',
        'upload/system/library/mt_uni_credit/consent_gate.php',
        'upload/system/library/mt_uni_credit/config/environment.php',
        'upload/system/library/mt_uni_credit/keys/.htaccess',
        'upload/system/library/mt_uni_credit/keys/index.php',
        'upload/system/library/mt_uni_credit/secrets/.htaccess',
        'upload/system/library/mt_uni_credit/secrets/index.php',
        'upload/system/library/mt_uni_credit/secrets/smartucf-key.php',
    );
}

function package_forbidden_entries()
{
    return array(
        'upload/config/environment.php',
        'upload/catalog/view/image/mt_uni_credit/uni_logo.svg',
        'upload/catalog/view/image/mt_uni_credit/uni_logo_red.svg',
        'upload/system/library/mt_uni_credit/keys/avalon_cert.pem',
        'upload/system/library/mt_uni_credit/keys/avalon_private_key.pem',
        'upload/system/library/mt_uni_credit/recording_process_two_mailer.php',
    );
}
