<?php

/**
 * Operational Satrudnik mail for canonical terminal bank failures only.
 *
 * Sends only for bank_send_failed_cp / bank_send_failed_smartucf when the caller
 * supplies a request-local first-transition flag (previous != target before persist).
 * Uses OpenCart Mail (same contract as native order history mail) — never the PHP mail function.
 */
final class MtUniCreditSatrudnikFailureNotifier
{
    /** @var callable|null factory(string $engine): object Mail-like */
    private static $mailFactory;

    /** @var callable|null resolver(object $controller): array|null */
    private static $shopResolver;

    /**
     * Test seam: inject Mail factory. Pass null to restore default `new Mail($engine)`.
     *
     * @param callable|null $factory
     * @return void
     */
    public static function setMailFactory($factory)
    {
        self::$mailFactory = is_callable($factory) ? $factory : null;
    }

    /**
     * Test seam: inject shop snapshot resolver. Pass null to restore loadFreshShop.
     *
     * @param callable|null $resolver
     * @return void
     */
    public static function setShopResolver($resolver)
    {
        self::$shopResolver = is_callable($resolver) ? $resolver : null;
    }

    /**
     * @param string $statusId
     * @return bool
     */
    public static function isTargetStatus($statusId)
    {
        $statusId = strtolower(trim((string) $statusId));

        return $statusId === MtUniCreditBankStatus::SEND_FAILED_CP
            || $statusId === MtUniCreditBankStatus::SEND_FAILED_SMARTUCF;
    }

    /**
     * After successful native addOrderHistory / finalization history_called.
     *
     * @param object $controller OpenCart controller (config, db, load)
     * @param array<string, mixed> $result Submission/finalize context
     * @return bool True when a send was attempted successfully
     */
    public static function maybeNotifyAfterNativeHistory($controller, array $result)
    {
        $orderId = '';
        $probeOrder = isset($result['order_id']) ? (string) $result['order_id'] : '';

        try {
            if (empty($result['bank_status_transitioned'])) {
                return false;
            }

            $statusId = isset($result['bank_status']) ? (string) $result['bank_status'] : '';
            if (!self::isTargetStatus($statusId)) {
                return false;
            }

            $orderId = MtUniCreditShopOrderId::tryNormalize(
                isset($result['order_id']) ? $result['order_id'] : null
            );
            if ($orderId === null) {
                return false;
            }

            // OC3 Controllers expose config via Registry __get; never gate on isset(controller config).
            $config = self::resolveRuntimeConfig($controller);
            if ($config === null) {
                return false;
            }

            $shop = array();
            if (self::$shopResolver !== null) {
                $loaded = call_user_func(self::$shopResolver, $controller);
            } elseif (class_exists('MtUniCreditStorefrontRuntime', false)) {
                $loaded = MtUniCreditStorefrontRuntime::loadFreshShop($controller);
            } else {
                $loaded = null;
            }
            if (is_array($loaded)) {
                $shop = $loaded;
            }

            $email = isset($shop['satrudnik_email']) ? trim((string) $shop['satrudnik_email']) : '';
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return false;
            }

            $dateAdded = self::resolveOrderDateAdded($controller, $orderId);
            $cpOrderId = self::resolveControlPanelOrderId($result);
            $statusLabel = MtUniCreditBankStatus::resolveLabel(
                $statusId,
                isset($result['status_label']) ? (string) $result['status_label'] : ''
            );

            return self::sendWithOpenCartMail(
                $config,
                $email,
                $orderId,
                $dateAdded,
                $cpOrderId,
                $statusId,
                $statusLabel
            );
        } catch (Exception $exception) {
            self::logSendFailure($orderId !== '' ? $orderId : $probeOrder, $exception);

            return false;
        } catch (Throwable $exception) {
            self::logSendFailure($orderId !== '' ? $orderId : $probeOrder, $exception);

            return false;
        }
    }

    /**
     * Pure helpers for tests (no transport).
     *
     * @param array<string, mixed>|null $shop
     * @return string
     */
    public static function resolveRecipientEmail($shop)
    {
        if (!is_array($shop)) {
            return '';
        }
        $email = isset($shop['satrudnik_email']) ? trim((string) $shop['satrudnik_email']) : '';
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return '';
        }

        return $email;
    }

    /**
     * @param string $orderId
     * @param string $dateAdded
     * @param int $cpOrderId
     * @param string $statusId
     * @param string $statusLabel
     * @return array{subject: string, body: string}
     */
    public static function composeMessage($orderId, $dateAdded, $cpOrderId, $statusId, $statusLabel)
    {
        $orderId = (string) $orderId;
        $statusId = (string) $statusId;
        $statusLabel = trim((string) $statusLabel);
        if ($statusLabel === '') {
            $statusLabel = (string) MtUniCreditBankStatus::resolveLabel($statusId, '');
        }

        $subject = 'Проблем при изпращане на заявка за финансиране - поръчка ' . $orderId;

        $lines = array(
            'Проблем при изпращане на заявка за финансиране',
            '',
            'Магазин поръчка: ' . $orderId,
        );
        $dateAdded = trim((string) $dateAdded);
        if ($dateAdded !== '') {
            $lines[] = 'Дата на поръчката: ' . $dateAdded;
        }
        $cpOrderId = (int) $cpOrderId;
        if ($cpOrderId > 0) {
            $lines[] = 'КП поръчка: ' . $cpOrderId;
        }
        $lines[] = 'Статус: ' . $statusLabel . ' (' . $statusId . ')';

        return array(
            'subject' => $subject,
            'body' => implode("\n", $lines),
        );
    }

    /**
     * @param string $previousStatusId Immutable snapshot from BEFORE persist
     * @param string $persistedStatusId
     * @param string $targetStatusId
     * @return bool
     */
    public static function isFirstTransition($previousStatusId, $persistedStatusId, $targetStatusId)
    {
        $previousStatusId = strtolower(trim((string) $previousStatusId));
        $persistedStatusId = strtolower(trim((string) $persistedStatusId));
        $targetStatusId = strtolower(trim((string) $targetStatusId));
        if ($targetStatusId === '' || !self::isTargetStatus($targetStatusId)) {
            return false;
        }
        if ($persistedStatusId !== $targetStatusId) {
            return false;
        }

        return $previousStatusId !== $targetStatusId;
    }

    /**
     * Resolve the same runtime Config instance native order mail uses.
     *
     * Must NOT gate on isset(controller config): OC3 Controller has __get via Registry
     * but no __isset, so isset() is false even when $controller->config works.
     *
     * @param mixed $controller
     * @return object|null Config-like object with get()
     */
    private static function resolveRuntimeConfig($controller)
    {
        if (!is_object($controller)) {
            return null;
        }

        $config = null;
        try {
            $config = $controller->config;
        } catch (Exception $ignored) {
            $config = null;
        } catch (Throwable $ignored) {
            $config = null;
        }

        if (is_object($config) && method_exists($config, 'get')) {
            return $config;
        }

        return null;
    }

    /**
     * @param object $config OpenCart config
     * @param string $to
     * @param string $orderId
     * @param string $dateAdded
     * @param int $cpOrderId
     * @param string $statusId
     * @param string $statusLabel
     * @return bool
     */
    private static function sendWithOpenCartMail(
        $config,
        $to,
        $orderId,
        $dateAdded,
        $cpOrderId,
        $statusId,
        $statusLabel
    ) {
        $message = self::composeMessage($orderId, $dateAdded, $cpOrderId, $statusId, $statusLabel);
        $engine = '';
        if (is_object($config) && method_exists($config, 'get')) {
            $engine = (string) $config->get('config_mail_engine');
        }
        if ($engine === '') {
            $engine = 'mail';
        }

        $mail = self::createMail($engine);
        if (!is_object($mail)) {
            throw new RuntimeException('OpenCart Mail instance unavailable.');
        }

        if (is_object($config) && method_exists($config, 'get')) {
            $mail->parameter = $config->get('config_mail_parameter');
            $mail->smtp_hostname = $config->get('config_mail_smtp_hostname');
            $mail->smtp_username = $config->get('config_mail_smtp_username');
            $mail->smtp_password = html_entity_decode(
                (string) $config->get('config_mail_smtp_password'),
                ENT_QUOTES,
                'UTF-8'
            );
            $mail->smtp_port = $config->get('config_mail_smtp_port');
            $mail->smtp_timeout = $config->get('config_mail_smtp_timeout');

            $from = trim((string) $config->get('config_email'));
            $sender = html_entity_decode((string) $config->get('config_name'), ENT_QUOTES, 'UTF-8');
        } else {
            $from = '';
            $sender = '';
        }

        if ($from === '') {
            throw new RuntimeException('config_email is required for Satrudnik mail.');
        }
        if ($sender === '') {
            $sender = $from;
        }

        $mail->setTo($to);
        $mail->setFrom($from);
        $mail->setSender($sender);
        $mail->setSubject($message['subject']);
        $mail->setText($message['body']);

        $sent = $mail->send();
        if ($sent === false) {
            throw new RuntimeException('OpenCart Mail::send returned false.');
        }

        return true;
    }

    /**
     * @param string $engine
     * @return object
     */
    private static function createMail($engine)
    {
        if (self::$mailFactory !== null) {
            return call_user_func(self::$mailFactory, (string) $engine);
        }

        if (!class_exists('Mail', false)) {
            throw new RuntimeException('OpenCart Mail class is not loaded.');
        }

        return new Mail((string) $engine);
    }

    /**
     * @param object $controller
     * @param string $orderId
     * @return string
     */
    private static function resolveOrderDateAdded($controller, $orderId)
    {
        try {
            $nativeId = MtUniCreditShopOrderId::tryNativeOc3OrderId($orderId);
            if ($nativeId === null || $nativeId <= 0) {
                return '';
            }
            // Same OC3 isset trap as config — read via __get, never isset().
            $loader = null;
            try {
                $loader = $controller->load;
            } catch (Exception $ignored) {
                $loader = null;
            } catch (Throwable $ignored) {
                $loader = null;
            }
            if (!is_object($loader) || !method_exists($loader, 'model')) {
                return '';
            }
            $loader->model('checkout/order');
            $orderModel = null;
            try {
                $orderModel = $controller->model_checkout_order;
            } catch (Exception $ignored) {
                $orderModel = null;
            } catch (Throwable $ignored) {
                $orderModel = null;
            }
            if (!is_object($orderModel) || !method_exists($orderModel, 'getOrder')) {
                return '';
            }
            $order = $orderModel->getOrder((int) $nativeId);
            if (!is_array($order)) {
                return '';
            }
            $date = isset($order['date_added']) ? trim((string) $order['date_added']) : '';

            return $date;
        } catch (Exception $ignored) {
            return '';
        } catch (Throwable $ignored) {
            return '';
        }
    }

    /**
     * @param array<string, mixed> $result
     * @return int
     */
    private static function resolveControlPanelOrderId(array $result)
    {
        $cpId = isset($result['control_panel_order_id']) ? (int) $result['control_panel_order_id'] : 0;
        if ($cpId > 0) {
            return $cpId;
        }
        if (isset($result['attempt']) && is_array($result['attempt'])) {
            $fromAttempt = isset($result['attempt']['control_panel_order_id'])
                ? (int) $result['attempt']['control_panel_order_id']
                : 0;
            if ($fromAttempt > 0) {
                return $fromAttempt;
            }
        }

        return 0;
    }

    /**
     * @param string $orderId
     * @param Exception|Throwable $exception
     * @return void
     */
    private static function logSendFailure($orderId, $exception)
    {
        $orderId = (string) $orderId;
        $detail = '';
        if (is_object($exception) && method_exists($exception, 'getMessage')) {
            $detail = trim(preg_replace('/\s+/', ' ', (string) $exception->getMessage()));
        }
        if (strlen($detail) > 200) {
            $detail = substr($detail, 0, 200);
        }
        error_log(
            '[mt_uni_credit] satrudnik failure mail send failed for order '
                . ($orderId !== '' ? $orderId : '?')
                . ': '
                . $detail
        );
    }
}
