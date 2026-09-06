<?php

/**
 * AUD-004 F-004-01 — private-key custody / verified mode enforcement.
 * Run: php tests/phase_aud004_f00401_key_custody_check.php
 *
 * PHP 7.3 compatible. Offline. No SmartUCF/CP network.
 */
require_once __DIR__ . '/bootstrap.php';

$failures = array();
$passes = 0;

/**
 * @param bool $condition
 * @param string $message
 * @return void
 */
function mtucF00401_assert($condition, $message)
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
if (!defined('DIR_SYSTEM')) {
    define('DIR_SYSTEM', $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR);
}
if (!defined('DIR_STORAGE')) {
    mtuc_test_define_dir_storage('mtuc-aud004-f00401');
}
if (!defined('DB_PASSWORD')) {
    define('DB_PASSWORD', 'phase4-test-installation-db-password-secret');
}

require_once DIR_SYSTEM . 'library/mt_uni_credit/bootstrap.php';

mtucF00401_assert(mtuc_phase0_network_isolation_active(), 'isolation: offline guard active');

$fixtureDir = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'certificates';
$certPem = (string) file_get_contents($fixtureDir . DIRECTORY_SEPARATOR . 'matching_cert.pem');
$keyPem = (string) file_get_contents($fixtureDir . DIRECTORY_SEPARATOR . 'matching_key.pem');
$passphrase = 'phase2-fixture-secret';
mtucF00401_assert($certPem !== '' && $keyPem !== '', 'fixtures: matching cert/key present');

/**
 * In-memory mode map simulating umask-created artifacts + verified chmod.
 */
final class Aud004ModeMap
{
    /** @var array<string, int> */
    public $modes = array();

    /** @var array<string, bool> */
    public $failChmod = array();

    /** @var array<string, bool> */
    public $failUnlink = array();

    /** @var int */
    public $defaultNewFileMode = 0644;

    /** @var int */
    public $defaultNewDirMode = 0770;

    /**
     * @param string $path
     * @param int $mode
     * @return bool
     */
    public function chmod($path, $mode)
    {
        $path = str_replace('\\', '/', (string) $path);
        foreach ($this->failChmod as $needle => $flag) {
            if ($flag && strpos($path, str_replace('\\', '/', (string) $needle)) !== false) {
                return false;
            }
        }
        $this->modes[$path] = ((int) $mode) & 0777;

        return true;
    }

    /**
     * @param string $path
     * @return int|false
     */
    public function fileperms($path)
    {
        $path = str_replace('\\', '/', (string) $path);
        if (!isset($this->modes[$path])) {
            if (is_dir($path)) {
                return 0040000 | ($this->defaultNewDirMode & 0777);
            }
            if (is_file($path)) {
                return 0100000 | ($this->defaultNewFileMode & 0777);
            }

            return false;
        }

        $bit = is_dir($path) ? 0040000 : 0100000;

        return $bit | ($this->modes[$path] & 0777);
    }

    /**
     * @param string $path
     * @param int $mode
     * @return void
     */
    public function seed($path, $mode)
    {
        $this->modes[str_replace('\\', '/', (string) $path)] = ((int) $mode) & 0777;
    }
}

/**
 * @param string $protectedRoot
 * @param Aud004ModeMap $map
 * @param callable|null $chmodFn
 * @param callable|null $unlinkFn
 * @return MtUniCreditCertificateLocalStore
 */
function mtucF00401_store($protectedRoot, Aud004ModeMap $map, $chmodFn = null, $unlinkFn = null)
{
    $paths = new MtUniCreditCertificateLocalPaths(function () use ($protectedRoot) {
        return $protectedRoot;
    });
    $enforcer = new MtUniCreditFileModeEnforcer(
        is_callable($chmodFn)
            ? $chmodFn
            : function ($path, $mode) use ($map) {
                return $map->chmod($path, $mode);
            },
        function ($path) use ($map) {
            return $map->fileperms($path);
        }
    );

    return new MtUniCreditCertificateLocalStore($paths, null, $enforcer, $unlinkFn);
}

/**
 * @param string $path
 * @param bool $allowDelete
 * @return bool
 */
function mtucF00401_unlinkSeam($path, $allowDelete)
{
    if (!$allowDelete) {
        return false;
    }
    if (is_file($path)) {
        @unlink($path);
    }

    return !is_file($path);
}

/**
 * @param string $prefix
 * @return string
 */
function mtucF00401_root($prefix)
{
    $root = MtUniCreditTestTempRoot::allocate($prefix);
    @mkdir($root . DIRECTORY_SEPARATOR . 'keys', 0770, true);
    @mkdir($root . DIRECTORY_SEPARATOR . 'secrets', 0770, true);

    return $root;
}

/**
 * @param MtUniCreditCertificateLocalStore $store
 * @param string $cert
 * @param string $key
 * @param string $pass
 * @return void
 */
function mtucF00401_seedActivePair(MtUniCreditCertificateLocalStore $store, $cert, $key, $pass)
{
    // Direct write of initial active pair (pre-existing deployment), then modes via enforcer path on refresh.
    @file_put_contents($store->certificatePath(), $cert);
    @file_put_contents($store->privateKeyPath(), $key);
}

// ---------------------------------------------------------------------------
// Enforcer unit: subset semantics + umask 0022 simulation
// ---------------------------------------------------------------------------
$mapU = new Aud004ModeMap();
$mapU->defaultNewFileMode = 0644; // umask 0022 style
$enfU = new MtUniCreditFileModeEnforcer(
    function ($p, $m) use ($mapU) {
        return $mapU->chmod($p, $m);
    },
    function ($p) use ($mapU) {
        return $mapU->fileperms($p);
    }
);
$tmpU = MtUniCreditTestTempRoot::allocate('mtuc-f00401-umask');
$backupSim = $tmpU . DIRECTORY_SEPARATOR . 'private-key-backup.pem';
file_put_contents($backupSim, 'PRIVATE');
// Simulate copy under umask 0022 → 0644 before enforcement.
$mapU->seed($backupSim, 0644);
mtucF00401_assert(($mapU->fileperms($backupSim) & 0777) === 0644, 'umask sim: backup starts as 0644');
$enfU->applyAndVerify($backupSim, MtUniCreditFileModeEnforcer::MODE_PRIVATE_KEY);
mtucF00401_assert(($mapU->fileperms($backupSim) & 0777) === 0600, 'umask sim: backup forced to 0600');

$broad = $tmpU . DIRECTORY_SEPARATOR . 'broad.pem';
file_put_contents($broad, 'x');
$mapU->seed($broad, 0666);
$threwBroad = false;
try {
    // chmod "succeeds" to 0600 in map, then verify — force chmod to leave broad bits by failing verify path:
    $mapU->failChmod[$broad] = false;
    $enfU->applyAndVerify($broad, 0600);
    mtucF00401_assert(($mapU->fileperms($broad) & 0777) === 0600, 'enforcer: 0666 tightened to 0600');
} catch (MtUniCreditCertificateSyncException $e) {
    $threwBroad = true;
}
mtucF00401_assert(!$threwBroad, 'enforcer: successful tighten does not throw');

$failPath = $tmpU . DIRECTORY_SEPARATOR . 'fail.pem';
file_put_contents($failPath, 'x');
$mapU->seed($failPath, 0644);
$mapU->failChmod['fail.pem'] = true;
$threwChmod = false;
try {
    $enfU->applyAndVerify($failPath, 0600);
} catch (MtUniCreditCertificateSyncException $e) {
    $threwChmod = true;
    mtucF00401_assert(
        $e->reason() === MtUniCreditCertificateSyncException::REASON_LOCAL_FS,
        'enforcer: chmod failure is LOCAL_FS'
    );
}
mtucF00401_assert($threwChmod, 'enforcer: chmod failure throws');

// Verify rejects extra bits when chmod lies (returns true but leaves broad mode)
$mapLie = new Aud004ModeMap();
$liePath = $tmpU . DIRECTORY_SEPARATOR . 'lie.pem';
file_put_contents($liePath, 'x');
$mapLie->seed($liePath, 0644);
$enfLie = new MtUniCreditFileModeEnforcer(
    function ($p, $m) use ($mapLie) {
        // pretend success without changing mode
        return true;
    },
    function ($p) use ($mapLie) {
        return $mapLie->fileperms($p);
    }
);
$threwLie = false;
try {
    $enfLie->applyAndVerify($liePath, 0600);
} catch (MtUniCreditCertificateSyncException $e) {
    $threwLie = true;
}
mtucF00401_assert($threwLie, 'enforcer: verify fails when effective mode stays broad');

// Directory tighten 0770 → 0700
$dirBroad = $tmpU . DIRECTORY_SEPARATOR . 'incoming-broad';
@mkdir($dirBroad, 0777, true);
$mapU->seed($dirBroad, 0770);
$enfU->ensureDirectory($dirBroad, MtUniCreditFileModeEnforcer::MODE_STAGING_DIR);
mtucF00401_assert(($mapU->fileperms($dirBroad) & 0777) === 0700, 'staging: existing 0770 tightened to 0700');

// ---------------------------------------------------------------------------
// Happy path replacePair with mode map + real files
// ---------------------------------------------------------------------------
$rootOk = mtucF00401_root('mtuc-f00401-ok');
$mapOk = new Aud004ModeMap();
$storeOk = mtucF00401_store($rootOk, $mapOk);
mtucF00401_seedActivePair($storeOk, $certPem, $keyPem, $passphrase);
$mapOk->seed($storeOk->certificatePath(), 0640);
$mapOk->seed($storeOk->privateKeyPath(), 0600);
$storeOk->replacePair($certPem, $keyPem, array('ssl_revision' => 'r1'), $passphrase);
mtucF00401_assert(is_file($storeOk->certificatePath()), 'happy: active cert present');
mtucF00401_assert(is_file($storeOk->privateKeyPath()), 'happy: active key present');
$certMode = $mapOk->fileperms($storeOk->certificatePath()) & 0777;
$keyMode = $mapOk->fileperms($storeOk->privateKeyPath()) & 0777;
mtucF00401_assert($certMode === 0640, 'happy: active cert mode 0640');
mtucF00401_assert($keyMode === 0600, 'happy: active key mode 0600');
$incomingOk = $storeOk->keysDirectory() . DIRECTORY_SEPARATOR . '.incoming';
mtucF00401_assert(!is_dir($incomingOk) || (count(glob($incomingOk . DIRECTORY_SEPARATOR . '*')) === 0), 'happy: staging cleaned');

// ---------------------------------------------------------------------------
// A. Backup private-key chmod failure → abort, old pair preserved
// ---------------------------------------------------------------------------
$rootA = mtucF00401_root('mtuc-f00401-a');
$mapA = new Aud004ModeMap();
$storeA = mtucF00401_store($rootA, $mapA);
mtucF00401_seedActivePair($storeA, $certPem, $keyPem, $passphrase);
$mapA->seed($storeA->certificatePath(), 0640);
$mapA->seed($storeA->privateKeyPath(), 0600);
$mapA->failChmod['private-key-backup-'] = true;
$beforeKeyA = hash('sha256', (string) file_get_contents($storeA->privateKeyPath()));
$beforeCertA = hash('sha256', (string) file_get_contents($storeA->certificatePath()));
$threwA = false;
try {
    $storeA->replacePair($certPem, $keyPem, array(), $passphrase);
} catch (MtUniCreditCertificateSyncException $e) {
    $threwA = true;
}
mtucF00401_assert($threwA, 'A: backup chmod failure aborts refresh');
mtucF00401_assert(
    hash('sha256', (string) file_get_contents($storeA->privateKeyPath())) === $beforeKeyA,
    'A: old active private key preserved'
);
mtucF00401_assert(
    hash('sha256', (string) file_get_contents($storeA->certificatePath())) === $beforeCertA,
    'A: old active certificate preserved'
);
mtucF00401_assert(($mapA->fileperms($storeA->privateKeyPath()) & 0777) === 0600, 'A: final private-key mode 0600');
mtucF00401_assert(($mapA->fileperms($storeA->certificatePath()) & 0777) === 0640, 'A: final certificate mode 0640');

// ---------------------------------------------------------------------------
// B. Staged private-key chmod failure
// ---------------------------------------------------------------------------
$rootB = mtucF00401_root('mtuc-f00401-b');
$mapB = new Aud004ModeMap();
$storeB = mtucF00401_store($rootB, $mapB);
mtucF00401_seedActivePair($storeB, $certPem, $keyPem, $passphrase);
$mapB->seed($storeB->certificatePath(), 0640);
$mapB->seed($storeB->privateKeyPath(), 0600);
$mapB->failChmod['private-key-'] = true; // matches staged private-key-*.pem (and backup)
// Allow backup to succeed: only fail staged candidate (not backup).
$mapB->failChmod = array('private-key-' => true);
// Problem: backup also matches private-key-. Use more specific needle after backup:
// Fail only files containing '/private-key-' that do NOT contain 'backup'
$mapB2 = new Aud004ModeMap();
$storeB = mtucF00401_store($rootB, $mapB2);
mtucF00401_seedActivePair($storeB, $certPem, $keyPem, $passphrase);
$mapB2->seed($storeB->certificatePath(), 0640);
$mapB2->seed($storeB->privateKeyPath(), 0600);
$enforcerB = new MtUniCreditFileModeEnforcer(
    function ($path, $mode) use ($mapB2) {
        $norm = str_replace('\\', '/', $path);
        if (strpos($norm, '/private-key-') !== false && strpos($norm, 'backup') === false && (($mode & 0777) === 0600)) {
            return false;
        }

        return $mapB2->chmod($path, $mode);
    },
    function ($path) use ($mapB2) {
        return $mapB2->fileperms($path);
    }
);
$storeB = new MtUniCreditCertificateLocalStore(
    new MtUniCreditCertificateLocalPaths(function () use ($rootB) {
        return $rootB;
    }),
    null,
    $enforcerB
);
$beforeKeyB = hash('sha256', (string) file_get_contents($storeB->privateKeyPath()));
$beforeCertB = hash('sha256', (string) file_get_contents($storeB->certificatePath()));
$threwB = false;
try {
    $storeB->replacePair($certPem, $keyPem, array(), $passphrase);
} catch (MtUniCreditCertificateSyncException $e) {
    $threwB = true;
}
mtucF00401_assert($threwB, 'B: staged private-key chmod failure aborts');
mtucF00401_assert(
    hash('sha256', (string) file_get_contents($storeB->privateKeyPath())) === $beforeKeyB,
    'B: old active private key preserved'
);
mtucF00401_assert(
    hash('sha256', (string) file_get_contents($storeB->certificatePath())) === $beforeCertB,
    'B: old active certificate preserved'
);
mtucF00401_assert(($mapB2->fileperms($storeB->privateKeyPath()) & 0777) === 0600, 'B: final private-key mode 0600');
mtucF00401_assert(($mapB2->fileperms($storeB->certificatePath()) & 0777) === 0640, 'B: final certificate mode 0640');

// ---------------------------------------------------------------------------
// C. Certificate chmod failure (staged cert)
// ---------------------------------------------------------------------------
$rootC = mtucF00401_root('mtuc-f00401-c');
$mapC = new Aud004ModeMap();
$enforcerC = new MtUniCreditFileModeEnforcer(
    function ($path, $mode) use ($mapC) {
        $norm = str_replace('\\', '/', $path);
        if (strpos($norm, '/certificate-') !== false && strpos($norm, 'backup') === false && (($mode & 0777) === 0640)) {
            return false;
        }

        return $mapC->chmod($path, $mode);
    },
    function ($path) use ($mapC) {
        return $mapC->fileperms($path);
    }
);
$storeC = new MtUniCreditCertificateLocalStore(
    new MtUniCreditCertificateLocalPaths(function () use ($rootC) {
        return $rootC;
    }),
    null,
    $enforcerC
);
mtucF00401_seedActivePair($storeC, $certPem, $keyPem, $passphrase);
$mapC->seed($storeC->certificatePath(), 0640);
$mapC->seed($storeC->privateKeyPath(), 0600);
$threwC = false;
try {
    $storeC->replacePair($certPem, $keyPem, array(), $passphrase);
} catch (MtUniCreditCertificateSyncException $e) {
    $threwC = true;
}
mtucF00401_assert($threwC, 'C: certificate chmod failure rejects refresh');

// ---------------------------------------------------------------------------
// D. Active private-key final mode failure after rename
// ---------------------------------------------------------------------------
$rootD = mtucF00401_root('mtuc-f00401-d');
$mapD = new Aud004ModeMap();
$activeKeyD = null;
$enforcerD = new MtUniCreditFileModeEnforcer(
    function ($path, $mode) use ($mapD, &$activeKeyD) {
        $norm = str_replace('\\', '/', $path);
        if ($activeKeyD !== null && hash_equals($norm, str_replace('\\', '/', $activeKeyD)) && (($mode & 0777) === 0600)) {
            // Allow earlier 0600 applications; fail only after promotion (file is active path and already exists as renamed).
            // Heuristic: fail when path equals final private key and staging private-key files already gone / not matching stage pattern.
            if (strpos($norm, '.incoming') === false) {
                return false;
            }
        }

        return $mapD->chmod($path, $mode);
    },
    function ($path) use ($mapD) {
        return $mapD->fileperms($path);
    }
);
$pathsD = new MtUniCreditCertificateLocalPaths(function () use ($rootD) {
    return $rootD;
});
$activeKeyD = str_replace('\\', '/', $pathsD->privateKeyPath());
$storeD = new MtUniCreditCertificateLocalStore($pathsD, null, $enforcerD);
mtucF00401_seedActivePair($storeD, $certPem, $keyPem, $passphrase);
$mapD->seed($storeD->certificatePath(), 0640);
$mapD->seed($storeD->privateKeyPath(), 0600);
$beforeKeyD = hash('sha256', (string) file_get_contents($storeD->privateKeyPath()));
$beforeCertD = hash('sha256', (string) file_get_contents($storeD->certificatePath()));
$threwD = false;
try {
    $storeD->replacePair($certPem, $keyPem, array(), $passphrase);
} catch (MtUniCreditCertificateSyncException $e) {
    $threwD = true;
}
mtucF00401_assert($threwD, 'D: active private-key final mode failure aborts/rolls back');
// After rollback, pair should not be mixed: both restored from backup or removed consistently.
$hasCertD = is_file($storeD->certificatePath());
$hasKeyD = is_file($storeD->privateKeyPath());
mtucF00401_assert($hasCertD === $hasKeyD, 'D: no mixed active pair after failure');
mtucF00401_assert(
    $hasKeyD && hash('sha256', (string) file_get_contents($storeD->privateKeyPath())) === $beforeKeyD,
    'D: old active private key hash restored'
);
mtucF00401_assert(
    $hasCertD && hash('sha256', (string) file_get_contents($storeD->certificatePath())) === $beforeCertD,
    'D: old active certificate hash restored'
);
mtucF00401_assert(($mapD->fileperms($storeD->privateKeyPath()) & 0777) === 0600, 'D: final private-key mode 0600');
mtucF00401_assert(($mapD->fileperms($storeD->certificatePath()) & 0777) === 0640, 'D: final certificate mode 0640');

// ---------------------------------------------------------------------------
// E. Existing .incoming too broad → tighten or fail
// ---------------------------------------------------------------------------
$rootE = mtucF00401_root('mtuc-f00401-e');
$mapE = new Aud004ModeMap();
$storeE = mtucF00401_store($rootE, $mapE);
$incomingE = $storeE->keysDirectory() . DIRECTORY_SEPARATOR . '.incoming';
@mkdir($incomingE, 0777, true);
$mapE->seed($incomingE, 0770);
mtucF00401_seedActivePair($storeE, $certPem, $keyPem, $passphrase);
$mapE->seed($storeE->certificatePath(), 0640);
$mapE->seed($storeE->privateKeyPath(), 0600);
$storeE->replacePair($certPem, $keyPem, array(), $passphrase);
// During replacePair ensureDirectory must have set 0700 on incoming (tracked in map even if removed).
mtucF00401_assert(
    isset($mapE->modes[str_replace('\\', '/', $incomingE)])
        && (($mapE->modes[str_replace('\\', '/', $incomingE)] & 0777) === 0700),
    'E: broad .incoming tightened to 0700 before use'
);

$rootE2 = mtucF00401_root('mtuc-f00401-e2');
$mapE2 = new Aud004ModeMap();
$enforcerE2 = new MtUniCreditFileModeEnforcer(
    function ($path, $mode) use ($mapE2) {
        $norm = str_replace('\\', '/', $path);
        if (strpos($norm, '.incoming') !== false && is_dir($path) && (($mode & 0777) === 0700)) {
            return false;
        }

        return $mapE2->chmod($path, $mode);
    },
    function ($path) use ($mapE2) {
        return $mapE2->fileperms($path);
    }
);
$storeE2 = new MtUniCreditCertificateLocalStore(
    new MtUniCreditCertificateLocalPaths(function () use ($rootE2) {
        return $rootE2;
    }),
    null,
    $enforcerE2
);
@mkdir($storeE2->keysDirectory() . DIRECTORY_SEPARATOR . '.incoming', 0777, true);
$mapE2->seed($storeE2->keysDirectory() . DIRECTORY_SEPARATOR . '.incoming', 0770);
mtucF00401_seedActivePair($storeE2, $certPem, $keyPem, $passphrase);
$threwE2 = false;
try {
    $storeE2->replacePair($certPem, $keyPem, array(), $passphrase);
} catch (MtUniCreditCertificateSyncException $e) {
    $threwE2 = true;
}
mtucF00401_assert($threwE2, 'E: fail closed when broad .incoming cannot be tightened');

// ---------------------------------------------------------------------------
// F. Production cleanup failure injection (unlink seam)
// ---------------------------------------------------------------------------

// F-A: unlink fails, tightening succeeds → protected residue <=0600
$rootFA = mtucF00401_root('mtuc-f00401-fa');
$mapFA = new Aud004ModeMap();
$storeFA = mtucF00401_store(
    $rootFA,
    $mapFA,
    null,
    function ($path) {
        $norm = str_replace('\\', '/', (string) $path);
        if (strpos($norm, 'private-key-backup-') !== false) {
            return mtucF00401_unlinkSeam($path, false);
        }

        return mtucF00401_unlinkSeam($path, true);
    }
);
mtucF00401_seedActivePair($storeFA, $certPem, $keyPem, $passphrase);
$mapFA->seed($storeFA->certificatePath(), 0640);
$mapFA->seed($storeFA->privateKeyPath(), 0600);
$activeCertBeforeFA = hash('sha256', $certPem);
$activeKeyBeforeFA = hash('sha256', $keyPem);
$threwFA = false;
try {
    $storeFA->replacePair($certPem, $keyPem, array('ssl_revision' => 'fa'), $passphrase);
} catch (MtUniCreditCertificateSyncException $e) {
    $threwFA = true;
}
mtucF00401_assert(!$threwFA, 'F-A: unlink fail + tighten success does not throw');
$incomingFA = $storeFA->keysDirectory() . DIRECTORY_SEPARATOR . '.incoming';
$backupResidueFA = glob($incomingFA . DIRECTORY_SEPARATOR . 'private-key-backup-*.pem');
mtucF00401_assert(is_array($backupResidueFA) && count($backupResidueFA) === 1, 'F-A: private-key backup residue remains');
$residueFA = $backupResidueFA[0];
mtucF00401_assert(($mapFA->fileperms($residueFA) & 0777) === 0600, 'F-A: residue mode 0600');
mtucF00401_assert(is_dir($incomingFA), 'F-A: staging dir retained with residue');
mtucF00401_assert(($mapFA->fileperms($incomingFA) & 0777) === 0700, 'F-A: staging dir mode 0700');
mtucF00401_assert(
    hash('sha256', (string) file_get_contents($storeFA->certificatePath())) === $activeCertBeforeFA,
    'F-A: active certificate hash intact'
);
mtucF00401_assert(
    hash('sha256', (string) file_get_contents($storeFA->privateKeyPath())) === $activeKeyBeforeFA,
    'F-A: active private-key hash intact'
);
mtucF00401_assert(($mapFA->fileperms($storeFA->privateKeyPath()) & 0777) === 0600, 'F-A: active key mode 0600');
mtucF00401_assert(($mapFA->fileperms($storeFA->certificatePath()) & 0777) === 0640, 'F-A: active cert mode 0640');

// F-B: tightening fails, unlink succeeds → no residue
$rootFB = mtucF00401_root('mtuc-f00401-fb');
$mapFB = new Aud004ModeMap();
$backupChmodFB = 0;
$storeFB = mtucF00401_store(
    $rootFB,
    $mapFB,
    function ($path, $mode) use ($mapFB, &$backupChmodFB) {
        $norm = str_replace('\\', '/', (string) $path);
        if (strpos($norm, 'private-key-backup-') !== false && (((int) $mode) & 0777) === 0600) {
            $backupChmodFB++;
            if ($backupChmodFB > 1) {
                return false;
            }
        }

        return $mapFB->chmod($path, $mode);
    }
);
mtucF00401_seedActivePair($storeFB, $certPem, $keyPem, $passphrase);
$mapFB->seed($storeFB->certificatePath(), 0640);
$mapFB->seed($storeFB->privateKeyPath(), 0600);
$threwFB = false;
try {
    $storeFB->replacePair($certPem, $keyPem, array('ssl_revision' => 'fb'), $passphrase);
} catch (MtUniCreditCertificateSyncException $e) {
    $threwFB = true;
}
mtucF00401_assert(!$threwFB, 'F-B: tighten fail + unlink success does not throw');
$incomingFB = $storeFB->keysDirectory() . DIRECTORY_SEPARATOR . '.incoming';
mtucF00401_assert(
    !is_dir($incomingFB) || count(glob($incomingFB . DIRECTORY_SEPARATOR . 'private-key-backup-*.pem')) === 0,
    'F-B: no private-key backup residue'
);
mtucF00401_assert(
    hash('sha256', (string) file_get_contents($storeFB->privateKeyPath())) === hash('sha256', $keyPem),
    'F-B: active private-key hash intact'
);
mtucF00401_assert(
    hash('sha256', (string) file_get_contents($storeFB->certificatePath())) === hash('sha256', $certPem),
    'F-B: active certificate hash intact'
);

// F-C: tightening fails AND unlink fails → controlled LOCAL_FS (central regression)
$rootFC = mtucF00401_root('mtuc-f00401-fc');
$mapFC = new Aud004ModeMap();
$backupChmodFC = 0;
$storeFC = mtucF00401_store(
    $rootFC,
    $mapFC,
    function ($path, $mode) use ($mapFC, &$backupChmodFC) {
        $norm = str_replace('\\', '/', (string) $path);
        if (strpos($norm, 'private-key-backup-') !== false && (((int) $mode) & 0777) === 0600) {
            $backupChmodFC++;
            if ($backupChmodFC > 1) {
                // Simulate cleanup being unable to keep/restore secure bits (residue goes broad).
                $mapFC->seed($path, 0644);

                return false;
            }
        }

        return $mapFC->chmod($path, $mode);
    },
    function ($path) {
        $norm = str_replace('\\', '/', (string) $path);
        if (strpos($norm, 'private-key-backup-') !== false) {
            return mtucF00401_unlinkSeam($path, false);
        }

        return mtucF00401_unlinkSeam($path, true);
    }
);
mtucF00401_seedActivePair($storeFC, $certPem, $keyPem, $passphrase);
$mapFC->seed($storeFC->certificatePath(), 0640);
$mapFC->seed($storeFC->privateKeyPath(), 0600);
$threwFC = false;
$reasonFC = null;
try {
    $storeFC->replacePair($certPem, $keyPem, array('ssl_revision' => 'fc'), $passphrase);
} catch (MtUniCreditCertificateSyncException $e) {
    $threwFC = true;
    $reasonFC = $e->reason();
}
mtucF00401_assert($threwFC, 'F-C: tighten fail + unlink fail surfaces failure');
mtucF00401_assert(
    $reasonFC === MtUniCreditCertificateSyncException::REASON_LOCAL_FS,
    'F-C: failure reason is LOCAL_FS'
);
$incomingFC = $storeFC->keysDirectory() . DIRECTORY_SEPARATOR . '.incoming';
$backupResidueFC = glob($incomingFC . DIRECTORY_SEPARATOR . 'private-key-backup-*.pem');
mtucF00401_assert(is_array($backupResidueFC) && count($backupResidueFC) === 1, 'F-C: insecure residue still present');
$residueFC = $backupResidueFC[0];
mtucF00401_assert(($mapFC->fileperms($residueFC) & 0777) === 0644, 'F-C: residue remains broader than 0600');
mtucF00401_assert(
    hash('sha256', (string) file_get_contents($storeFC->privateKeyPath())) === hash('sha256', $keyPem),
    'F-C: active private-key still published (not rolled back for cleanup)'
);
mtucF00401_assert(
    hash('sha256', (string) file_get_contents($storeFC->certificatePath())) === hash('sha256', $certPem),
    'F-C: active certificate still published'
);
mtucF00401_assert(($mapFC->fileperms($storeFC->privateKeyPath()) & 0777) === 0600, 'F-C: active key mode 0600');
mtucF00401_assert(($mapFC->fileperms($storeFC->certificatePath()) & 0777) === 0640, 'F-C: active cert mode 0640');

// F-D: first tighten fails, unlink fails, second tighten succeeds → protected residue
$rootFD = mtucF00401_root('mtuc-f00401-fd');
$mapFD = new Aud004ModeMap();
$backupChmodFD = 0;
$storeFD = mtucF00401_store(
    $rootFD,
    $mapFD,
    function ($path, $mode) use ($mapFD, &$backupChmodFD) {
        $norm = str_replace('\\', '/', (string) $path);
        if (strpos($norm, 'private-key-backup-') !== false && (((int) $mode) & 0777) === 0600) {
            $backupChmodFD++;
            if ($backupChmodFD === 2) {
                return false;
            }
        }

        return $mapFD->chmod($path, $mode);
    },
    function ($path) {
        $norm = str_replace('\\', '/', (string) $path);
        if (strpos($norm, 'private-key-backup-') !== false) {
            return mtucF00401_unlinkSeam($path, false);
        }

        return mtucF00401_unlinkSeam($path, true);
    }
);
mtucF00401_seedActivePair($storeFD, $certPem, $keyPem, $passphrase);
$mapFD->seed($storeFD->certificatePath(), 0640);
$mapFD->seed($storeFD->privateKeyPath(), 0600);
$threwFD = false;
try {
    $storeFD->replacePair($certPem, $keyPem, array('ssl_revision' => 'fd'), $passphrase);
} catch (MtUniCreditCertificateSyncException $e) {
    $threwFD = true;
}
mtucF00401_assert(!$threwFD, 'F-D: retry tighten success accepts protected residue');
$incomingFD = $storeFD->keysDirectory() . DIRECTORY_SEPARATOR . '.incoming';
$backupResidueFD = glob($incomingFD . DIRECTORY_SEPARATOR . 'private-key-backup-*.pem');
mtucF00401_assert(is_array($backupResidueFD) && count($backupResidueFD) === 1, 'F-D: residue remains');
mtucF00401_assert(($mapFD->fileperms($backupResidueFD[0]) & 0777) === 0600, 'F-D: residue mode 0600 after retry');
mtucF00401_assert(($mapFD->fileperms($incomingFD) & 0777) === 0700, 'F-D: staging dir mode 0700');
mtucF00401_assert(
    hash('sha256', (string) file_get_contents($storeFD->privateKeyPath())) === hash('sha256', $keyPem),
    'F-D: active private-key hash intact'
);

// F-E: certificate backup residue equivalent (<=0640 or deleted)
$rootFE = mtucF00401_root('mtuc-f00401-fe');
$mapFE = new Aud004ModeMap();
$storeFE = mtucF00401_store(
    $rootFE,
    $mapFE,
    null,
    function ($path) {
        $norm = str_replace('\\', '/', (string) $path);
        if (strpos($norm, 'certificate-backup-') !== false) {
            return mtucF00401_unlinkSeam($path, false);
        }

        return mtucF00401_unlinkSeam($path, true);
    }
);
mtucF00401_seedActivePair($storeFE, $certPem, $keyPem, $passphrase);
$mapFE->seed($storeFE->certificatePath(), 0640);
$mapFE->seed($storeFE->privateKeyPath(), 0600);
$threwFE = false;
try {
    $storeFE->replacePair($certPem, $keyPem, array('ssl_revision' => 'fe'), $passphrase);
} catch (MtUniCreditCertificateSyncException $e) {
    $threwFE = true;
}
mtucF00401_assert(!$threwFE, 'F-E: certificate residue with secure mode does not throw');
$incomingFE = $storeFE->keysDirectory() . DIRECTORY_SEPARATOR . '.incoming';
$certResidueFE = glob($incomingFE . DIRECTORY_SEPARATOR . 'certificate-backup-*.pem');
mtucF00401_assert(is_array($certResidueFE) && count($certResidueFE) === 1, 'F-E: certificate backup residue remains');
mtucF00401_assert(($mapFE->fileperms($certResidueFE[0]) & 0777) === 0640, 'F-E: certificate residue mode 0640');
mtucF00401_assert(($mapFE->fileperms($incomingFE) & 0777) === 0700, 'F-E: staging dir mode 0700');

// ---------------------------------------------------------------------------
// Lease modes
// ---------------------------------------------------------------------------
$rootL = mtucF00401_root('mtuc-f00401-lease');
$mapL = new Aud004ModeMap();
$storeL = mtucF00401_store($rootL, $mapL);
mtucF00401_seedActivePair($storeL, $certPem, $keyPem, $passphrase);
$mapL->seed($storeL->certificatePath(), 0640);
$mapL->seed($storeL->privateKeyPath(), 0600);
$lease = $storeL->createConsumerPairLease($passphrase);
mtucF00401_assert(is_file($lease->certificatePath()) && is_file($lease->privateKeyPath()), 'lease: files created');
mtucF00401_assert(($mapL->fileperms($lease->privateKeyPath()) & 0777) === 0600, 'lease: private key 0600');
mtucF00401_assert(($mapL->fileperms($lease->certificatePath()) & 0777) === 0600, 'lease: cert 0600');
$leaseDir = dirname($lease->certificatePath());
mtucF00401_assert(($mapL->fileperms($leaseDir) & 0777) === 0700, 'lease: directory 0700');
$lease->release();

// ---------------------------------------------------------------------------
// Structural markers
// ---------------------------------------------------------------------------
$storeSrc = (string) file_get_contents(DIR_SYSTEM . 'library/mt_uni_credit/certificate_local_store.php');
$enfSrc = (string) file_get_contents(DIR_SYSTEM . 'library/mt_uni_credit/file_mode_enforcer.php');
mtucF00401_assert(strpos($enfSrc, 'applyAndVerify') !== false, 'structure: FileModeEnforcer present');
mtucF00401_assert(strpos($storeSrc, 'MODE_STAGING_DIR') !== false, 'structure: staging uses MODE_STAGING_DIR');
mtucF00401_assert(strpos($storeSrc, 'applyAndVerify($backupKey') !== false, 'structure: backup key mode verified');
mtucF00401_assert(strpos($storeSrc, '@chmod(') === false, 'structure: no suppressed bare @chmod in store');
mtucF00401_assert(strpos($storeSrc, 'unlinkPath') !== false, 'structure: unlink seam present');
mtucF00401_assert(
    strpos($storeSrc, 'Sensitive certificate staging residue could not be secured.') !== false,
    'structure: unsecured residue raises LOCAL_FS'
);

echo PHP_EOL;
if ($failures) {
    echo 'AUD-004 F-004-01: FAIL (' . count($failures) . ' failures, ' . $passes . ' passes)' . PHP_EOL;
    foreach ($failures as $f) {
        echo '  - ' . $f . PHP_EOL;
    }
    exit(1);
}

echo 'AUD-004 F-004-01: PASS (' . $passes . ' passes)' . PHP_EOL;
exit(0);
