<?php

/**
 * Unique temporary roots for offline tests (AUD-033 / F-033-03).
 * PHP 7.3 compatible. Cleanup is bounded to allocated roots only.
 */
final class MtUniCreditTestTempRoot
{
    /** @var array<int, string> */
    private static $roots = array();

    /** @var bool */
    private static $shutdownRegistered = false;

    /**
     * @param string $prefix
     * @return string Absolute directory path (no trailing separator)
     */
    public static function allocate($prefix = 'mtuc-test')
    {
        $prefix = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $prefix);
        if ($prefix === '') {
            $prefix = 'mtuc-test';
        }

        $base = realpath(sys_get_temp_dir());
        if ($base === false || $base === '') {
            throw new RuntimeException('MTUC test temp: sys_get_temp_dir() is unavailable.');
        }

        try {
            $token = bin2hex(random_bytes(8));
        } catch (Exception $ignored) {
            $token = sha1(uniqid($prefix, true));
        }

        $name = $prefix . '-' . $token . '-' . getmypid();
        $path = $base . DIRECTORY_SEPARATOR . $name;
        if (!@mkdir($path, 0770, true) && !is_dir($path)) {
            throw new RuntimeException('MTUC test temp: failed to create ' . $path);
        }

        self::$roots[] = $path;
        self::registerShutdownCleanup();

        return $path;
    }

    /**
     * Storage root for DIR_STORAGE: contains mt_uni_credit/ subdirectory.
     *
     * @param string $prefix
     * @return string Absolute storage root (no trailing separator)
     */
    public static function allocateModuleStorage($prefix = 'mtuc-storage')
    {
        $root = self::allocate($prefix);
        $module = $root . DIRECTORY_SEPARATOR . 'mt_uni_credit';
        if (!@mkdir($module, 0770, true) && !is_dir($module)) {
            throw new RuntimeException('MTUC test temp: failed to create module storage under ' . $root);
        }

        return $root;
    }

    /**
     * @param string $path
     * @return void
     */
    public static function cleanup($path)
    {
        $path = (string) $path;
        if ($path === '' || !is_dir($path)) {
            return;
        }

        $resolved = realpath($path);
        $tempRoot = realpath(sys_get_temp_dir());
        if ($resolved === false || $tempRoot === false) {
            return;
        }

        $resolvedNorm = strtolower(str_replace('\\', '/', $resolved));
        $tempNorm = strtolower(str_replace('\\', '/', $tempRoot));

        // Must stay strictly under the system temp directory.
        if ($resolvedNorm === $tempNorm || strpos($resolvedNorm, $tempNorm . '/') !== 0) {
            return;
        }

        // Refuse shallow/dangerous targets (temp root itself or drive roots).
        $parts = array_values(array_filter(explode('/', trim($resolvedNorm, '/')), 'strlen'));
        if (count($parts) < 2) {
            return;
        }

        self::deleteTree($resolved);
    }

    /**
     * @return void
     */
    public static function cleanupAll()
    {
        foreach (self::$roots as $root) {
            self::cleanup($root);
        }
        self::$roots = array();
    }

    /**
     * @return void
     */
    private static function registerShutdownCleanup()
    {
        if (self::$shutdownRegistered) {
            return;
        }
        self::$shutdownRegistered = true;
        register_shutdown_function(function () {
            MtUniCreditTestTempRoot::cleanupAll();
        });
    }

    /**
     * @param string $dir
     * @return void
     */
    private static function deleteTree($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = @scandir($dir);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($full) && !is_link($full)) {
                self::deleteTree($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($dir);
    }
}

/**
 * Define DIR_STORAGE once with a unique per-process root.
 *
 * @param string $prefix
 * @return string
 */
function mtuc_test_define_dir_storage($prefix = 'mtuc-storage')
{
    if (defined('DIR_STORAGE')) {
        return DIR_STORAGE;
    }

    $storage = MtUniCreditTestTempRoot::allocateModuleStorage($prefix);
    define('DIR_STORAGE', rtrim($storage, '/\\') . DIRECTORY_SEPARATOR);

    return DIR_STORAGE;
}
