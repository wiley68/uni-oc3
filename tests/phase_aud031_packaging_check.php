<?php
/** Offline packaging/security regressions. All artifacts live in a unique repository-owned temp tree. */
require_once dirname(__DIR__) . '/scripts/package.php';
$repo = dirname(__DIR__);
$base = $repo . '/.tmp-authority';
if (is_link($base)) {
    throw new RuntimeException('Unsafe test temp parent');
}
if (!is_dir($base)) {
    mkdir($base, 0700);
}
$temp = $base . '/packaging-' . bin2hex(random_bytes(12));
mkdir($temp, 0700);
$passes = 0;
function pkg_assert($ok, $label)
{
    global $passes;
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    $passes++;
    echo 'PASS ' . $label . PHP_EOL;
}
function pkg_reject($call, $label)
{
    try {
        $call();
    } catch (Throwable $error) {
        pkg_assert(true, $label);
        return;
    }
    pkg_assert(false, $label);
}
function pkg_remove($path)
{
    if (is_dir($path) && !is_link($path)) {
        foreach (new DirectoryIterator($path) as $entry) {
            if (!$entry->isDot()) {
                pkg_remove($entry->getPathname());
            }
        }
        rmdir($path);
    } else {
        unlink($path);
    }
}
function pkg_mutated_zip($good, $dest, $mutation)
{
    copy($good, $dest);
    $zip = new ZipArchive();
    if ($zip->open($dest) !== true) {
        throw new RuntimeException('Cannot open mutation ZIP');
    }
    $mutation($zip);
    $zip->close();
}
function pkg_build_mtime($path, $start, $end)
{
    $zip = new ZipArchive();
    pkg_assert($zip->open($path, ZipArchive::CHECKCONS) === true, 'ZIP opens with consistency checks');
    $timestamps = array();
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = $zip->statIndex($i);
        $bytes = $zip->getFromIndex($i);
        if ($entry === false || $bytes === false || hash('crc32b', $bytes) !== sprintf('%08x', $entry['crc'])) {
            throw new RuntimeException('ZIP entry is unreadable or failed CRC');
        }
        $timestamps[$entry['mtime']] = true;
    }
    $zip->close();
    pkg_assert(count($timestamps) === 1, 'all readable entries share one timestamp and pass CRC');
    $mtime = array_key_first($timestamps);
    pkg_assert($mtime >= $start - $start % 2 && $mtime <= $end
        && $mtime % 2 === 0 && gmdate('Y', $mtime) !== '1980',
        'current UTC DOS timestamp ' . gmdate('c', $mtime));

    return $mtime;
}

function pkg_extract_move($path, $staging, $entry, $destination)
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true || !$zip->extractTo($staging, $entry) || !$zip->close()) {
        throw new RuntimeException('Cannot extract timestamp fixture');
    }
    $staged = $staging . '/' . $entry;
    $stagedMtime = filemtime($staged);
    if ($stagedMtime === false || !rename($staged, $destination)) {
        throw new RuntimeException('Cannot move timestamp fixture');
    }
    clearstatcache(true, $destination);

    return array($stagedMtime, filemtime($destination));
}

$originalTimezone = getenv('TZ');
try {
    mkdir($temp . '/output');
    $sources = package_sources($repo);
    // Read the resulting DOS values in UTC after building with a non-UTC caller.
    putenv('TZ=Europe/Sofia');
    $start = time();
    $result = package_build($repo, $temp . '/output');
    $end = time();
    pkg_assert(getenv('TZ') === 'Europe/Sofia', 'builder restores caller timezone');
    pkg_assert(is_file($result['path']), 'full production source build into disposable output');
    package_validate_zip($result['path'], $sources);
    pkg_assert(hash_file('sha256', $result['path']) === $result['sha256'], 'full manifest and every entry SHA256 verified');
    echo 'Verified payload: ' . $result['files'] . ' files; SHA256: ' . $result['sha256'] . PHP_EOL;
    $zip = new ZipArchive();
    $zip->open($result['path']);
    $names = array();
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }
    $zip->close();
    pkg_assert($names === array_keys($sources), 'exact case-preserving sorted file manifest');
    pkg_assert(isset($sources['upload/system/library/mt_uni_credit/keys/.htaccess'], $sources['upload/system/library/mt_uni_credit/secrets/.htaccess']), 'hidden protection files included');
    mkdir($temp . '/cli');
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($repo . '/scripts/package.php')
        . ' ' . escapeshellarg('--output-dir=' . $temp . '/cli');
    $cliStart = time();
    exec($command . ' 2>&1', $cliOutput, $cliCode);
    $cliEnd = time();
    $filename = 'oc3_uni_2.0.5.ocmod.zip';
    $cliPath = $temp . '/cli/' . $filename;
    pkg_assert(basename($result['path']) === $filename, 'production filename and version preserved');
    pkg_assert($cliCode === 0 && is_file($cliPath)
        && in_array('Package: ' . $cliPath, $cliOutput, true)
        && in_array('SHA256: ' . hash_file('sha256', $cliPath), $cliOutput, true),
        'PHP CLI reports the path and SHA256 of its own archive');
    package_validate_zip($cliPath, $sources);
    putenv('TZ=UTC');
    pkg_build_mtime($result['path'], $start, $end);
    pkg_build_mtime($cliPath, $cliStart, $cliEnd);
    exec($command . ' 2>&1', $refusedOutput, $refusedCode);
    pkg_assert($refusedCode !== 0, 'PHP CLI frozen-target refusal exits nonzero');
    mkdir($temp . '/second');
    pkg_reject(function () use ($repo, $temp) { package_build($repo, $temp . '/output'); }, 'existing frozen target refused');
    pkg_assert(hash_file('sha256', $result['path']) === $result['sha256'], 'existing artifact bytes unchanged');

    foreach (array('../evil', '/absolute', 'C:\\evil', 'upload/../evil', 'upload/./evil', 'upload//evil', 'upload/x\\evil', 'upload/x/', 'docs/a',
        'upload/.env', 'upload/a/.env.local', 'upload/.vscode/a', 'upload/a.code-workspace', 'upload/a.bak', 'upload/a.local.php',
        'upload/tests/a', 'upload/config/environment.php', 'upload/catalog/view/image/a.svg', 'upload/a.pem', 'upload/a.key', 'upload/a.p12', 'upload/a.pfx', 'upload/a.jks') as $bad) {
        pkg_reject(function () use ($bad) { package_path_check($bad); }, 'path policy ' . $bad);
    }
    foreach (array('force_test_cp_create_422', 'X-UniPayment-Test-Failure', 'UNIPAYMENT_ENABLE_TEST_FAILURES', 'ShopApiTestFailure',
        '-----BEGIN PRIVATE KEY-----', '-----BEGIN RSA PRIVATE KEY-----', '-----BEGIN EC PRIVATE KEY-----', '-----BEGIN OPENSSH PRIVATE KEY-----') as $bad) {
        pkg_reject(function () use ($bad) { package_content_check('upload/a', $bad); }, 'content sentinel ' . $bad);
    }
    package_content_check('upload/a', 'password secret token Authorization');
    pkg_assert(true, 'benign security vocabulary allowed');

    $fixture = $temp . '/fixture';
    mkdir($fixture);
    file_put_contents($fixture . '/install.xml', '<modification><version>2.0.3</version></modification>');
    mkdir($fixture . '/upload');
    // Build a complete fixture so negative preflight tests cannot pass merely due to missing required files.
    foreach (package_required_entries() as $name) {
        if ($name === 'install.xml') { continue; }
        $parent = dirname($fixture . '/' . $name);
        if (!is_dir($parent)) { mkdir($parent, 0700, true); }
        file_put_contents($fixture . '/' . $name, 'safe');
    }
    package_sources($fixture);
    pkg_assert(true, 'complete independent fixture accepted');

    $entry = 'upload/build-timestamp-probe.php';
    $firstBytes = "<?php return 'first';\n";
    $secondBytes = "<?php return 'second';\n";
    file_put_contents($fixture . '/' . $entry, $firstBytes);
    mkdir($temp . '/first-fixture');
    $firstStart = time();
    $first = package_build($fixture, $temp . '/first-fixture');
    $firstMtime = pkg_build_mtime($first['path'], $firstStart, time());
    package_validate_zip($first['path'], package_sources($fixture));
    mkdir($temp . '/installed');
    $destination = $temp . '/installed/probe.php';
    list($firstExtracted, $firstInstalled) = pkg_extract_move($first['path'], $temp . '/extract-first', $entry, $destination);
    pkg_assert(file_get_contents($destination) === $firstBytes, 'first extracted PHP contains first source bytes');

    file_put_contents($fixture . '/' . $entry, $secondBytes);
    $deadline = hrtime(true) + 5000000000;
    while (time() < $firstMtime + 2 && hrtime(true) < $deadline) {
        usleep(20000);
    }
    $secondStart = time();
    pkg_assert($secondStart >= $firstMtime + 2, 'second build starts in a later DOS interval');
    $second = package_build($fixture, $temp . '/second');
    $secondMtime = pkg_build_mtime($second['path'], $secondStart, time());
    package_validate_zip($second['path'], package_sources($fixture));
    list($secondExtracted, $secondInstalled) = pkg_extract_move($second['path'], $temp . '/extract-second', $entry, $destination);
    pkg_assert($secondMtime > $firstMtime && file_get_contents($destination) === $secondBytes,
        'later ZIP has a different timestamp and installs the changed PHP bytes');
    pkg_assert($firstExtracted === $firstMtime && $firstInstalled === $firstMtime
        && $secondExtracted === $secondMtime && $secondInstalled === $secondMtime,
        'extract and rename preserve distinct mtimes: staged ' . gmdate('c', $firstExtracted)
        . ' -> installed ' . gmdate('c', $firstInstalled) . '; staged ' . gmdate('c', $secondExtracted)
        . ' -> installed ' . gmdate('c', $secondInstalled));

    mkdir($temp . '/negative-output');
    foreach (array('.env' => 'safe', 'private.txt' => '-----BEGIN PRIVATE KEY-----', 'debug' => 'ShopApiTestFailure') as $leaf => $bytes) {
        file_put_contents($fixture . '/upload/' . $leaf, $bytes);
        pkg_reject(function () use ($fixture, $temp) { package_build($fixture, $temp . '/negative-output'); }, 'preflight rejects before writing ' . $leaf);
        unlink($fixture . '/upload/' . $leaf);
    }
    pkg_assert(scandir($temp . '/negative-output') === array('.', '..'), 'negative source preflight writes no output');
    $hidden = $fixture . '/upload/system/library/mt_uni_credit/keys/.htaccess';
    unlink($hidden);
    pkg_reject(function () use ($fixture) { package_sources($fixture); }, 'required hidden file omission rejected');
    file_put_contents($hidden, 'safe');
    symlink($repo . '/install.xml', $fixture . '/upload/link');
    pkg_reject(function () use ($fixture) { package_sources($fixture); }, 'complete fixture outside-file symlink rejected');
    unlink($fixture . '/upload/link');
    file_put_contents($fixture . '/upload/A.php', 'safe');
    file_put_contents($fixture . '/upload/a.php', 'safe');
    pkg_reject(function () use ($fixture) { package_sources($fixture); }, 'complete fixture case collision rejected');
    unlink($fixture . '/upload/A.php'); unlink($fixture . '/upload/a.php');
    file_put_contents($fixture . '/upload/Ä.php', 'safe');
    file_put_contents($fixture . '/upload/ä.php', 'safe');
    pkg_reject(function () use ($fixture) { package_sources($fixture); }, 'Unicode source case collision rejected');
    unlink($fixture . '/upload/Ä.php'); unlink($fixture . '/upload/ä.php');
    posix_mkfifo($fixture . '/upload/pipe', 0600);
    pkg_reject(function () use ($fixture) { package_sources($fixture); }, 'complete fixture FIFO rejected without reading');
    unlink($fixture . '/upload/pipe');
    unlink($fixture . '/install.xml');
    symlink($repo . '/install.xml', $fixture . '/install.xml');
    pkg_reject(function () use ($fixture) { package_sources($fixture); }, 'install.xml symlink rejected');

    $mutations = array(
        'missing entry' => function ($z) { $z->deleteName('install.xml'); },
        'extra entry' => function ($z) { $z->addFromString('upload/extra.txt', 'safe'); },
        'wrong bytes' => function ($z) { $z->addFromString('install.xml', 'corrupted'); },
        'traversal' => function ($z) { $z->addFromString('../evil', 'evil'); },
        'traversal directory marker' => function ($z) { $z->addEmptyDir('../evil'); },
        'wrong root' => function ($z) { $z->addFromString('README', 'extra'); },
        'case collision' => function ($z) { $z->addFromString('INSTALL.XML', 'evil'); },
        'ZIP symlink' => function ($z) { $z->setExternalAttributesName('install.xml', ZipArchive::OPSYS_UNIX, 0120777 << 16); },
    );
    foreach ($mutations as $label => $mutate) {
        $badZip = $temp . '/bad.zip';
        pkg_mutated_zip($result['path'], $badZip, $mutate);
        pkg_reject(function () use ($badZip, $sources) { package_validate_zip($badZip, $sources); }, 'real ZIP rejects ' . $label);
    }
    foreach (array('ShopApiTestFailure', '-----BEGIN PRIVATE KEY-----') as $payload) {
        $badZip = $temp . '/bad.zip';
        pkg_mutated_zip($result['path'], $badZip, function ($z) use ($payload) { $z->addFromString('install.xml', $payload); });
        $maliciousSources = $sources;
        $maliciousSources['install.xml']['sha256'] = hash('sha256', $payload);
        pkg_reject(function () use ($badZip, $maliciousSources) { package_validate_zip($badZip, $maliciousSources); }, 'archive content scan rejects even matching malicious bytes');
    }
    // Raw ZIP records permit actual duplicate names, which ZipArchive::addFromString replaces.
    $name = 'upload/duplicate.txt';
    $local = $central = '';
    for ($i = 0; $i < 2; $i++) {
        $offset = strlen($local);
        $local .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0, 0, 0, 0, strlen($name), 0) . $name;
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 0, 0, 0, 0, strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
    }
    file_put_contents($temp . '/duplicate.zip', $local . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, 2, 2, strlen($central), strlen($local), 0));
    $duplicateSources = $sources;
    $duplicateSources[$name] = array('sha256' => hash('sha256', ''));
    pkg_reject(function () use ($temp, $duplicateSources) { package_validate_zip($temp . '/duplicate.zip', $duplicateSources); }, 'actual duplicate ZIP entries rejected');
    $changed = $sources;
    $changed['install.xml']['sha256'] = str_repeat('0', 64);
    pkg_reject(function () use ($temp, $changed) { package_publish($temp . '/output/failed.zip', $changed); }, 'post-preflight source change fails transaction');
    pkg_assert(!file_exists($temp . '/output/failed.zip') && glob($temp . '/output/.package-*') === array(), 'failed transaction never publishes and removes own temporary file');
    $incomplete = $sources;
    unset($incomplete['install.xml']);
    pkg_reject(function () use ($temp, $incomplete) { package_publish($temp . '/output/invalid.zip', $incomplete); }, 'post-build required manifest validation fails transaction');
    pkg_assert(!file_exists($temp . '/output/invalid.zip') && glob($temp . '/output/.package-*') === array(), 'failed output validation leaves no published or temporary artifact');
    file_put_contents($temp . '/bad.zip', 'not a ZIP');
    pkg_reject(function () use ($temp, $sources) { package_validate_zip($temp . '/bad.zip', $sources); }, 'malformed ZIP rejected');
    symlink($temp . '/output', $temp . '/linked-output');
    pkg_reject(function () use ($repo, $temp) { package_build($repo, $temp . '/linked-output'); }, 'output symlink rejected');
    pkg_reject(function () use ($repo, $temp) { package_build($repo, $temp . '/output/../second'); }, 'output traversal rejected');
    pkg_reject(function () use ($repo) { package_build($repo, $repo . '/upload'); }, 'source-overlapping output rejected');
    pkg_reject(function () use ($repo, $temp) { package_build($repo, $temp . '/absent'); }, 'unowned output directory not created');
    file_put_contents($temp . '/output/unrelated', 'preserve');
    pkg_reject(function () use ($repo, $temp) { package_build($repo, $temp . '/output'); }, 'failed invocation cleanup bounded');
    pkg_assert(file_get_contents($temp . '/output/unrelated') === 'preserve' && glob($temp . '/output/.package-*') === array(), 'unrelated files preserved and no temporary ZIP leaked');
    echo 'Packaging regressions: ' . $passes . ' PASS' . PHP_EOL;
} finally {
    putenv($originalTimezone === false ? 'TZ' : 'TZ=' . $originalTimezone);
    pkg_remove($temp);
}
