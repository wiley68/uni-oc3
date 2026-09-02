<?php

/**
 * Resolves the OpenCart upload root (parent of system/) deterministically.
 */
final class MtUniCreditExtensionRoot
{
    /**
     * @return string Absolute path to upload/ (shop root for packaged module files)
     */
    public static function path()
    {
        return dirname(dirname(dirname(__DIR__)));
    }
}
