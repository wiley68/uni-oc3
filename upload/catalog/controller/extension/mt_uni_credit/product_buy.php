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
        MtUniCreditProductBuyPreference::applyPaymentIfAvailable(
            $this->session->data,
            $this->session->data['payment_methods'],
            $storeId
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
     * Leaving Checkout via cart/home: drop Buy preference entirely so a later
     * normal Checkout cannot reuse it. Safe for pending (Buy abandoned before Checkout)
     * and active (left an in-progress Buy Checkout).
     *
     * @param string $route
     * @param mixed $data
     * @return void
     */
    public function releaseCheckoutGuard(&$route, &$data)
    {
        if (!isset($this->session->data) || !is_array($this->session->data)) {
            return;
        }
        MtUniCreditProductBuyPreference::clear($this->session->data);
    }

    /**
     * Product page entry: keep pending Buy handoff (Купи stash lives here), but drop an
     * already-activated Buy Checkout preference (customer left Checkout to browse).
     *
     * @param string $route
     * @param mixed $data
     * @return void
     */
    public function releaseActiveCheckoutGuard(&$route, &$data)
    {
        if (!isset($this->session->data) || !is_array($this->session->data)) {
            return;
        }
        MtUniCreditProductBuyPreference::clearIfActivated($this->session->data);
    }
}
