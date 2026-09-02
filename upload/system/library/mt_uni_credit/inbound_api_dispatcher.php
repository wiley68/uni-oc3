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
     * @return array<string, mixed>
     */
    public static function dispatch(
        $handler,
        MtUniCreditRequestAuthenticator $authenticator,
        array $server,
        $rawBody,
        $requestMethod
    ) {
        if (strtoupper((string) $requestMethod) !== 'POST') {
            throw new MtUniCreditInboundApiException('Разрешени са само POST заявки.', 405, 'method_not_allowed');
        }

        if ($rawBody === '') {
            throw new MtUniCreditInboundApiException('Изисква се JSON тяло на заявката.', 400, 'invalid_payload');
        }

        if (strlen($rawBody) > self::MAX_RAW_BODY_BYTES) {
            throw new MtUniCreditInboundApiException('JSON тялото на заявката е твърде голямо.', 400, 'payload_too_large');
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            throw new MtUniCreditInboundApiException('JSON тялото на заявката е невалидно.', 400, 'malformed_json');
        }

        $headers = self::extractHeaders($server);
        $unicid = $authenticator->authenticate($payload, $rawBody, $headers);

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
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            return array(
                'status' => 500,
                'body' => '{"success":false,"message":"Модулът не можа да кодира отговора."}',
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
        $payload = array(
            'success' => false,
            'message' => $exception->getMessage(),
        );
        if ($exception->getErrorCode() !== null) {
            $payload['error'] = $exception->getErrorCode();
        }
        if ($exception->getResponseData() !== null) {
            $payload['data'] = $exception->getResponseData();
        }

        return self::encodeResponse($payload, $exception->getStatusCode());
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
            case 422:
                return '422 Unprocessable Entity';
            default:
                return (int) $status . ' Error';
        }
    }
}
