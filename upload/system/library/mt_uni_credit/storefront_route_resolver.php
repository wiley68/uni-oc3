<?php

/**
 * OpenCart storefront route helpers (homepage = common/home or default empty route).
 */
final class MtUniCreditStorefrontRouteResolver
{
    /**
     * @param mixed $route
     * @return string
     */
    public static function currentRoute($route)
    {
        return trim((string) $route);
    }

    /**
     * @param mixed $route
     * @return bool
     */
    public static function isHomepageRoute($route)
    {
        $route = self::currentRoute($route);

        return $route === '' || $route === 'common/home';
    }
}
