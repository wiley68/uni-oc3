<?php

/**
 * Shared wiring for catalog inbound CP JSON API controllers.
 */
final class MtUniCreditInboundApiRunner
{
    /**
     * @param object $controller OpenCart Controller with config, db, request, response, log
     * @param callable $handler
     * @param string|null $expectedOperation Code-bound operation for this endpoint
     * @return void
     */
    public static function run($controller, $handler, $expectedOperation = null)
    {
        $controller->response->addHeader('Content-Type: application/json; charset=utf-8');
        $controller->response->addHeader('Cache-Control: no-store');
        $controller->response->addHeader('X-Content-Type-Options: nosniff');

        try {
            $server = is_array($controller->request->server) ? $controller->request->server : array();
            $bodyRead = MtUniCreditBoundedRawBodyReader::readPhpInput($server);
            if (!empty($bodyRead['oversized'])) {
                throw new MtUniCreditInboundApiException(
                    'Тялото на заявката надвишава допустимия размер.',
                    413,
                    'payload_too_large'
                );
            }

            $storeId = (int) $controller->config->get('config_store_id');
            $db = MtUniCreditBootstrap::dbFromRegistry($controller->db);
            $credentials = MtUniCreditBootstrap::credentialsRepositoryFromDb($db);
            $authenticator = new MtUniCreditRequestAuthenticator(
                $credentials,
                new MtUniCreditApiNonceRepository($db),
                $storeId,
                (bool) $controller->config->get(MtUniCreditConstants::MODULE_SETTING_STATUS)
            );

            $method = isset($server['REQUEST_METHOD']) ? (string) $server['REQUEST_METHOD'] : 'GET';
            $payload = MtUniCreditInboundApiDispatcher::dispatch(
                $handler,
                $authenticator,
                $server,
                (string) $bodyRead['body'],
                $method,
                $expectedOperation
            );
            $encoded = MtUniCreditInboundApiDispatcher::encodeResponse($payload, 200);
        } catch (MtUniCreditInboundApiException $exception) {
            $encoded = MtUniCreditInboundApiDispatcher::encodeException($exception);
        } catch (MtUniCreditShopSnapshotValidationException $exception) {
            $encoded = MtUniCreditInboundApiDispatcher::encodeResponse(
                MtUniCreditInboundApiEnvelope::failure(
                    $exception->errorCode(),
                    $exception->getMessage(),
                    array('violations' => $exception->violations())
                ),
                422
            );
        } catch (Exception $exception) {
            if ((bool) $controller->config->get(MtUniCreditConstants::MODULE_SETTING_DEBUG)) {
                $controller->log->write('[mt_uni_credit] inbound API failure: ' . $exception->getMessage());
            }
            $encoded = MtUniCreditInboundApiDispatcher::encodeResponse(
                MtUniCreditInboundApiEnvelope::failure(
                    'internal_error',
                    'Модулът не можа да обработи заявката.'
                ),
                500
            );
        }

        $proto = isset($controller->request->server['SERVER_PROTOCOL'])
            ? (string) $controller->request->server['SERVER_PROTOCOL']
            : 'HTTP/1.1';
        $controller->response->addHeader(
            $proto . ' ' . MtUniCreditInboundApiDispatcher::httpStatusLine((int) $encoded['status'])
        );
        $controller->response->setOutput($encoded['body']);
    }
}
