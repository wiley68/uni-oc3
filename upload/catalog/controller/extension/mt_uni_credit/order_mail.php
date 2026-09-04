<?php

require_once DIR_SYSTEM . 'library/mt_uni_credit/bootstrap.php';

/**
 * Inject UniCredit leasing into native OpenCart order emails (OC3).
 *
 * Customer: mail/order_add is HTML (Mail::setHtml).
 * Admin alert: mail/order_alert is text (Mail::setText) — append plain text, not HTML.
 */
class ControllerExtensionMtUniCreditOrderMail extends Controller
{
    /**
     * catalog/view/mail/order_add/after
     *
     * @param string $route
     * @param array $data
     * @param string $output
     * @return void
     */
    public function afterOrderAdd(&$route, &$data, &$output)
    {
        $this->appendLeasing(
            $data,
            $output,
            MtUniCreditFinancingPresentationAudience::CUSTOMER,
            'html_table'
        );
    }

    /**
     * catalog/view/mail/order_alert/after
     *
     * @param string $route
     * @param array $data
     * @param string $output
     * @return void
     */
    public function afterOrderAlert(&$route, &$data, &$output)
    {
        $this->appendLeasing(
            $data,
            $output,
            MtUniCreditFinancingPresentationAudience::ADMIN_EMAIL,
            'text'
        );
    }

    /**
     * @param array $data
     * @param string $output
     * @param string $audience
     * @param string $format html_table|text
     * @return void
     */
    private function appendLeasing($data, &$output, $audience, $format)
    {
        if (!is_string($output) || $output === '') {
            return;
        }
        if (
            strpos($output, 'mt-uni-credit-leasing-block') !== false
            || strpos($output, "УниКредит лизинг\n") !== false
            || (
                strpos($output, 'УниКредит лизинг') !== false
                && strpos($output, 'Срок (месеци)') !== false
            )
        ) {
            return;
        }
        if (!is_array($data)) {
            return;
        }
        $orderId = (int) (isset($data['order_id']) ? $data['order_id'] : 0);
        error_log(
            'mt_uni_credit: mail event entered audience=' . $audience
                . ' order_id=' . $orderId
                . ' format=' . $format
        );
        if ($orderId <= 0) {
            return;
        }

        try {
            $db = MtUniCreditBootstrap::dbFromRegistry($this->db);
            $service = new MtUniCreditFinancingPresentationService(
                new MtUniCreditFinancingPresentationRepository($db)
            );
            $storeId = (int) $this->config->get('config_store_id');
            // Prefer order store_id when present in mail data context via DB ownership.
            $rows = $service->rowsForOrder($storeId, $orderId, $audience);
            error_log('mt_uni_credit: mail row_count=' . count($rows) . ' audience=' . $audience);
            if ($rows === array()) {
                return;
            }

            $presenter = new MtUniCreditFinancingLeasingPresenter();
            if ($format === 'text') {
                $chunk = $presenter->renderText($rows);
                if ($chunk === '' || $this->containsBlockedSensitiveMailContent($chunk, $audience)) {
                    return;
                }
                $output .= "\n\n" . $chunk;

                return;
            }

            // Customer native mail: strip CP ids + enforce privacy.
            if ($audience === MtUniCreditFinancingPresentationAudience::CUSTOMER) {
                $rows = $service->filterCustomerFacingRows($rows);
            }
            $html = $presenter->renderHtml($rows);
            if ($html === '' || $this->containsBlockedSensitiveMailContent($html, $audience)) {
                if ($this->containsBlockedSensitiveMailContent($html, $audience)) {
                    error_log('mt_uni_credit: blocked order mail leasing HTML containing sensitive fields');
                }

                return;
            }
            $output .= '<br/>' . $html;
        } catch (Exception $exception) {
            error_log('mt_uni_credit: order mail leasing inject failed class=' . get_class($exception));
        }
    }

    /**
     * @param string $content
     * @param string $audience
     * @return bool
     */
    private function containsBlockedSensitiveMailContent($content, $audience)
    {
        if ($audience !== MtUniCreditFinancingPresentationAudience::CUSTOMER) {
            return false;
        }
        if (strpos($content, MtUniCreditFinancingLeasingPresenter::LABEL_EGN) !== false) {
            return true;
        }
        if (strpos($content, MtUniCreditFinancingLeasingPresenter::LABEL_PHONE2) !== false) {
            return true;
        }

        return false;
    }
}
