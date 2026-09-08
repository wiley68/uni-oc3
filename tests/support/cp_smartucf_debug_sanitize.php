<?php

/**
 * Intended CP ShopModuleSmartUcfDebugFetcher sanitize contract (AUD-028-F03).
 *
 * Production CP source (READ-ONLY in this workspace):
 *   uni.avalonbg.com/app/Support/ShopModuleSmartUcfDebugFetcher.php
 *
 * This mirror encodes the coordinated fix: preserve safe module envelope metadata
 * (transport_error, operation, endpoint, outcome, type) without synthesizing
 * SmartUCF success/session fields.
 *
 * PHP 7.3 compatible.
 */

/**
 * @param array<string, mixed> $storeResponse Module smartucf_debug_log success envelope
 * @return array<string, mixed>
 */
function mtuc_cp_smartucf_debug_sanitize(array $storeResponse)
{
    $data = isset($storeResponse['data']) && is_array($storeResponse['data'])
        ? $storeResponse['data']
        : array();
    $log = isset($data['log']) && is_array($data['log']) ? $data['log'] : null;

    $scalarString = function ($value) {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    };
    $scalarHttp = function ($value) use ($scalarString) {
        if (is_int($value) || is_float($value)) {
            return (int) $value;
        }
        $text = $scalarString($value);
        if ($text !== null && ctype_digit($text)) {
            return (int) $text;
        }

        return $text;
    };

    $sanitizedLog = null;
    if ($log !== null) {
        $sanitizedLog = array(
            'order_id' => $scalarString(isset($log['order_id']) ? $log['order_id'] : null),
            'entry_point' => $scalarString(isset($log['entry_point']) ? $log['entry_point'] : null),
            'event_code' => $scalarString(isset($log['event_code']) ? $log['event_code'] : null),
            'http_status' => $scalarHttp(
                isset($log['http_status'])
                    ? $log['http_status']
                    : (isset($log['http_code']) ? $log['http_code'] : null)
            ),
            'http_code' => $scalarHttp(
                isset($log['http_code'])
                    ? $log['http_code']
                    : (isset($log['http_status']) ? $log['http_status'] : null)
            ),
            'summary' => $scalarString(isset($log['summary']) ? $log['summary'] : null),
            'created_at' => $scalarString(
                isset($log['created_at'])
                    ? $log['created_at']
                    : (isset($log['created_at_site']) ? $log['created_at_site'] : null)
            ),
            'created_at_site' => $scalarString(
                isset($log['created_at_site'])
                    ? $log['created_at_site']
                    : (isset($log['created_at']) ? $log['created_at'] : null)
            ),
            // AUD-028-F03: preserve safe operational envelope from module.
            'type' => $scalarString(isset($log['type']) ? $log['type'] : null),
            'operation' => $scalarString(isset($log['operation']) ? $log['operation'] : null),
            'endpoint' => array_key_exists('endpoint', $log) ? $log['endpoint'] : null,
            'outcome' => $scalarString(isset($log['outcome']) ? $log['outcome'] : null),
            'transport_error' => array_key_exists('transport_error', $log) ? $log['transport_error'] : null,
            'request' => array_key_exists('request', $log) ? $log['request'] : null,
            'response' => array_key_exists('response', $log) ? $log['response'] : null,
        );
    }

    return array(
        'success' => true,
        'data' => array(
            'order_id' => $scalarString(isset($data['order_id']) ? $data['order_id'] : null),
            'oc_order_id' => $scalarString(isset($data['oc_order_id']) ? $data['oc_order_id'] : null),
            'wc_order_id' => $scalarHttp(isset($data['wc_order_id']) ? $data['wc_order_id'] : null),
            'log' => $sanitizedLog,
        ),
    );
}
