<?php

/**
 * Shared wiring for catalog inbound CP JSON API controllers.
 */
final class MtUniCreditInboundApiRunner
{
    /**
     * @param object $controller OpenCart Controller with config, db, request, response, log
     * @param callable $handler
     * @return void
     */
    public static function run($controller, $handler)
    {
        $controller->response->addHeader('Content-Type: application/json; charset=utf-8');
        $controller->response->addHeader('Cache-Control: no-store');
        $controller->response->addHeader('X-Content-Type-Options: nosniff');

        try {
            $storeId = (int) $controller->config->get('config_store_id');
            $db = MtUniCreditBootstrap::dbFromRegistry($controller->db);
            $credentials = MtUniCreditBootstrap::credentialsRepositoryFromDb($db);
            $authenticator = new MtUniCreditRequestAuthenticator(
                $credentials,
                new MtUniCreditApiNonceRepository($db),
                $storeId,
                (bool) $controller->config->get(MtUniCreditConstants::MODULE_SETTING_STATUS)
            );

            $rawBody = file_get_contents('php://input');
            if (!is_string($rawBody)) {
                $rawBody = '';
            }

            $server = is_array($controller->request->server) ? $controller->request->server : array();
            $method = isset($server['REQUEST_METHOD']) ? (string) $server['REQUEST_METHOD'] : 'GET';
            $payload = MtUniCreditInboundApiDispatcher::dispatch(
                $handler,
                $authenticator,
                $server,
                $rawBody,
                $method
            );
            $encoded = MtUniCreditInboundApiDispatcher::encodeResponse($payload, 200);
        } catch (MtUniCreditInboundApiException $exception) {
            $encoded = MtUniCreditInboundApiDispatcher::encodeException($exception);
        } catch (MtUniCreditShopSnapshotValidationException $exception) {
            $encoded = MtUniCreditInboundApiDispatcher::encodeResponse(array(
                'success' => false,
                'message' => $exception->getMessage(),
                'error' => $exception->errorCode(),
                'data' => array('violations' => $exception->violations()),
            ), 422);
        } catch (Exception $exception) {
            if ((bool) $controller->config->get(MtUniCreditConstants::MODULE_SETTING_DEBUG)) {
                $controller->log->write('[mt_uni_credit] inbound API failure: ' . $exception->getMessage());
            }
            $encoded = MtUniCreditInboundApiDispatcher::encodeResponse(array(
                'success' => false,
                'message' => 'Модулът не можа да обработи заявката.',
            ), 500);
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
