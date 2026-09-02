<?php

final class MtUniCreditCurlCpHttpTransport implements MtUniCreditCpHttpTransport
{
    /** @var int */
    private $connectTimeout;

    /** @var int */
    private $timeout;

    /**
     * @param int|null $connectTimeout
     * @param int|null $timeout
     */
    public function __construct($connectTimeout = null, $timeout = null)
    {
        $this->connectTimeout = $connectTimeout !== null
            ? (int) $connectTimeout
            : MtUniCreditCpHttpConstants::CONNECT_TIMEOUT_SECONDS;
        $this->timeout = $timeout !== null
            ? (int) $timeout
            : MtUniCreditCpHttpConstants::TOTAL_TIMEOUT_SECONDS;
    }

    /**
     * @param string $method
     * @param string $url
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $payload
     * @return MtUniCreditCpHttpResponse
     */
    public function request($method, $url, array $headers, $payload)
    {
        if (!function_exists('curl_init')) {
            throw new MtUniCreditCpConnectionException('The cURL PHP extension is not available.');
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new MtUniCreditCpConnectionException('The Control Panel request could not be initialized.');
        }

        $headerLines = array();
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $options = array(
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headerLines,
        );

        if ($payload !== null) {
            try {
                $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                curl_close($handle);
                throw new MtUniCreditCpConnectionException(
                    'The Control Panel request payload could not be encoded.',
                    0,
                    $exception
                );
            }
        }

        curl_setopt_array($handle, $options);
        $body = curl_exec($handle);

        if ($body === false) {
            $errorNumber = curl_errno($handle);
            $error = curl_error($handle);
            curl_close($handle);

            if ($errorNumber === CURLE_OPERATION_TIMEDOUT) {
                throw new MtUniCreditCpTimeoutException('The Control Panel request timed out.');
            }

            throw new MtUniCreditCpConnectionException('The Control Panel connection failed: ' . $error);
        }

        if (strlen((string) $body) > MtUniCreditCpHttpConstants::MAX_RESPONSE_BYTES) {
            curl_close($handle);
            throw new MtUniCreditCpInvalidPayloadException('The Control Panel response exceeded the allowed size.');
        }

        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return new MtUniCreditCpHttpResponse($statusCode, (string) $body);
    }
}
