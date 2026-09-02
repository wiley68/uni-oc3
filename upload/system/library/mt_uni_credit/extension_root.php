<?php

/**
 * Resolves the mt_uni_credit extension library root deterministically.
 *
 * Deployed path: DIR_SYSTEM . 'library/mt_uni_credit'
 */
final class MtUniCreditExtensionRoot
{
    /**
     * @return string Absolute path to system/library/mt_uni_credit
     */
    public static function path()
    {
        if (defined('DIR_SYSTEM')) {
            return rtrim(DIR_SYSTEM, '/\\') . DIRECTORY_SEPARATOR . 'library' . DIRECTORY_SEPARATOR . 'mt_uni_credit';
        }

        return __DIR__;
    }
}
