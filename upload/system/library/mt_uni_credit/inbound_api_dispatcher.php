<?php

/**
 * Shared POST/JSON/HMAC lifecycle for catalog inbound CP API controllers.
 */
final class MtUniCreditInboundApiDispatcher
{
    const MAX_RAW_BODY_BYTES = 1048576;

    /**
     * @param callable $handler
     * @param MtUniCreditRequestAuthenticator $authenticator
     * @param array<string, mixed> $server
     * @param string $rawBody
     * @param string $requestMethod
     * @param string|null $expectedOperation
     * @return array<string, mixed>
     */
    public static function dispatch(
        $handler,
        MtUniCreditRequestAuthenticator $authenticator,
        array $server,
        $rawBody,
        $requestMethod,
        $expectedOperation = null
    ) {
        if (strtoupper((string) $requestMethod) !== 'POST') {
            throw new MtUniCreditInboundApiException('Разрешени са само POST заявки.', 405, 'method_not_allowed');
        }

        if ($rawBody === '') {
            throw new MtUniCreditInboundApiException('Изисква се JSON тяло на заявката.', 400, 'invalid_payload');
        }

        // Defense in depth — oversized bodies should already be rejected in the runner (413).
        if (strlen($rawBody) > self::MAX_RAW_BODY_BYTES) {
            throw new MtUniCreditInboundApiException(
                'Тялото на заявката надвишава допустимия размер.',
                413,
                'payload_too_large'
            );
        }

        // Authenticate exact raw body bytes before any JSON decode / payload validation.
        $headers = self::extractHeaders($server);
        $authenticatedUnicid = $authenticator->authenticate($rawBody, $headers);

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            throw new MtUniCreditInboundApiException('JSON тялото на заявката е невалидно.', 400, 'malformed_json');
        }

        $unicid = $authenticator->finalizeAuthenticatedRequest($payload, $authenticatedUnicid, $headers);

        if ($expectedOperation !== null && $expectedOperation !== '') {
            MtUniCreditInboundApiOperations::assertExact($payload, (string) $expectedOperation);
        }

        return call_user_func($handler, $payload, $unicid);
    }

    /**
     * @param array<string, mixed> $server
     * @return array<string, string>
     */
    public static function extractHeaders(array $server)
    {
        $headers = array();

        if (function_exists('getallheaders')) {
            $requestHeaders = getallheaders();
            if (is_array($requestHeaders)) {
                foreach ($requestHeaders as $name => $value) {
                    if (is_string($name) && is_string($value)) {
                        $headers[$name] = $value;
                    }
                }
            }
        }

        foreach ($server as $key => $value) {
            if (!is_string($value) || strpos((string) $key, 'HTTP_') !== 0) {
                continue;
            }
            $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr((string) $key, 5)))));
            $headers[$name] = $value;
        }

        return $headers;
    }

    /**
     * @param array<string, mixed> $payload
     * @param int $statusCode
     * @return array{status: int, body: string}
     */
    public static function encodeResponse(array $payload, $statusCode)
    {
        $normalized = MtUniCreditInboundApiEnvelope::forJsonEncode($payload, ((int) $statusCode) < 400);
        $body = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            $fallback = MtUniCreditInboundApiEnvelope::forJsonEncode(
                MtUniCreditInboundApiEnvelope::failure(
                    'internal_error',
                    'Модулът не можа да кодира отговора.'
                ),
                false
            );

            return array(
                'status' => 500,
                'body' => (string) json_encode($fallback, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            );
        }

        return array(
            'status' => (int) $statusCode,
            'body' => (string) $body,
        );
    }

    /**
     * @param MtUniCreditInboundApiException $exception
     * @return array{status: int, body: string}
     */
    public static function encodeException(MtUniCreditInboundApiException $exception)
    {
        $error = $exception->getErrorCode();
        if ($error === null || $error === '') {
            switch ((int) $exception->getStatusCode()) {
                case 400:
                    $error = 'bad_request';
                    break;
                case 401:
                    $error = 'authentication_failed';
                    break;
                case 403:
                    $error = 'module_disabled';
                    break;
                case 404:
                    $error = 'not_found';
                    break;
                case 405:
                    $error = 'method_not_allowed';
                    break;
                case 409:
                    $error = 'conflict';
                    break;
                case 413:
                    $error = 'payload_too_large';
                    break;
                case 422:
                    $error = 'unprocessable_entity';
                    break;
                default:
                    $error = 'internal_error';
                    break;
            }
        }

        return self::encodeResponse(
            MtUniCreditInboundApiEnvelope::failure(
                $error,
                $exception->getMessage(),
                $exception->getResponseData()
            ),
            $exception->getStatusCode()
        );
    }

    /**
     * @param int $status
     * @return string
     */
    public static function httpStatusLine($status)
    {
        switch ((int) $status) {
            case 200:
                return '200 OK';
            case 201:
                return '201 Created';
            case 400:
                return '400 Bad Request';
            case 401:
                return '401 Unauthorized';
            case 403:
                return '403 Forbidden';
            case 404:
                return '404 Not Found';
            case 405:
                return '405 Method Not Allowed';
            case 409:
                return '409 Conflict';
            case 413:
                return '413 Payload Too Large';
            case 422:
                return '422 Unprocessable Entity';
            case 500:
                return '500 Internal Server Error';
            default:
                return (int) $status . ' Error';
        }
    }
}
