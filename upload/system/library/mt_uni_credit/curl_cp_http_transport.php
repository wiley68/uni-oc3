<?php

final class MtUniCreditCurlCpHttpTransport implements MtUniCreditCpHttpTransport
{
    /** @var int */
    private $connectTimeout;

    /** @var int */
    private $timeout;

    /** @var callable|null Offline DNS probe injection; never a destination authority. */
    private $dnsResolver;

    /**
     * @param int|null $connectTimeout
     * @param int|null $timeout
     * @param callable|null $dnsResolver Offline resolver injection (records, never a URL).
     */
    public function __construct($connectTimeout = null, $timeout = null, $dnsResolver = null)
    {
        $this->connectTimeout = $connectTimeout !== null
            ? (int) $connectTimeout
            : MtUniCreditCpHttpConstants::CONNECT_TIMEOUT_SECONDS;
        $this->timeout = $timeout !== null
            ? (int) $timeout
            : MtUniCreditCpHttpConstants::TOTAL_TIMEOUT_SECONDS;
        $this->dnsResolver = is_callable($dnsResolver) ? $dnsResolver : null;
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
            throw new MtUniCreditCpConnectionException('The cURL PHP extension is not available.', 0, null, false, 'curl_unavailable');
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new MtUniCreditCpConnectionException('The Control Panel request could not be initialized.', 0, null, false);
        }

        $headerLines = array();
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $buffer = new MtUniCreditCpBoundedResponseBuffer();
        $options = array(
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use ($buffer) {
                return $buffer->write($chunk);
            },
        );

        if ($payload !== null) {
            try {
                $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                curl_close($handle);
                throw new MtUniCreditCpConnectionException(
                    'The Control Panel request payload could not be encoded.',
                    0,
                    $exception,
                    false,
                    'payload_invalid'
                );
            }
        }

        // The environment chooses the host. Resolve and pin a public address; do not let
        // redirects, proxies or a second DNS lookup turn it into an internal destination.
        try {
            $host = parse_url($url, PHP_URL_HOST);
            $addresses = (new MtUniCreditCpDestinationPolicy())->resolvePublicAddresses($host, $this->dnsResolver);
        } catch (MtUniCreditCpDestinationException $exception) {
            curl_close($handle);
            throw new MtUniCreditCpConfigurationException('The CP destination resolves to an unsafe network.', 0, $exception, 'destination_unsafe');
        } catch (Throwable $exception) {
            curl_close($handle);
            throw $exception;
        }
        $pinned = array();
        foreach ($addresses as $address) {
            $pinned[] = strpos($address, ':') !== false ? '[' . $address . ']' : $address;
        }
        // Multiple pinned answers retain cURL's connection failover on libcurl 7.59+.
        // Older PHP 7.3 deployments still pin one address without requiring a newer libcurl.
        $curlVersion = function_exists('curl_version') ? curl_version() : array();
        $addressList = isset($curlVersion['version_number']) && $curlVersion['version_number'] >= 0x073B00
            ? implode(',', $pinned) : $pinned[0];
        $options[CURLOPT_RESOLVE] = array($host . ':443:' . $addressList);
        $options[CURLOPT_PROXY] = '';

        curl_setopt_array($handle, $options);
        $executed = curl_exec($handle);

        // Intentional size abort must win over CURLE_WRITE_ERROR / false exec result.
        if ($buffer->isAbortedForSize()) {
            curl_close($handle);
            throw new MtUniCreditCpInvalidPayloadException('The Control Panel response exceeded the allowed size.');
        }

        if ($executed === false) {
            $errorNumber = curl_errno($handle);
            $error = curl_error($handle);
            curl_close($handle);

            if ($errorNumber === CURLE_OPERATION_TIMEDOUT) {
                throw new MtUniCreditCpTimeoutException('The Control Panel request timed out.', 0, null, true, 'timeout');
            }

            $reason = 'transport_failure';
            if (in_array($errorNumber, array(CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_RESOLVE_PROXY), true)) {
                $reason = 'dns_failure';
            } else {
                foreach (array('CURLE_SSL_CONNECT_ERROR', 'CURLE_PEER_FAILED_VERIFICATION', 'CURLE_SSL_CERTPROBLEM', 'CURLE_SSL_CIPHER', 'CURLE_SSL_CACERT_BADFILE') as $constant) {
                    if (defined($constant) && $errorNumber === constant($constant)) {
                        $reason = 'tls_failure';
                        break;
                    }
                }
            }

            throw new MtUniCreditCpConnectionException('The Control Panel connection failed: ' . $error, 0, null, true, $reason);
        }

        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return new MtUniCreditCpHttpResponse($statusCode, $buffer->getBody());
    }
}
