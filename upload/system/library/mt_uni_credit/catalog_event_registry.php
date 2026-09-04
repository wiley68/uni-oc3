<?php

/**
 * Shared catalog event definitions for Thank You + native order mail enrichment.
 */
final class MtUniCreditCatalogEventRegistry
{
    /**
     * @return array<int, array{code: string, trigger: string, action: string}>
     */
    public static function definitions()
    {
        return array(
            array(
                'code' => 'mt_uni_credit_checkout_success_order',
                'trigger' => 'catalog/controller/checkout/success/before',
                'action' => 'extension/mt_uni_credit/checkout_success/before',
            ),
            array(
                'code' => 'mt_uni_credit_checkout_success_view',
                'trigger' => 'catalog/view/common/success/before',
                'action' => 'extension/mt_uni_credit/checkout_success/beforeView',
            ),
            array(
                'code' => 'mt_uni_credit_checkout_success_view_after',
                'trigger' => 'catalog/view/common/success/after',
                'action' => 'extension/mt_uni_credit/checkout_success/afterView',
            ),
            array(
                'code' => 'mt_uni_credit_mail_order_add',
                'trigger' => 'catalog/view/mail/order_add/after',
                'action' => 'extension/mt_uni_credit/order_mail/afterOrderAdd',
            ),
            array(
                'code' => 'mt_uni_credit_mail_order_alert',
                'trigger' => 'catalog/view/mail/order_alert/after',
                'action' => 'extension/mt_uni_credit/order_mail/afterOrderAlert',
            ),
        );
    }
}
