<?php

/** Payload-free CP diagnostics. Values originate from fixed identifiers and validated state. */
final class MtUniCreditCpOutboundDiagnostics
{
    public static function begin($method, $path, $validatedHost = null)
    {
        $operations = array(
            'POST /auth/login' => 'cp_login',
            'POST /auth/refresh' => 'cp_token_refresh',
            'POST /auth/logout' => 'cp_logout',
            'GET /shop' => 'cp_shop_refresh',
            'POST /orders' => 'cp_order_create',
            'PATCH /orders/status' => 'cp_order_status',
            'GET /ssl/certificate' => 'cp_certificate_metadata',
            'GET /ssl/certificate/bundle' => 'cp_certificate_bundle',
        );
        $identifier = strtoupper((string) $method) . ' ' . (string) $path;

        return array(
            'operation' => isset($operations[$identifier]) ? $operations[$identifier] : 'cp_operation',
            'endpoint' => isset($operations[$identifier]) ? '/api/v1' . $path : null,
            'phase' => 'local_validation',
            'transport_attempted' => false,
            'http_status' => null,
            'destination_hostname' => $validatedHost,
            'exception_class' => null,
            'reason_code' => null,
        );
    }

    public static function failure(array $state, Throwable $exception)
    {
        // Never inspect exception messages, nested error payloads or arbitrary URL components.
        $class = get_class($exception);
        $state['exception_class'] = preg_match('/^[A-Za-z0-9_\\\\]+$/D', $class) ? $class : 'Throwable';
        if ($exception instanceof MtUniCreditCpConfigurationException || $exception instanceof MtUniCreditCpDestinationException) {
            $state['phase'] = 'configuration';
            $state['transport_attempted'] = false;
            $state['reason_code'] = $exception->getDiagnosticReason();
        } elseif ($exception instanceof MtUniCreditCpConnectionException) {
            if ($exception->wasTransportAttempted() !== null) {
                $state['transport_attempted'] = $exception->wasTransportAttempted();
            }
            $state['phase'] = $state['transport_attempted'] ? 'transport' : 'request_preparation';
            $state['reason_code'] = $exception instanceof MtUniCreditCpTimeoutException
                ? 'timeout' : $exception->getDiagnosticReason();
        } elseif ($state['http_status'] !== null && $state['http_status'] >= 300) {
            $state['phase'] = 'http_response';
            $state['reason_code'] = 'cp_http_rejected';
        } elseif ($exception instanceof MtUniCreditCpAuthenticationException) {
            $state['reason_code'] = 'credentials_unavailable';
        } elseif ($state['phase'] === 'response_validation') {
            $state['reason_code'] = 'response_invalid';
        } elseif ($exception instanceof MtUniCreditCpInvalidPayloadException || $exception instanceof MtUniCreditShopSnapshotValidationException) {
            $state['reason_code'] = $state['transport_attempted'] ? 'response_invalid' : 'payload_invalid';
        } else {
            $state['reason_code'] = 'local_failure';
        }

        return $state;
    }
}
