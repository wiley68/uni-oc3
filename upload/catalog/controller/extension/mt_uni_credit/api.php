<?php

require_once DIR_SYSTEM . 'library/mt_uni_credit/bootstrap.php';

/**
 * CP → module authenticated inbound JSON API (Phase 6 + GAP-05..14).
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
            if (!is_array($data) || $data === array() || $this->isListArray($data)) {
                throw new MtUniCreditInboundApiException(
                    'Полето data трябва да съдържа пълна конфигурация на магазина.',
                    400,
                    'invalid_payload'
                );
            }

            $data = MtUniCreditShopSnapshotSanitizer::sanitize($data);

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

            try {
                $persistence->replaceValidatedSnapshot($storeId, $unicid, $data);
            } catch (MtUniCreditShopSnapshotValidationException $exception) {
                throw new MtUniCreditInboundApiException(
                    'Конфигурацията на магазина е невалидна.',
                    422,
                    'shop_snapshot_invalid',
                    array('violations' => $exception->violations())
                );
            }

            $cache = new MtUniCreditShopCacheRepository($db);

            return array(
                'success' => true,
                'message' => 'Кешът на shop данни е обновен успешно.',
                'data' => $cache->findMetadata($storeId, $unicid),
            );
        }, MtUniCreditInboundApiOperations::SHOP_CACHE);
    }

    public function order_bank_status()
    {
        MtUniCreditInboundApiRunner::run($this, function (array $payload, $unicid) {
            $orderId = isset($payload['order_id']) ? $payload['order_id'] : null;
            if (!is_string($orderId)) {
                throw new MtUniCreditInboundApiException('Полето order_id е задължително.', 400, 'invalid_payload');
            }
            $orderId = trim($orderId);
            if ($orderId === '' || strlen($orderId) > 13) {
                throw new MtUniCreditInboundApiException('Полето order_id е невалидно.', 400, 'invalid_payload');
            }

            $statusId = isset($payload['status_id']) ? $payload['status_id'] : null;
            if (!is_string($statusId)) {
                throw new MtUniCreditInboundApiException('Полето status_id е задължително.', 400, 'invalid_payload');
            }
            $statusId = trim($statusId);
            if ($statusId === '' || strlen($statusId) > 255) {
                throw new MtUniCreditInboundApiException('Полето status_id е невалидно.', 400, 'invalid_payload');
            }
            if (!MtUniCreditInboundBankStatusVocabulary::isAccepted($statusId)) {
                throw new MtUniCreditInboundApiException('Неподдържан банков статус.', 400, 'unsupported_status');
            }

            $status = isset($payload['status']) ? $payload['status'] : null;
            if (!is_string($status)) {
                throw new MtUniCreditInboundApiException('Полето status е задължително.', 400, 'invalid_payload');
            }
            $status = trim($status);
            if ($status === '' || strlen($status) > 255) {
                throw new MtUniCreditInboundApiException('Полето status е невалидно.', 400, 'invalid_payload');
            }

            $storeId = (int) $this->config->get('config_store_id');
            $db = MtUniCreditBootstrap::dbFromRegistry($this->db);

            try {
                $result = (new MtUniCreditOrderBankStatusRepository($db))->updateByOrderIdentifier(
                    $storeId,
                    $unicid,
                    $orderId,
                    $statusId,
                    $status,
                    MtUniCreditBankStatusTransitionPolicy::SOURCE_INBOUND_CALLBACK
                );
            } catch (MtUniCreditFinancingOrderAmbiguousException $exception) {
                throw new MtUniCreditInboundApiException(
                    'Поръчката е двусмислена за този магазин.',
                    409,
                    'order_ambiguous',
                    null
                );
            } catch (MtUniCreditOrderBankStatusSemanticConflictException $exception) {
                throw new MtUniCreditInboundApiException(
                    'Несъвместима промяна на банков статус.',
                    409,
                    'semantic_conflict',
                    null
                );
            }

            if ($result === null) {
                throw new MtUniCreditInboundApiException('Поръчката не е намерена в магазина.', 404, 'order_not_found');
            }

            return array(
                'success' => true,
                'message' => 'Банковият статус е обновен успешно.',
                'data' => $result,
            );
        }, MtUniCreditInboundApiOperations::ORDER_BANK_STATUS);
    }

    public function smartucf_debug_log()
    {
        MtUniCreditInboundApiRunner::run($this, function (array $payload, $unicid) {
            $orderIdRaw = isset($payload['order_id']) ? $payload['order_id'] : null;
            if (!is_string($orderIdRaw)) {
                throw new MtUniCreditInboundApiException('Полето order_id е задължително.', 400, 'invalid_payload');
            }
            $orderIdRaw = trim($orderIdRaw);
            if ($orderIdRaw === '' || strlen($orderIdRaw) > 13 || !ctype_digit($orderIdRaw)) {
                throw new MtUniCreditInboundApiException('Полето order_id е невалидно.', 400, 'invalid_payload');
            }

            $storeId = (int) $this->config->get('config_store_id');
            $db = MtUniCreditBootstrap::dbFromRegistry($this->db);

            try {
                $resolved = (new MtUniCreditFinancingOrderResolver($db))->resolve($storeId, $unicid, $orderIdRaw);
            } catch (MtUniCreditFinancingOrderAmbiguousException $exception) {
                throw $this->opaqueDebugNotFound();
            }

            if ($resolved === null) {
                throw $this->opaqueDebugNotFound();
            }

            if (!$this->isAuthorizedPrimaryDebugTarget($storeId, $db, $resolved['order_id'], $resolved['attempt'])) {
                throw $this->opaqueDebugNotFound();
            }

            $orderId = $resolved['order_id'];
            $log = (new MtUniCreditDiagnosticDebugLogRepository($db))->findLatestSmartUcfSessionByOrderId(
                $storeId,
                $orderId
            );
            if ($log === null) {
                throw $this->opaqueDebugNotFound();
            }

            return array(
                'success' => true,
                'message' => 'Диагностичният запис е намерен.',
                'data' => array(
                    'order_id' => $orderIdRaw,
                    'oc_order_id' => MtUniCreditShopOrderId::tryNativeOc3OrderId($orderId),
                    'log' => $log,
                ),
            );
        }, MtUniCreditInboundApiOperations::SMARTUCF_DEBUG_LOG);
    }

    /**
     * Process 1 ownership only. Process-2-only and missing session ownership are opaque.
     *
     * @param int $storeId
     * @param MtUniCreditDbAdapter $db
     * @param string $orderId Canonical shop order id
     * @param array<string, mixed> $attempt
     * @return bool
     */
    private function isAuthorizedPrimaryDebugTarget($storeId, MtUniCreditDbAdapter $db, $orderId, array $attempt)
    {
        $bank = (new MtUniCreditOrderBankStatusRepository($db))->findCurrentStatus($storeId, $orderId);
        if (is_array($bank) && (string) (isset($bank['status_id']) ? $bank['status_id'] : '') === MtUniCreditBankStatus::SENT_PROCESS2) {
            return false;
        }

        $smartucfState = isset($attempt['smartucf_state']) ? (string) $attempt['smartucf_state'] : '';
        if ($smartucfState === '' || $smartucfState === MtUniCreditSmartUcfLifecycleStates::NOT_STARTED) {
            return false;
        }

        return true;
    }

    /**
     * @return MtUniCreditInboundApiException
     */
    private function opaqueDebugNotFound()
    {
        return new MtUniCreditInboundApiException(
            'Не е намерена диагностична информация за тази поръчка.',
            404,
            'order_not_found'
        );
    }

    /**
     * PHP 7.3 polyfill for array_is_list().
     *
     * @param array<mixed> $array
     * @return bool
     */
    private function isListArray(array $array)
    {
        if ($array === array()) {
            return true;
        }

        $expected = 0;
        foreach ($array as $key => $_value) {
            if ($key !== $expected) {
                return false;
            }
            $expected++;
        }

        return true;
    }
}
