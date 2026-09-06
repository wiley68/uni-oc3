<?php

/**
 * Unique temporary roots for offline tests (AUD-033 / F-033-03).
 * PHP 7.3 compatible. Cleanup deletes ONLY helper-allocated roots.
 */
final class MtUniCreditTestTempRoot
{
    /** @var array<string, string> normalized path => absolute path */
    private static $roots = array();

    /** @var array<string, bool> normalized paths cleaned this process (idempotent) */
    private static $cleaned = array();

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

        $resolved = realpath($path);
        if ($resolved === false || $resolved === '') {
            throw new RuntimeException('MTUC test temp: failed to canonicalize ' . $path);
        }

        $key = self::normalizePath($resolved);
        self::$roots[$key] = $resolved;
        self::registerShutdownCleanup();

        return $resolved;
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
     * Delete an exact helper-allocated root only.
     *
     * @param string $path
     * @return bool true when cleanup accepted (deleted or already gone); false when refused
     */
    public static function cleanup($path)
    {
        $path = (string) $path;
        if ($path === '') {
            return false;
        }

        $tempRoot = realpath(sys_get_temp_dir());
        if ($tempRoot === false || $tempRoot === '') {
            return false;
        }

        $resolved = realpath($path);
        // Already missing: idempotent only for previously allocated/cleaned roots.
        if ($resolved === false) {
            $key = self::normalizePath($path);
            if (isset(self::$roots[$key]) || isset(self::$cleaned[$key])) {
                unset(self::$roots[$key]);
                self::$cleaned[$key] = true;

                return true;
            }

            return false;
        }

        $key = self::normalizePath($resolved);
        $tempKey = self::normalizePath($tempRoot);

        if ($key === '' || $key === $tempKey) {
            return false;
        }

        // Exact membership in the allocation registry (not child/parent/unrelated).
        if (!isset(self::$roots[$key])) {
            // Idempotent re-clean of a root we already removed this process.
            if (isset(self::$cleaned[$key])) {
                return true;
            }

            return false;
        }

        // Defense in depth: allocated root must still resolve under system temp.
        if (strpos($key, $tempKey . '/') !== 0) {
            return false;
        }

        $parts = array_values(array_filter(explode('/', trim($key, '/')), 'strlen'));
        if (count($parts) < 2) {
            return false;
        }

        $ok = self::deleteTree($resolved);
        unset(self::$roots[$key]);
        self::$cleaned[$key] = true;

        return $ok;
    }

    /**
     * @return bool true when every registered root cleaned successfully
     */
    public static function cleanupAll()
    {
        $ok = true;
        $snapshot = self::$roots;
        foreach ($snapshot as $absolute) {
            if (!self::cleanup($absolute)) {
                $ok = false;
            }
        }
        self::$roots = array();

        return $ok;
    }

    /**
     * @return array<int, string>
     */
    public static function allocatedRoots()
    {
        return array_values(self::$roots);
    }

    /**
     * @param string $path
     * @return string
     */
    private static function normalizePath($path)
    {
        $normalized = str_replace('\\', '/', (string) $path);
        $normalized = rtrim($normalized, '/');
        if (DIRECTORY_SEPARATOR === '\\') {
            return strtolower($normalized);
        }

        return $normalized;
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
     * @return bool
     */
    private static function deleteTree($dir)
    {
        if (!is_dir($dir)) {
            return true;
        }
        $items = @scandir($dir);
        if (!is_array($items)) {
            return false;
        }
        $ok = true;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($full) && !is_link($full)) {
                if (!self::deleteTree($full)) {
                    $ok = false;
                }
            } else {
                if (!@unlink($full) && file_exists($full)) {
                    $ok = false;
                }
            }
        }
        if (!@rmdir($dir) && is_dir($dir)) {
            $ok = false;
        }

        return $ok;
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
