<?php

/**
 * Guarded versioned public URLs for storefront CSS/JS under the default-theme path.
 */
final class MtUniCreditStorefrontAssetUrls
{
    /**
     * @param string $absolutePath Absolute filesystem path to the asset
     * @param string $publicRelativeUrl Public catalog-relative URL (no leading slash required)
     * @return string Empty string when the file is missing (no warning)
     */
    public static function versionedUrl($absolutePath, $publicRelativeUrl)
    {
        $absolutePath = (string) $absolutePath;
        $publicRelativeUrl = ltrim(str_replace('\\', '/', (string) $publicRelativeUrl), '/');
        if ($absolutePath === '' || $publicRelativeUrl === '') {
            return '';
        }
        if (!is_file($absolutePath)) {
            return '';
        }

        $mtime = @filemtime($absolutePath);
        if ($mtime === false || (int) $mtime <= 0) {
            return $publicRelativeUrl;
        }

        return $publicRelativeUrl . '?ver=' . (int) $mtime;
    }
}
