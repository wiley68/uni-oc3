<?php

/**
 * Shared Product/Cart/Checkout terminal navigation
 * (Process 2 Thank You, Process 1 definitive SmartUCF failure Thank You,
 * Process 1 CP-create failure stay-on-page error modal).
 *
 * Successful Process 1 bank redirect is never rewritten here.
 */
final class MtUniCreditFinancingTerminalNavigationSupport
{
    const SESSION_SUCCESS_ORDER_ID = 'mt_uni_credit_success_order_id';

    const STEP_SMARTUCF_TERMINAL_FAILED = 'smartucf_terminal_failed';

    /** Storefront must close the financing modal and show the error dialog (no redirect). */
    const UI_ERROR_MODAL = 'error_modal';

    /**
     * Definitive remote_reject after CP create (frozen Phase 9 terminal bank_send_failed_*).
     *
     * @param array<string, mixed> $result Storefront/checkout submission result array
     * @return bool
     */
    public static function isDefinitiveRemoteRejectTerminal(array $result)
    {
        if (!empty($result['success'])) {
            return false;
        }
        if ((int) (isset($result['order_id']) ? $result['order_id'] : 0) <= 0) {
            return false;
        }
        if (empty($result['cp_succeeded'])) {
            return false;
        }
        if (!empty($result['recoverable']) || !empty($result['ambiguous_blocked'])) {
            return false;
        }
        $error = (string) (isset($result['error']) ? $result['error'] : '');

        return $error === MtUniCreditSmartUcfFailureClassification::CLASS_REMOTE_REJECT;
    }

    /**
     * @param array<string, mixed> $result
     * @return bool
     * @deprecated Use isDefinitiveRemoteRejectTerminal()
     */
    public static function isSmartUcfTerminalFailure(array $result)
    {
        return self::isDefinitiveRemoteRejectTerminal($result);
    }

    /**
     * After Process 2 success: stash order ownership for checkout/success and force Thank You URL.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $sessionData
     * @param int $orderId
     * @param string $thankYouUrl
     * @return array<string, mixed>
     */
    public static function enrichProcess2ThankYou(array $payload, array &$sessionData, $orderId, $thankYouUrl)
    {
        $orderId = (int) $orderId;
        $thankYouUrl = trim((string) $thankYouUrl);
        if ($orderId <= 0 || $thankYouUrl === '') {
            return $payload;
        }
        if (empty($payload['success'])) {
            return $payload;
        }
        // Process 1 SmartUCF bank redirect must stay intact.
        if (!empty($payload['bank_redirect']) && !empty($payload['redirect'])) {
            return $payload;
        }

        $sessionData['order_id'] = $orderId;
        $sessionData[self::SESSION_SUCCESS_ORDER_ID] = $orderId;
        $payload['redirect'] = $thankYouUrl;
        $payload['bank_redirect'] = false;

        return $payload;
    }

    /**
     * After definitive remote_reject with OC+CP created: terminal Thank You (not cart/prepared).
     *
     * @param array<string, mixed> $payload Controller JSON payload (success remains false)
     * @param array<string, mixed> $sessionData
     * @param int $orderId
     * @param string $thankYouUrl
     * @return array<string, mixed>
     */
    public static function enrichDefinitiveRemoteRejectThankYou(
        array $payload,
        array &$sessionData,
        $orderId,
        $thankYouUrl
    ) {
        $orderId = (int) $orderId;
        $thankYouUrl = trim((string) $thankYouUrl);
        if ($orderId <= 0 || $thankYouUrl === '') {
            return $payload;
        }

        $sessionData['order_id'] = $orderId;
        $sessionData[self::SESSION_SUCCESS_ORDER_ID] = $orderId;
        unset($sessionData[MtUniCreditCheckoutConfirmPreparation::SESSION_PREPARED_ORDER_ID]);

        $payload['redirect'] = $thankYouUrl;
        $payload['bank_redirect'] = false;
        $payload['terminal'] = true;
        $payload['bank_failure_known'] = true;
        $payload['step'] = self::STEP_SMARTUCF_TERMINAL_FAILED;
        $payload['message'] = MtUniCreditFinancingLeasingPresenter::SMARTUCF_TERMINAL_FAILURE_MESSAGE;

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $sessionData
     * @param int $orderId
     * @param string $thankYouUrl
     * @return array<string, mixed>
     * @deprecated Use enrichDefinitiveRemoteRejectThankYou()
     */
    public static function enrichSmartUcfTerminalFailure(
        array $payload,
        array &$sessionData,
        $orderId,
        $thankYouUrl
    ) {
        return self::enrichDefinitiveRemoteRejectThankYou($payload, $sessionData, $orderId, $thankYouUrl);
    }

    /**
     * CP create did not succeed (no CP financing order). Local OC order/attempt may exist.
     * Customer stays on Product/Cart — never prepared/cart/Thank You.
     *
     * @param array<string, mixed> $result
     * @return bool
     */
    public static function isCpCreateFailureStayOnPage(array $result)
    {
        if (!empty($result['success'])) {
            return false;
        }
        if (!empty($result['cp_succeeded'])) {
            return false;
        }
        if (self::isDefinitiveRemoteRejectTerminal($result)) {
            return false;
        }

        // Local order materialization before CP is expected for Product Apply.
        return (int) (isset($result['order_id']) ? $result['order_id'] : 0) > 0;
    }

    /**
     * Structured storefront result: error modal, no navigation redirect.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $sessionData
     * @return array<string, mixed>
     */
    public static function enrichCpCreateFailureModal(array $payload, array &$sessionData)
    {
        unset($payload['redirect']);
        unset($sessionData[MtUniCreditCheckoutConfirmPreparation::SESSION_PREPARED_ORDER_ID]);
        // Never promote CP-create failure to Thank You ownership.
        unset($sessionData[self::SESSION_SUCCESS_ORDER_ID]);

        $payload['success'] = false;
        $payload['bank_redirect'] = false;
        $payload['terminal_ui'] = self::UI_ERROR_MODAL;
        $payload['stay_on_page'] = true;

        if (!isset($payload['message']) || trim((string) $payload['message']) === '') {
            if (!empty($payload['ambiguous_blocked'])) {
                $payload['message'] = MtUniCreditControlPanelOrderLifecycleService::CUSTOMER_AMBIGUOUS_MESSAGE;
            } else {
                $payload['message'] = MtUniCreditControlPanelOrderLifecycleService::CUSTOMER_FAILURE_MESSAGE;
            }
        }

        return $payload;
    }
}
