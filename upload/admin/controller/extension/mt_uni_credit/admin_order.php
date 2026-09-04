<?php

require_once DIR_SYSTEM . 'library/mt_uni_credit/bootstrap.php';

/**
 * Admin Orders list + order info UniCredit financing presentation (no core template edits).
 *
 * OC3 view events: admin/view/sale/order_list|order_info before|after
 * (admin/startup/event strips the admin/ prefix before register).
 */
class ControllerExtensionMtUniCreditAdminOrder extends Controller
{
    /**
     * @param string $route
     * @param array $data
     * @param string $code
     * @return void
     */
    public function beforeOrderList(&$route, &$data, &$code)
    {
        if (!is_array($data) || empty($data['orders']) || !is_array($data['orders'])) {
            return;
        }
        $hasOrder = false;
        foreach ($data['orders'] as $order) {
            if (is_array($order) && (int) (isset($order['order_id']) ? $order['order_id'] : 0) > 0) {
                $hasOrder = true;
                break;
            }
        }
        if (!$hasOrder) {
            return;
        }

        try {
            $repo = new MtUniCreditFinancingPresentationRepository(
                new MtUniCreditDbAdapter($this->db, defined('DB_PREFIX') ? DB_PREFIX : 'oc_')
            );
            $fallbackStoreId = (int) $this->config->get('config_store_id');
            $labels = $repo->bankStatusLabelsForOrders($data['orders'], $fallbackStoreId);
            foreach ($data['orders'] as $index => $order) {
                $data['orders'][$index]['mt_uni_credit_bank_status'] = isset($labels[$index])
                    ? $labels[$index]
                    : '';
            }
        } catch (Exception $exception) {
            error_log('mt_uni_credit: admin order list enrichment failed class=' . get_class($exception));
        }
    }

    /**
     * @param string $route
     * @param array $data
     * @param string $output
     * @return void
     */
    public function afterOrderList(&$route, &$data, &$output)
    {
        if (!is_string($output) || $output === '' || empty($data['orders']) || !is_array($data['orders'])) {
            return;
        }
        if (strpos($output, 'mt-uni-credit-admin-bank-status') !== false) {
            return;
        }

        $columnLabel = 'UniCredit статус';
        $safeLabel = htmlspecialchars($columnLabel, ENT_QUOTES, 'UTF-8');

        // OC3 thead uses <td>: insert after Status (2nd text-left), before Total (text-right).
        $replaced = preg_replace(
            '/(<thead>[\s\S]*?<td class="text-left">[\s\S]*?<\/td>\s*<td class="text-left">[\s\S]*?<\/td>)(\s*<td class="text-right">)/u',
            '$1<td class="text-left">' . $safeLabel . '</td>$2',
            $output,
            1,
            $count
        );
        if (is_string($replaced) && $count > 0) {
            $output = $replaced;
        }

        foreach ($data['orders'] as $order) {
            if (!is_array($order)) {
                continue;
            }
            $orderId = (int) (isset($order['order_id']) ? $order['order_id'] : 0);
            if ($orderId <= 0) {
                continue;
            }
            $label = trim((string) (isset($order['mt_uni_credit_bank_status'])
                ? $order['mt_uni_credit_bank_status']
                : ''));
            $cell = $label !== ''
                ? htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
                : '—';

            $pattern = '/(name="selected\[\]" value="' . preg_quote((string) $orderId, '/')
                . '"[\s\S]*?<td class="text-right">[\s\S]*?<\/td>\s*<td class="text-left">[\s\S]*?<\/td>\s*<td class="text-left">[\s\S]*?<\/td>\s*)(<td class="text-right">)/u';
            $rowReplaced = preg_replace(
                $pattern,
                '$1<td class="text-left mt-uni-credit-admin-bank-status">' . $cell . '</td>$2',
                $output,
                1
            );
            if (is_string($rowReplaced)) {
                $output = $rowReplaced;
            }
        }

        $output = str_replace('colspan="8"', 'colspan="9"', $output);
    }

    /**
     * @param string $route
     * @param array $data
     * @param string $output
     * @return void
     */
    public function afterOrderInfo(&$route, &$data, &$output)
    {
        if (!is_string($output) || $output === '') {
            return;
        }
        if (strpos($output, 'mt-uni-credit-admin-order-leasing') !== false) {
            return;
        }

        $orderId = (int) (isset($data['order_id']) ? $data['order_id'] : 0);
        if ($orderId <= 0) {
            $request = $this->request;
            if (is_object($request) && isset($request->get['order_id'])) {
                $orderId = (int) $request->get['order_id'];
            }
        }
        if ($orderId <= 0) {
            return;
        }

        try {
            $service = new MtUniCreditFinancingPresentationService(
                new MtUniCreditFinancingPresentationRepository(
                    new MtUniCreditDbAdapter($this->db, defined('DB_PREFIX') ? DB_PREFIX : 'oc_')
                )
            );
            $storeId = (int) (isset($data['store_id']) ? $data['store_id'] : $this->config->get('config_store_id'));
            $html = $service->htmlForOrder(
                $storeId,
                $orderId,
                MtUniCreditFinancingPresentationAudience::ADMIN_PANEL,
                ''
            );
            if ($html === '') {
                return;
            }

            $title = htmlspecialchars(MtUniCreditFinancingLeasingPresenter::ADMIN_TITLE, ENT_QUOTES, 'UTF-8');
            $block = '<div class="panel panel-default mt-uni-credit-admin-order-leasing">'
                . '<div class="panel-heading"><h3 class="panel-title">'
                . '<i class="fa fa-university"></i> ' . $title
                . '</h3></div>'
                . '<div class="panel-body">' . $html . '</div></div>';

            // Prefer after History panel (fa-comment-o), still inside #content container-fluid.
            $replaced = preg_replace(
                '/(<div class="panel panel-default">\s*<div class="panel-heading">[\s\S]*?fa-comment-o[\s\S]*?<\/div>\s*<\/div>)(\s*<\/div>)/u',
                '$1' . $block . '$2',
                $output,
                1,
                $count
            );
            if (is_string($replaced) && $count > 0) {
                $output = $replaced;
            } else {
                $replaced = preg_replace(
                    '/(<div id="content">[\s\S]*)(<\/div>\s*)(\{\{\s*footer|\{\%\s*endblock|<\/body)/u',
                    '$1' . $block . '$2$3',
                    $output,
                    1,
                    $count2
                );
                if (is_string($replaced) && $count2 > 0) {
                    $output = $replaced;
                } else {
                    $output .= $block;
                }
            }
        } catch (Exception $exception) {
            error_log('mt_uni_credit: admin order info leasing failed class=' . get_class($exception));
        }
    }
}
