<?php

require_once DIR_SYSTEM . 'library/mt_uni_credit/bootstrap.php';

/**
 * CP → module authenticated inbound JSON API (Phase 6).
 *
 * Routes:
 * - extension/mt_uni_credit/api/shop_cache
 * - extension/mt_uni_credit/api/order_bank_status
 * - extension/mt_uni_credit/api/smartucf_debug_log
 */
class ControllerExtensionMtUniCreditApi extends Controller
{
    public function shop_cache()
    {
        MtUniCreditInboundApiRunner::run($this, function (array $payload, $unicid) {
            $data = isset($payload['data']) ? $payload['data'] : null;
            if (!is_array($data) || $data === array()) {
                throw new MtUniCreditInboundApiException(
                    'Полето data трябва да съдържа пълна конфигурация на магазина.',
                    400,
                    'invalid_payload'
                );
            }

            if (isset($data['unicid']) && (!is_string($data['unicid']) || !hash_equals($unicid, $data['unicid']))) {
                throw new MtUniCreditInboundApiException(
                    'UNICID в конфигурацията не съвпада с този на магазина.',
                    400,
                    'invalid_payload'
                );
            }

            $storeId = (int) $this->config->get('config_store_id');
            $db = MtUniCreditBootstrap::dbFromRegistry($this->db);
            $persistence = MtUniCreditBootstrap::shopCachePersistenceFromDb($db);
            $persistence->replaceValidatedSnapshot($storeId, $unicid, $data);
            $cache = new MtUniCreditShopCacheRepository($db);

            return array(
                'success' => true,
                'message' => 'Кешът на shop данни е обновен успешно.',
                'data' => $cache->findMetadata($storeId, $unicid),
            );
        });
    }

    public function order_bank_status()
    {
        MtUniCreditInboundApiRunner::run($this, function (array $payload, $unicid) {
            unset($unicid);

            $orderId = isset($payload['order_id']) ? $payload['order_id'] : null;
            if (!is_string($orderId) && !is_int($orderId)) {
                throw new MtUniCreditInboundApiException('Полето order_id е задължително.', 400, 'invalid_payload');
            }
            $orderId = trim((string) $orderId);
            if ($orderId === '' || strlen($orderId) > 64) {
                throw new MtUniCreditInboundApiException('Полето order_id е невалидно.', 400, 'invalid_payload');
            }

            $statusId = isset($payload['status_id']) ? $payload['status_id'] : null;
            if (!is_string($statusId) && !is_int($statusId)) {
                throw new MtUniCreditInboundApiException('Полето status_id е задължително.', 400, 'invalid_payload');
            }
            $statusId = trim((string) $statusId);
            if ($statusId === '' || strlen($statusId) > 255) {
                throw new MtUniCreditInboundApiException('Полето status_id е невалидно.', 400, 'invalid_payload');
            }
            if (!MtUniCreditInboundBankStatusVocabulary::isAccepted($statusId)) {
                throw new MtUniCreditInboundApiException('Неподдържан банков статус.', 400, 'unsupported_status');
            }

            $status = isset($payload['status']) ? $payload['status'] : '';
            if (!is_string($status) || strlen($status) > 255) {
                throw new MtUniCreditInboundApiException('Полето status е невалидно.', 400, 'invalid_payload');
            }
            $status = trim($status);

            $storeId = (int) $this->config->get('config_store_id');
            $db = MtUniCreditBootstrap::dbFromRegistry($this->db);
            $result = (new MtUniCreditOrderBankStatusRepository($db))->updateByOrderIdentifier(
                $storeId,
                $orderId,
                $statusId,
                $status
            );
            if ($result === null) {
                throw new MtUniCreditInboundApiException('Поръчката не е намерена в магазина.', 404, 'order_not_found');
            }

            return array(
                'success' => true,
                'message' => 'Банковият статус е обновен успешно.',
                'data' => $result,
            );
        });
    }

    public function smartucf_debug_log()
    {
        MtUniCreditInboundApiRunner::run($this, function (array $payload, $unicid) {
            unset($unicid);

            $orderIdRaw = isset($payload['order_id']) ? $payload['order_id'] : null;
            if (!is_string($orderIdRaw) && !is_int($orderIdRaw)) {
                throw new MtUniCreditInboundApiException('Полето order_id е задължително.', 400, 'invalid_payload');
            }
            $orderIdRaw = trim((string) $orderIdRaw);
            if ($orderIdRaw === '' || strlen($orderIdRaw) > 64 || !ctype_digit($orderIdRaw)) {
                throw new MtUniCreditInboundApiException('Полето order_id е невалидно.', 400, 'invalid_payload');
            }

            $orderId = (int) $orderIdRaw;
            $storeId = (int) $this->config->get('config_store_id');
            $db = MtUniCreditBootstrap::dbFromRegistry($this->db);
            $ownership = new MtUniCreditOrderOwnershipResolver($db);
            if ($ownership->resolveAuthorizedOrderId($storeId, $orderIdRaw) === null) {
                throw new MtUniCreditInboundApiException(
                    'Не е намерена диагностична информация за тази поръчка.',
                    404,
                    'order_not_found'
                );
            }

            $log = (new MtUniCreditDiagnosticDebugLogRepository($db))->findLatestByOrderId($storeId, $orderId);
            if ($log === null) {
                throw new MtUniCreditInboundApiException(
                    'Не е намерена диагностична информация за тази поръчка.',
                    404,
                    'order_not_found'
                );
            }

            return array(
                'success' => true,
                'data' => array(
                    'order_id' => $orderIdRaw,
                    'oc_order_id' => $orderId,
                    'log' => $log,
                ),
            );
        });
    }
}
