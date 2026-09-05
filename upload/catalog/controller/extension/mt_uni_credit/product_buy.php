<?php

require_once DIR_SYSTEM . 'library/mt_uni_credit/bootstrap.php';

/**
 * Product Buy → Checkout payment preselect (native session.payment_method).
 *
 * Invoked from OCMOD hooks on checkout/payment_method — not a public storefront route.
 */
class ControllerExtensionMtUniCreditProductBuy extends Controller
{
    /**
     * After payment_methods are discovered: select UniCredit when Buy preference is valid.
     *
     * @return void
     */
    public function applyPaymentPreselect()
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
     * @return void
     */
    public function onPaymentMethodSaved()
    {
        if (!isset($this->session->data) || !is_array($this->session->data)) {
            return;
        }
        MtUniCreditProductBuyPreference::clearIfPaymentChangedAway($this->session->data);
    }
}
