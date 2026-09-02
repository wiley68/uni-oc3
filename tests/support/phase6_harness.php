<?php

require_once __DIR__ . '/phase2_memory_db.php';

/**
 * Phase 6 inbound API offline harness.
 */
final class Phase6TestHarness
{
    const STORE_A = 900001;

    const STORE_B = 900002;

    const NOW = 1787380000;

    /**
     * @param Phase2MemoryDb|null $memoryDb
     * @param int $storeId
     * @return array<string, mixed>
     */
    public static function stack($memoryDb = null, $storeId = self::STORE_A)
    {
        $memoryDb = $memoryDb !== null ? $memoryDb : new Phase2MemoryDb();
        $clock = function () {
            return self::NOW;
        };
        $db = new MtUniCreditDbAdapter($memoryDb, 'oc_');
        $settings = new MtUniCreditSettingStore($db, MtUniCreditConstants::MODULE_SETTINGS_CODE);
        Phase4TestHarness::prepareCredentials($settings, $storeId);
        $settings->set($storeId, MtUniCreditConstants::MODULE_SETTING_STATUS, '1');

        $credentials = MtUniCreditBootstrap::credentialsRepositoryFromDb($db);
        $nonces = new MtUniCreditApiNonceRepository($db, new MtUniCreditPersistenceClock($clock));
        $authenticator = new MtUniCreditRequestAuthenticator(
            $credentials,
            $nonces,
            $storeId,
            true,
            new MtUniCreditRequestSignatureVerifier($clock)
        );

        return array(
            'memoryDb' => $memoryDb,
            'db' => $db,
            'settings' => $settings,
            'credentials' => $credentials,
            'authenticator' => $authenticator,
            'storeId' => $storeId,
            'secret' => Phase4TestHarness::TEST_SECRET,
            'unicid' => Phase4TestHarness::TEST_UNICID,
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $stack
     * @param array<string, string> $headerOverrides
     * @param string|null $rawBodyOverride
     * @return array{status: int, body: string, payload: array<string, mixed>|null}
     */
    public static function dispatch(array $payload, array $stack, array $headerOverrides = array(), $rawBodyOverride = null)
    {
        $rawBody = $rawBodyOverride !== null
            ? $rawBodyOverride
            : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers = self::signedHeaders($stack['secret'], $rawBody, $headerOverrides);

        try {
            $result = MtUniCreditInboundApiDispatcher::dispatch(
                function ($decoded) {
                    return $decoded;
                },
                $stack['authenticator'],
                self::serverFromHeaders($headers),
                $rawBody,
                'POST'
            );
            $encoded = MtUniCreditInboundApiDispatcher::encodeResponse(array('success' => true, 'data' => $result), 200);
        } catch (MtUniCreditInboundApiException $exception) {
            $encoded = MtUniCreditInboundApiDispatcher::encodeException($exception);
        }

        $decoded = json_decode($encoded['body'], true);

        return array(
            'status' => (int) $encoded['status'],
            'body' => (string) $encoded['body'],
            'payload' => is_array($decoded) ? $decoded : null,
        );
    }

    /**
     * @param string $secret
     * @param string $rawBody
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    public static function signedHeaders($secret, $rawBody, array $overrides = array())
    {
        $timestamp = isset($overrides['X-UniPayment-Timestamp'])
            ? (string) $overrides['X-UniPayment-Timestamp']
            : (string) self::NOW;
        $nonce = isset($overrides['X-UniPayment-Nonce'])
            ? (string) $overrides['X-UniPayment-Nonce']
            : str_repeat('a', 64);
        $signature = isset($overrides['X-UniPayment-Signature'])
            ? (string) $overrides['X-UniPayment-Signature']
            : MtUniCreditRequestSignatureProtocol::computeSignature($secret, $timestamp, $nonce, $rawBody);

        return array_merge(array(
            'X-UniPayment-Timestamp' => $timestamp,
            'X-UniPayment-Nonce' => $nonce,
            'X-UniPayment-Signature' => $signature,
        ), $overrides);
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    public static function serverFromHeaders(array $headers)
    {
        $server = array('REQUEST_METHOD' => 'POST');
        foreach ($headers as $name => $value) {
            $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
            $server[$key] = $value;
        }

        return $server;
    }

    /**
     * @param array<string, mixed> $stack
     * @param array<string, mixed> $shopData
     * @return void
     */
    public static function pushShopCache(array $stack, array $shopData)
    {
        $persistence = MtUniCreditBootstrap::shopCachePersistenceFromDb($stack['db']);
        $persistence->replaceValidatedSnapshot($stack['storeId'], $stack['unicid'], $shopData);
    }
}
