<?php
/** WSL/Linux release builder. Run: php scripts/package.php [--output-dir=/absolute/existing/directory] */
require_once __DIR__ . '/package_policy.php';

function package_build($root, $outputDir)
{
    if (PHP_VERSION_ID < 80000 || !class_exists('ZipArchive') || !function_exists('simplexml_load_string') || !function_exists('mb_strtolower')) {
        throw new RuntimeException('PHP CLI 8.0+ with zip, SimpleXML and mbstring is required');
    }
    // Complete preflight precedes all filesystem writes.
    $sources = package_sources($root);
    $xmlBytes = file_get_contents($root . '/install.xml');
    if (stripos($xmlBytes, '<!DOCTYPE') !== false || stripos($xmlBytes, '<!ENTITY') !== false) {
        throw new RuntimeException('DTD/entities forbidden in install.xml');
    }
    $xml = simplexml_load_string($xmlBytes, 'SimpleXMLElement', LIBXML_NONET);
    $version = $xml === false ? '' : trim((string) $xml->version);
    if ($xml === false || $xml->getName() !== 'modification' || !preg_match('/^\d+\.\d+\.\d+$/D', $version)) {
        throw new RuntimeException('Invalid install.xml version');
    }
    package_safe_absolute($outputDir);
    if ($outputDir === $root || strpos($outputDir . '/', $root . '/upload/') === 0) {
        throw new RuntimeException('Output may not overlap package sources');
    }
    // Only the default repository dist may be created; custom disposable directories must exist.
    if (!is_dir($outputDir)) {
        if ($outputDir !== $root . '/dist' || file_exists($outputDir) || !mkdir($outputDir, 0755)) {
            throw new RuntimeException('Output directory must exist');
        }
    }
    $target = $outputDir . '/oc3_uni_' . $version . '.ocmod.zip';
    if (file_exists($target) || is_link($target)) {
        throw new RuntimeException('Refusing to overwrite existing/frozen artifact: ' . $target);
    }
    return package_publish($target, $sources);
}

/** Internal no-clobber transaction; source manifest must come from package_sources(). */
function package_publish($target, array $sources)
{
    package_safe_absolute($target);
    if (file_exists($target) || is_link($target)) {
        throw new RuntimeException('Refusing to overwrite existing/frozen artifact');
    }
    $temp = dirname($target) . '/.package-' . bin2hex(random_bytes(16)) . '.tmp';
    $handle = fopen($temp, 'x+b');
    if ($handle === false) {
        throw new RuntimeException('Cannot reserve temporary output');
    }
    fclose($handle);
    try {
        $zip = new ZipArchive();
        if ($zip->open($temp, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create ZIP');
        }
        try {
            foreach ($sources as $name => $source) {
                package_safe_absolute($source['path']);
                if (!is_file($source['path']) || realpath($source['path']) !== $source['path']) {
                    throw new RuntimeException('Source changed: ' . $name);
                }
                $bytes = file_get_contents($source['path']);
                if ($bytes === false || hash('sha256', $bytes) !== $source['sha256']) {
                    throw new RuntimeException('Source bytes changed: ' . $name);
                }
                if (!$zip->addFromString($name, $bytes)
                    || !$zip->setCompressionName($name, ZipArchive::CM_DEFLATE, 9)
                    || !$zip->setMtimeName($name, 315532800)
                    || !$zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0100644 << 16)) {
                    throw new RuntimeException('Cannot write ZIP entry: ' . $name);
                }
            }
        } finally {
            if (!$zip->close()) {
                throw new RuntimeException('Cannot finalize ZIP');
            }
        }
        package_validate_zip($temp, $sources);
        $hash = hash_file('sha256', $temp);
        if ($hash === false) {
            throw new RuntimeException('Cannot hash validated ZIP');
        }
        package_safe_absolute(dirname($target));
        // Atomic no-clobber publication on the same filesystem; never rename over a release.
        if (!link($temp, $target)) {
            throw new RuntimeException('Cannot publish without overwriting target');
        }
        return array('path' => $target, 'sha256' => $hash, 'files' => count($sources));
    } finally {
        if (is_file($temp) && !is_link($temp)) {
            unlink($temp);
        }
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    try {
        $outputDir = dirname(__DIR__) . '/dist';
        if (count($argv) > 2 || (isset($argv[1]) && strpos($argv[1], '--output-dir=') !== 0)) {
            throw new RuntimeException('Usage: php scripts/package.php [--output-dir=/absolute/existing/directory]');
        }
        if (isset($argv[1])) {
            $outputDir = substr($argv[1], strlen('--output-dir='));
        }
        $result = package_build(dirname(__DIR__), $outputDir);
        echo 'Package: ' . $result['path'] . PHP_EOL . 'SHA256: ' . $result['sha256'] . PHP_EOL . 'Package policy validation: PASS' . PHP_EOL;
    } catch (Throwable $error) {
        fwrite(STDERR, 'Packaging failed: ' . $error->getMessage() . PHP_EOL);
        exit(1);
    }
}
