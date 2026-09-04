<?php

/**
 * Shared module event definitions (catalog + admin) for presentation + Phase 11 UX.
 *
 * OC3 mechanics (reference-oc3-core):
 * - DB trigger keeps catalog/ or admin/ prefix; startup/event strips first segment before register.
 * - Loader/Router fire controller/{route}/before|after and view/{route}/before|after.
 * - Action strings are classic OC3 routes: extension/.../controller/method (slash method).
 * - Callbacks must accept by-ref args matching Loader::view / Router signatures.
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
            array(
                'code' => 'mt_uni_credit_admin_order_list_before',
                'trigger' => 'admin/view/sale/order_list/before',
                'action' => 'extension/mt_uni_credit/admin_order/beforeOrderList',
            ),
            array(
                'code' => 'mt_uni_credit_admin_order_list_after',
                'trigger' => 'admin/view/sale/order_list/after',
                'action' => 'extension/mt_uni_credit/admin_order/afterOrderList',
            ),
            array(
                'code' => 'mt_uni_credit_admin_order_info_after',
                'trigger' => 'admin/view/sale/order_info/after',
                'action' => 'extension/mt_uni_credit/admin_order/afterOrderInfo',
            ),
            array(
                'code' => 'mt_uni_credit_home_controller_before',
                'trigger' => 'catalog/controller/common/home/before',
                'action' => 'extension/mt_uni_credit/home/beforeHome',
            ),
            array(
                'code' => 'mt_uni_credit_home_footer_after',
                'trigger' => 'catalog/view/common/footer/after',
                'action' => 'extension/mt_uni_credit/home/afterFooter',
            ),
        );
    }
}
