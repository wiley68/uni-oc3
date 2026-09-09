<?php

require_once DIR_SYSTEM . 'library/mt_uni_credit/bootstrap.php';

/**
 * Product Buy → Checkout payment preselect (native session.payment_method).
 *
 * OCMOD invokes applyPaymentPreselect / onPaymentMethodSaved via Loader::controller(),
 * which always passes array(&$data). Catalog event callbacks pass &$route, &$data.
 * Direct public routes execute Action with zero args and must not mutate session.
 */
class ControllerExtensionMtUniCreditProductBuy extends Controller
{
    /**
     * After payment_methods are discovered: select UniCredit when Buy preference is valid.
     *
     * Required &$data matches Loader::controller / OCMOD invocation. Direct route
     * (ControllerStartupRouter → Action::execute with no args) fails closed.
     *
     * @param array<string, mixed> $data
     * @return void
     */
    public function applyPaymentPreselect(&$data)
    {
        if (!isset($this->session->data) || !is_array($this->session->data)) {
            return;
        }
        if (
            !isset($this->session->data['payment_methods'])
            || !is_array($this->session->data['payment_methods'])
        ) {
            return;
        }

        $storeId = (int) $this->config->get('config_store_id');
        $navId = MtUniCreditProductBuyPreference::requestNavigationId($this->request);
        MtUniCreditProductBuyPreference::applyPaymentIfAvailable(
            $this->session->data,
            $this->session->data['payment_methods'],
            $storeId,
            $navId
        );
    }

    /**
     * After customer saves a payment method: drop Buy preference if they chose another method.
     *
     * @param array<string, mixed> $data
     * @return void
     */
    public function onPaymentMethodSaved(&$data)
    {
        if (!isset($this->session->data) || !is_array($this->session->data)) {
            return;
        }
        MtUniCreditProductBuyPreference::clearIfPaymentChangedAway($this->session->data);
    }

    /**
     * catalog/view/checkout/payment_method/before
     *
     * Applies Product Buy payment preselect and syncs Twig $data['code'] so the
     * radio is checked even when payment_method.php OCMOD did not apply.
     *
     * @param string $route
     * @param array $data
     * @param string $code
     * @return void
     */
    public function onPaymentMethodView(&$route, &$data, &$code)
    {
        unset($code);
        if ((string) $route !== 'checkout/payment_method') {
            return;
        }
        if (!isset($this->session->data) || !is_array($this->session->data)) {
            return;
        }
        if (
            !isset($this->session->data['payment_methods'])
            || !is_array($this->session->data['payment_methods'])
        ) {
            return;
        }

        $storeId = (int) $this->config->get('config_store_id');
        $navId = MtUniCreditProductBuyPreference::requestNavigationId($this->request);
        MtUniCreditProductBuyPreference::applyPaymentIfAvailable(
            $this->session->data,
            $this->session->data['payment_methods'],
            $storeId,
            $navId
        );

        if (!is_array($data)) {
            return;
        }
        if (isset($this->session->data['payment_method']['code'])) {
            $data['code'] = (string) $this->session->data['payment_method']['code'];
        }
    }

    /**
     * Catalog controller before: Product Buy lifecycle route policy (AUD-018 F01/F02).
     * Must return null so Loader does not replace the controller output.
     *
     * @param string $route
     * @param mixed $data
     * @return void
     */
    public function onStorefrontNavigation(&$route, &$data)
    {
        if (!isset($this->session->data) || !is_array($this->session->data)) {
            return;
        }

        $current = MtUniCreditStorefrontRouteResolver::currentRoute($route);
        $navId = MtUniCreditProductBuyPreference::requestNavigationId($this->request);

        // Preserve Product Buy stash + cart/add handoff.
        if (
            MtUniCreditStorefrontRouteResolver::isProductBuyRoute($current)
            || MtUniCreditStorefrontRouteResolver::isCartAddRoute($current)
        ) {
            return;
        }

        // Checkout entry: activate Buy when mt_uni_nav matches (URL/cookie); else clear.
        if (MtUniCreditStorefrontRouteResolver::isCheckoutEntryRoute($current)) {
            $storeId = (int) $this->config->get('config_store_id');
            MtUniCreditProductBuyPreference::onCheckoutEntry(
                $this->session->data,
                $storeId,
                $navId
            );

            return;
        }

        // Other Checkout lifecycle / UniCredit checkout AJAX: preserve (token checked at load).
        if (MtUniCreditStorefrontRouteResolver::isCheckoutLifecycleRoute($current)) {
            return;
        }

        // Cart page / home: full clear.
        if (
            MtUniCreditStorefrontRouteResolver::isCartPageRoute($current)
            || MtUniCreditStorefrontRouteResolver::isHomepageRoute($current)
        ) {
            MtUniCreditProductBuyPreference::clear($this->session->data);

            return;
        }

        // Product page: clear active only (pending Buy stash may still live here).
        if (MtUniCreditStorefrontRouteResolver::isProductPageRoute($current)) {
            MtUniCreditProductBuyPreference::clearIfActivated($this->session->data);

            return;
        }

        // Unrelated storefront (category/search/manufacturer/information/account/…).
        if (MtUniCreditStorefrontRouteResolver::isUnrelatedStorefrontRoute($current)) {
            MtUniCreditProductBuyPreference::clearOnUnrelatedStorefront($this->session->data);
        }
    }

    /**
     * @deprecated Prefer onStorefrontNavigation; retained for older event rows during upgrade.
     *
     * @param string $route
     * @param mixed $data
     * @return void
     */
    public function releaseCheckoutGuard(&$route, &$data)
    {
        $this->onStorefrontNavigation($route, $data);
    }

    /**
     * @deprecated Prefer onStorefrontNavigation; retained for older event rows during upgrade.
     *
     * @param string $route
     * @param mixed $data
     * @return void
     */
    public function releaseActiveCheckoutGuard(&$route, &$data)
    {
        $this->onStorefrontNavigation($route, $data);
    }
}
