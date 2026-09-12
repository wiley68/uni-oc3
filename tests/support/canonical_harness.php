<?php

/**
 * Shared helpers for canonical CP↔OC3 offline checks.
 *
 * Require AFTER upload/system/library/mt_uni_credit/bootstrap.php and
 * tests/support/phase2_memory_db.php / phase4–7 harnesses as needed by the caller.
 */
final class CanonicalTestHarness
{
    /**
     * @param bool $condition
     * @param string $message
     * @param array<int, string> $failures
     * @param int $passes
     * @return void
     */
    public static function assert(bool $condition, string $message, array &$failures, int &$passes): void
    {
        if ($condition) {
            $passes++;
            echo 'PASS  ' . $message . PHP_EOL;

            return;
        }
        $failures[] = $message;
        echo 'FAIL  ' . $message . PHP_EOL;
    }

    /**
     * Canonical failure envelope for fake CP HTTP errors.
     *
     * @param string $error
     * @param string $message
     * @param array<string, mixed>|null $data
     * @return array<string, mixed>
     */
    public static function failureEnvelope(string $error, string $message, $data = null): array
    {
        return array(
            'success' => false,
            'error' => $error,
            'message' => $message,
            'data' => $data === null ? new stdClass() : $data,
        );
    }

    /**
     * @param array<string, mixed> $data
     * @param string $message
     * @return array<string, mixed>
     */
    public static function successEnvelope(array $data, string $message = 'ok'): array
    {
        return array(
            'success' => true,
            'error' => null,
            'message' => $message,
            'data' => $data,
        );
    }

    /**
     * Invoke production-shaped order_bank_status through dispatcher + operation binding.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $stack
     * @param array<string, string> $headerOverrides
     * @param string|null $rawBodyOverride
     * @param array<string, mixed>|null $serverOverrides
     * @return array{status: int, body: string, payload: array<string, mixed>|null}
     */
    public static function invokeOrderBankStatus(
        array $payload,
        array $stack,
        array $headerOverrides = array(),
        $rawBodyOverride = null,
        $serverOverrides = null
    ): array {
        return self::invokeBoundEndpoint(
            MtUniCreditInboundApiOperations::ORDER_BANK_STATUS,
            function (array $body, $unicid) use ($stack) {
                $orderId = isset($body['order_id']) ? $body['order_id'] : null;
                if (!is_string($orderId)) {
                    throw new MtUniCreditInboundApiException('Полето order_id е задължително.', 400, 'invalid_payload');
                }
                $orderId = trim($orderId);
                if ($orderId === '' || strlen($orderId) > 13) {
                    throw new MtUniCreditInboundApiException('Полето order_id е невалидно.', 400, 'invalid_payload');
                }
                $statusId = isset($body['status_id']) ? $body['status_id'] : null;
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
                $status = isset($body['status']) ? $body['status'] : null;
                if (!is_string($status)) {
                    throw new MtUniCreditInboundApiException('Полето status е задължително.', 400, 'invalid_payload');
                }
                $status = trim($status);
                if ($status === '' || strlen($status) > 255) {
                    throw new MtUniCreditInboundApiException('Полето status е невалидно.', 400, 'invalid_payload');
                }

                try {
                    $result = (new MtUniCreditOrderBankStatusRepository($stack['db']))->updateByOrderIdentifier(
                        (int) $stack['storeId'],
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
                        'order_ambiguous'
                    );
                } catch (MtUniCreditOrderBankStatusSemanticConflictException $exception) {
                    throw new MtUniCreditInboundApiException(
                        'Несъвместима промяна на банков статус.',
                        409,
                        'semantic_conflict'
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
            },
            $payload,
            $stack,
            $headerOverrides,
            $rawBodyOverride,
            $serverOverrides
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $stack
     * @param array<string, string> $headerOverrides
     * @return array{status: int, body: string, payload: array<string, mixed>|null}
     */
    public static function invokeSmartUcfDebug(array $payload, array $stack, array $headerOverrides = array()): array
    {
        return self::invokeBoundEndpoint(
            MtUniCreditInboundApiOperations::SMARTUCF_DEBUG_LOG,
            function (array $body, $unicid) use ($stack) {
                $orderIdRaw = isset($body['order_id']) ? $body['order_id'] : null;
                if (!is_string($orderIdRaw)) {
                    throw new MtUniCreditInboundApiException('Полето order_id е задължително.', 400, 'invalid_payload');
                }
                $orderIdRaw = trim($orderIdRaw);
                if ($orderIdRaw === '' || strlen($orderIdRaw) > 13 || !ctype_digit($orderIdRaw)) {
                    throw new MtUniCreditInboundApiException('Полето order_id е невалидно.', 400, 'invalid_payload');
                }

                $storeId = (int) $stack['storeId'];
                $db = $stack['db'];

                try {
                    $resolved = (new MtUniCreditFinancingOrderResolver($db))->resolve($storeId, $unicid, $orderIdRaw);
                } catch (MtUniCreditFinancingOrderAmbiguousException $exception) {
                    throw new MtUniCreditInboundApiException(
                        'Не е намерена диагностична информация за тази поръчка.',
                        404,
                        'order_not_found'
                    );
                }

                if ($resolved === null) {
                    throw new MtUniCreditInboundApiException(
                        'Не е намерена диагностична информация за тази поръчка.',
                        404,
                        'order_not_found'
                    );
                }

                $attempt = $resolved['attempt'];
                $orderId = $resolved['order_id'];
                $bank = (new MtUniCreditOrderBankStatusRepository($db))->findCurrentStatus($storeId, $orderId);
                if (
                    is_array($bank)
                    && (string) (isset($bank['status_id']) ? $bank['status_id'] : '') === MtUniCreditBankStatus::SENT_PROCESS2
                ) {
                    throw new MtUniCreditInboundApiException(
                        'Не е намерена диагностична информация за тази поръчка.',
                        404,
                        'order_not_found'
                    );
                }
                $smartucfState = isset($attempt['smartucf_state']) ? (string) $attempt['smartucf_state'] : '';
                if ($smartucfState === '' || $smartucfState === MtUniCreditSmartUcfLifecycleStates::NOT_STARTED) {
                    throw new MtUniCreditInboundApiException(
                        'Не е намерена диагностична информация за тази поръчка.',
                        404,
                        'order_not_found'
                    );
                }

                $log = (new MtUniCreditDiagnosticDebugLogRepository($db))->findLatestSmartUcfSessionByOrderId(
                    $storeId,
                    $orderId
                );
                if ($log === null) {
                    throw new MtUniCreditInboundApiException(
                        'Не е намерена диагностична информация за тази поръчка.',
                        404,
                        'order_not_found'
                    );
                }

                return array(
                    'success' => true,
                    'message' => 'Диагностичният запис е намерен.',
                    'data' => array(
                        'order_id' => $orderIdRaw,
                        'oc_order_id' => $orderId,
                        'log' => $log,
                    ),
                );
            },
            $payload,
            $stack,
            $headerOverrides
        );
    }

    /**
     * @param string $expectedOperation
     * @param callable $handler
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $stack
     * @param array<string, string> $headerOverrides
     * @param string|null $rawBodyOverride
     * @param array<string, mixed>|null $serverOverrides
     * @return array{status: int, body: string, payload: array<string, mixed>|null}
     */
    public static function invokeBoundEndpoint(
        string $expectedOperation,
        $handler,
        array $payload,
        array $stack,
        array $headerOverrides = array(),
        $rawBodyOverride = null,
        $serverOverrides = null
    ): array {
        if ($rawBodyOverride === null && !isset($payload['unicid'])) {
            $payload['unicid'] = (string) $stack['unicid'];
        }

        if (!isset($headerOverrides['X-UniPayment-Nonce'])) {
            static $nonceSeq = 0;
            $nonceSeq++;
            $headerOverrides['X-UniPayment-Nonce'] = str_pad(dechex($nonceSeq), 64, '0', STR_PAD_LEFT);
        }

        $rawBody = $rawBodyOverride !== null
            ? (string) $rawBodyOverride
            : (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers = Phase6TestHarness::signedHeaders($stack['secret'], $rawBody, $headerOverrides);
        $server = Phase6TestHarness::serverFromHeaders($headers);
        $server['REQUEST_METHOD'] = 'POST';
        if (is_array($serverOverrides)) {
            foreach ($serverOverrides as $key => $value) {
                $server[$key] = $value;
            }
        }

        try {
            if (strlen($rawBody) > MtUniCreditBoundedRawBodyReader::MAX_INBOUND_BYTES) {
                throw new MtUniCreditInboundApiException(
                    'Тялото на заявката надвишава допустимия размер.',
                    413,
                    'payload_too_large'
                );
            }

            $result = MtUniCreditInboundApiDispatcher::dispatch(
                $handler,
                $stack['authenticator'],
                $server,
                $rawBody,
                'POST',
                $expectedOperation
            );
            $encoded = MtUniCreditInboundApiDispatcher::encodeResponse($result, 200);
        } catch (MtUniCreditInboundApiException $exception) {
            $encoded = MtUniCreditInboundApiDispatcher::encodeException($exception);
        } catch (Exception $exception) {
            $encoded = MtUniCreditInboundApiDispatcher::encodeResponse(
                MtUniCreditInboundApiEnvelope::failure('internal_error', 'Модулът не можа да обработи заявката.'),
                500
            );
        }

        $decoded = json_decode($encoded['body'], true);

        return array(
            'status' => (int) $encoded['status'],
            'body' => (string) $encoded['body'],
            'payload' => is_array($decoded) ? $decoded : null,
        );
    }

    /**
     * @param Phase2MemoryDb $memoryDb
     * @param MtUniCreditControlPanelOrderStatusPort $cpClient
     * @return MtUniCreditControlPanelStatusSyncService
     */
    public static function statusSync(Phase2MemoryDb $memoryDb, $cpClient): MtUniCreditControlPanelStatusSyncService
    {
        $db = new MtUniCreditDbAdapter($memoryDb, 'oc_');

        return new MtUniCreditControlPanelStatusSyncService(
            new MtUniCreditControlPanelStatusSyncRepository($db),
            $cpClient
        );
    }
}

/**
 * Controllable CP status port for durable sync unit checks.
 */
final class CanonicalRecordingStatusPort implements MtUniCreditControlPanelOrderStatusPort
{
    /** @var array<int, array<string, mixed>> */
    public $calls = array();

    /** @var callable|null */
    public $handler;

    /**
     * @param string $shopOrderId
     * @param string $statusLabel
     * @param string $statusId
     * @return array<string, mixed>
     */
    public function updateOrderStatus($shopOrderId, $statusLabel, $statusId)
    {
        $this->calls[] = array(
            'order_id' => (string) $shopOrderId,
            'status' => (string) $statusLabel,
            'status_id' => (string) $statusId,
        );
        if (is_callable($this->handler)) {
            return call_user_func($this->handler, $shopOrderId, $statusLabel, $statusId);
        }

        return CanonicalTestHarness::successEnvelope(array(
            'id' => 1,
            'shop_id' => 1,
            'order_id' => (string) $shopOrderId,
            'status' => (string) $statusLabel,
            'status_id' => (string) $statusId,
            'updated_at' => '2024-01-01 00:00:00',
        ));
    }
}
