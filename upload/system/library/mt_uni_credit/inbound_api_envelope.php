<?php

/**
 * Canonical CP↔module JSON envelopes for inbound responses.
 *
 * Empty `data` is always a JSON object `{}` (stdClass), never a JSON array `[]`.
 */
final class MtUniCreditInboundApiEnvelope
{
    /**
     * @param string $message
     * @param array<string, mixed>|null $data
     * @return array{success: bool, error: null, message: string, data: array<string, mixed>|\stdClass}
     */
    public static function success($message, $data = null)
    {
        return array(
            'success' => true,
            'error' => null,
            'message' => (string) $message,
            'data' => self::objectData($data),
        );
    }

    /**
     * @param string $error
     * @param string $message
     * @param array<string, mixed>|null $data
     * @return array{success: bool, error: string, message: string, data: array<string, mixed>|\stdClass}
     */
    public static function failure($error, $message, $data = null)
    {
        $error = trim((string) $error);
        if ($error === '' || !preg_match('/\A[a-z0-9_]+\z/', $error)) {
            $error = 'internal_error';
        }

        return array(
            'success' => false,
            'error' => $error,
            'message' => (string) $message,
            'data' => self::objectData($data),
        );
    }

    /**
     * Normalize a handler/exception payload into the canonical four-field envelope.
     *
     * @param array<string, mixed> $payload
     * @param bool $successDefault
     * @return array{success: bool, error: string|null, message: string, data: array<string, mixed>|\stdClass}
     */
    public static function normalize(array $payload, $successDefault = true)
    {
        $success = array_key_exists('success', $payload)
            ? (bool) $payload['success']
            : (bool) $successDefault;

        $message = isset($payload['message']) && is_string($payload['message'])
            ? $payload['message']
            : ($success ? 'OK' : 'Модулът не можа да обработи заявката.');

        $data = self::objectData(
            isset($payload['data']) && is_array($payload['data'])
                ? $payload['data']
                : null
        );

        if ($success) {
            return array(
                'success' => true,
                'error' => null,
                'message' => $message,
                'data' => $data,
            );
        }

        $error = isset($payload['error']) && is_string($payload['error']) && $payload['error'] !== ''
            ? $payload['error']
            : 'internal_error';

        return self::failure($error, $message, is_array($data) ? $data : null);
    }

    /**
     * Ensure encode-ready payload: empty data is always `{}`.
     *
     * @param array<string, mixed> $envelope
     * @param bool $successDefault
     * @return array{success: bool, error: string|null, message: string, data: array<string, mixed>|\stdClass}
     */
    public static function forJsonEncode(array $envelope, $successDefault = true)
    {
        $normalized = self::normalize($envelope, $successDefault);
        if ($normalized['data'] instanceof stdClass) {
            return $normalized;
        }
        if (is_array($normalized['data']) && $normalized['data'] === array()) {
            $normalized['data'] = new stdClass();
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed>|null $data
     * @return array<string, mixed>|\stdClass
     */
    private static function objectData($data)
    {
        if ($data === null || $data === array()) {
            return new stdClass();
        }

        if (self::isList($data)) {
            // Canonical contract requires a JSON object, never a top-level list.
            return new stdClass();
        }

        return $data;
    }

    /**
     * PHP 7.3 polyfill for array_is_list().
     *
     * @param array<mixed> $array
     * @return bool
     */
    private static function isList(array $array)
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
