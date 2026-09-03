<?php

/**
 * HTTP client for SmartUCF sucfOnlineSessionStart.
 */
final class MtUniCreditSmartUcfSessionClient
{
    const HTTP_TIMEOUT_SECONDS = 10;

    /** @var MtUniCreditSmartUcfPayloadBuilder */
    private $payloadBuilder;

    /** @var MtUniCreditSmartUcfEndpointPolicy */
    private $endpointPolicy;

    /** @var callable|null function(array $curlOptions): array{body:string,error:string,http_code:int} */
    private $httpExecutor;

    /**
     * @param MtUniCreditSmartUcfPayloadBuilder|null $payloadBuilder
     * @param MtUniCreditSmartUcfEndpointPolicy|null $endpointPolicy
     * @param callable|null $httpExecutor
     */
    public function __construct($payloadBuilder = null, $endpointPolicy = null, $httpExecutor = null)
    {
        $this->payloadBuilder = $payloadBuilder instanceof MtUniCreditSmartUcfPayloadBuilder
            ? $payloadBuilder
            : new MtUniCreditSmartUcfPayloadBuilder();
        $this->endpointPolicy = $endpointPolicy instanceof MtUniCreditSmartUcfEndpointPolicy
            ? $endpointPolicy
            : new MtUniCreditSmartUcfEndpointPolicy();
        $this->httpExecutor = is_callable($httpExecutor) ? $httpExecutor : null;
    }

    /**
     * @param array<string, mixed> $shop
     * @param array<string, mixed> $order
     * @param array<int, array<string, mixed>> $orderProducts
     * @param MtUniCreditCalculationResult $calculation
     * @param int|string $localOrderId
     * @param string|null $certPath
     * @param string|null $keyPath
     * @param string $passphrase
     * @return array{session_id: string, redirect_url: string, http_code: int, raw_request: string, raw_response: string, endpoint: string}
     */
    public function createSession(
        array $shop,
        array $order,
        array $orderProducts,
        MtUniCreditCalculationResult $calculation,
        $localOrderId,
        $certPath = null,
        $keyPath = null,
        $passphrase = ''
    ) {
        try {
            $url = $this->endpointPolicy->buildSessionStartUrl($this->serviceUrl($shop));
            $application = $this->endpointPolicy->assertTrustedApplicationBase($this->applicationUrl($shop));
            $payload = $this->payloadBuilder->build($shop, $order, $orderProducts, $calculation, $localOrderId);
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                throw new RuntimeException('SmartUCF payload could not be encoded as JSON.');
            }
        } catch (Throwable $exception) {
            throw new MtUniCreditSmartUcfSessionException(
                'SmartUCF request could not be prepared.',
                true,
                '',
                0,
                MtUniCreditSmartUcfSessionException::KIND_PRE_SEND,
                $exception
            );
        }

        $options = array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_TIMEOUT => self::HTTP_TIMEOUT_SECONDS,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'cache-control: no-cache'),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        );

        if (MtUniCreditShopConfigurationFlags::usesSmartUcfCertificate($shop)) {
            if ($certPath === null || $keyPath === null || $certPath === '' || $keyPath === '') {
                throw new MtUniCreditSmartUcfSessionException(
                    'SmartUCF SSL key or certificate path was not provided.',
                    true,
                    '',
                    0,
                    MtUniCreditSmartUcfSessionException::KIND_PRE_SEND
                );
            }
            if (!is_readable($keyPath) || !is_readable($certPath)) {
                throw new MtUniCreditSmartUcfSessionException(
                    'SmartUCF SSL key or certificate is missing or unreadable.',
                    true,
                    '',
                    0,
                    MtUniCreditSmartUcfSessionException::KIND_PRE_SEND
                );
            }
            $options[CURLOPT_SSLKEY] = $keyPath;
            $options[CURLOPT_SSLKEYPASSWD] = (string) $passphrase;
            $options[CURLOPT_SSLCERT] = $certPath;
            $options[CURLOPT_SSLCERTPASSWD] = (string) $passphrase;
            $options[CURLOPT_SSLVERSION] = CURL_SSLVERSION_TLSv1_2;
        }

        $executed = $this->executeHttp($options);
        $raw = isset($executed['body']) ? (string) $executed['body'] : '';
        $error = isset($executed['error']) ? (string) $executed['error'] : '';
        $httpCode = isset($executed['http_code']) ? (int) $executed['http_code'] : 0;

        if ($error !== '' || $raw === '') {
            throw new MtUniCreditSmartUcfSessionException(
                $error !== '' ? 'SmartUCF connection failed: ' . $error : 'SmartUCF returned an empty response.',
                false,
                $raw !== '' ? $raw : $error,
                $httpCode,
                MtUniCreditSmartUcfSessionException::KIND_TRANSPORT
            );
        }

        $decoded = json_decode($raw);
        if (!is_object($decoded)) {
            throw new MtUniCreditSmartUcfSessionException(
                'SmartUCF returned invalid JSON.',
                false,
                $raw,
                $httpCode,
                MtUniCreditSmartUcfSessionException::KIND_TRANSPORT
            );
        }
        $sessionId = trim((string) (isset($decoded->sucfOnlineSessionID) ? $decoded->sucfOnlineSessionID : ''));
        if ($sessionId === '') {
            throw new MtUniCreditSmartUcfSessionException(
                'SmartUCF did not return a session identifier.',
                false,
                $raw,
                $httpCode,
                $this->detectFailureKind($raw, $httpCode)
            );
        }

        try {
            $redirect = $this->endpointPolicy->buildApplicationRedirect($application, $sessionId);
        } catch (InvalidArgumentException $exception) {
            throw new MtUniCreditSmartUcfSessionException(
                'SmartUCF session redirect could not be built safely.',
                false,
                $raw,
                $httpCode,
                MtUniCreditSmartUcfSessionException::KIND_TRANSPORT,
                $exception
            );
        }

        return array(
            'session_id' => $sessionId,
            'redirect_url' => $redirect,
            'http_code' => $httpCode,
            'raw_request' => $json,
            'raw_response' => $raw,
            'endpoint' => $url,
        );
    }

    /**
     * @param array<int, mixed> $options
     * @return array{body: string, error: string, http_code: int}
     */
    private function executeHttp(array $options)
    {
        if ($this->httpExecutor !== null) {
            $result = call_user_func($this->httpExecutor, $options);
            if (!is_array($result)) {
                throw new MtUniCreditSmartUcfSessionException(
                    'SmartUCF HTTP executor returned an invalid result.',
                    true,
                    '',
                    0,
                    MtUniCreditSmartUcfSessionException::KIND_PRE_SEND
                );
            }

            return array(
                'body' => isset($result['body']) ? (string) $result['body'] : '',
                'error' => isset($result['error']) ? (string) $result['error'] : '',
                'http_code' => isset($result['http_code']) ? (int) $result['http_code'] : 0,
            );
        }

        $handle = curl_init();
        if ($handle === false) {
            throw new MtUniCreditSmartUcfSessionException(
                'SmartUCF HTTP client could not be initialized.',
                true,
                '',
                0,
                MtUniCreditSmartUcfSessionException::KIND_PRE_SEND
            );
        }
        curl_setopt_array($handle, $options);
        $response = curl_exec($handle);
        $error = curl_error($handle);
        $httpCode = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        return array(
            'body' => is_string($response) ? $response : '',
            'error' => (string) $error,
            'http_code' => $httpCode,
        );
    }

    /**
     * @param string $raw
     * @param int $httpCode
     * @return string
     */
    private function detectFailureKind($raw, $httpCode)
    {
        if ($this->hasStructuredBusinessError($raw)) {
            return ($httpCode === 0 || $httpCode >= 500)
                ? MtUniCreditSmartUcfSessionException::KIND_TRANSPORT
                : MtUniCreditSmartUcfSessionException::KIND_REMOTE;
        }

        $value = strtolower($raw);
        if ((strpos($value, 'duplicate') !== false && strpos($value, 'order') !== false)
            || strpos($value, 'already exists') !== false
            || strpos($value, 'съществува') !== false
        ) {
            return MtUniCreditSmartUcfSessionException::KIND_DUPLICATE;
        }

        return ($httpCode === 0 || $httpCode >= 500)
            ? MtUniCreditSmartUcfSessionException::KIND_TRANSPORT
            : MtUniCreditSmartUcfSessionException::KIND_REMOTE;
    }

    /**
     * @param string $raw
     * @return bool
     */
    private function hasStructuredBusinessError($raw)
    {
        $decoded = json_decode($raw);
        if (!is_object($decoded) || !property_exists($decoded, 'errorCode')) {
            return false;
        }

        $code = $decoded->errorCode;

        return $code !== null && $code !== '' && !(is_string($code) && trim($code) === '');
    }

    /**
     * @param array<string, mixed> $shop
     * @return string
     */
    private function serviceUrl(array $shop)
    {
        return MtUniCreditShopConfigurationFlags::isTestEnvironment($shop)
            ? trim((string) (isset($shop['uni_test_service']) ? $shop['uni_test_service'] : ''))
            : trim((string) (isset($shop['uni_production_service']) ? $shop['uni_production_service'] : ''));
    }

    /**
     * @param array<string, mixed> $shop
     * @return string
     */
    private function applicationUrl(array $shop)
    {
        return MtUniCreditShopConfigurationFlags::isTestEnvironment($shop)
            ? trim((string) (isset($shop['uni_test_application']) ? $shop['uni_test_application'] : ''))
            : trim((string) (isset($shop['uni_production_application']) ? $shop['uni_production_application'] : ''));
    }
}
