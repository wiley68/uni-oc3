<?php

/**
 * Satrudnik terminal-failure operational mail.
 *
 * Run: php tests/phase_satrudnik_failure_mail_check.php
 *
 * PHP 7.3 compatible. Offline. No network.
 */
require_once __DIR__ . '/bootstrap.php';

$root = MTUC_PHASE0_ROOT;
$lib = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR
    . 'library' . DIRECTORY_SEPARATOR . 'mt_uni_credit';

if (!defined('DIR_SYSTEM')) {
    define('DIR_SYSTEM', $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR);
}
if (!defined('DIR_STORAGE')) {
    mtuc_test_define_dir_storage('mtuc-satrudnik-mail');
}
if (!defined('DB_PASSWORD')) {
    define('DB_PASSWORD', 'phase4-test-installation-db-password-secret');
}
if (!defined('DB_PREFIX')) {
    define('DB_PREFIX', 'oc_');
}

require_once $lib . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once __DIR__ . '/support/phase2_memory_db.php';
require_once __DIR__ . '/fixtures/cp_shop_snapshot.php';

$failures = array();
$passes = 0;

/**
 * @param bool $condition
 * @param string $message
 * @return void
 */
function mtucSatrudnik_assert($condition, $message)
{
    global $failures, $passes;
    if ($condition) {
        $passes++;
        echo 'PASS  ' . $message . PHP_EOL;

        return;
    }
    $failures[] = $message;
    echo 'FAIL  ' . $message . PHP_EOL;
}

/**
 * Recording OpenCart Mail stand-in (never PHP mail()).
 */
final class MtucSatrudnikFakeMail
{
    /** @var mixed */
    public $parameter;
    /** @var mixed */
    public $smtp_hostname;
    /** @var mixed */
    public $smtp_username;
    /** @var mixed */
    public $smtp_password;
    /** @var mixed */
    public $smtp_port;
    /** @var mixed */
    public $smtp_timeout;

    /** @var mixed */
    public $to;
    /** @var mixed */
    public $from;
    /** @var mixed */
    public $sender;
    /** @var mixed */
    public $subject;
    /** @var mixed */
    public $text;
    /** @var bool */
    public $throwOnSend = false;
    /** @var bool */
    public $returnFalse = false;
    /** @var int */
    public $sendCalls = 0;

    /** @var string */
    public $engine;

    /**
     * @param string $engine
     */
    public function __construct($engine = 'smtp')
    {
        $this->engine = (string) $engine;
    }

    /**
     * @param mixed $to
     * @return void
     */
    public function setTo($to)
    {
        $this->to = $to;
    }

    /**
     * @param mixed $from
     * @return void
     */
    public function setFrom($from)
    {
        $this->from = $from;
    }

    /**
     * @param mixed $sender
     * @return void
     */
    public function setSender($sender)
    {
        $this->sender = $sender;
    }

    /**
     * @param mixed $subject
     * @return void
     */
    public function setSubject($subject)
    {
        $this->subject = $subject;
    }

    /**
     * @param mixed $text
     * @return void
     */
    public function setText($text)
    {
        $this->text = $text;
    }

    /**
     * @return bool
     */
    public function send()
    {
        $this->sendCalls++;
        if ($this->throwOnSend) {
            throw new RuntimeException('smtp transport failed');
        }
        if ($this->returnFalse) {
            return false;
        }

        return true;
    }
}

/**
 * @return object
 */
function mtucSatrudnik_config()
{
    return new class {
        /** @var array<string, mixed> */
        private $data = array(
            'config_mail_engine' => 'smtp',
            'config_mail_parameter' => '',
            'config_mail_smtp_hostname' => 'smtp.example.test',
            'config_mail_smtp_username' => 'smtp-user',
            'config_mail_smtp_password' => 'smtp&#45;pass',
            'config_mail_smtp_port' => '587',
            'config_mail_smtp_timeout' => '5',
            'config_email' => 'store@example.test',
            'config_name' => 'Test Store',
            'config_store_id' => 0,
        );

        /**
         * @param string $key
         * @return mixed
         */
        public function get($key)
        {
            return array_key_exists($key, $this->data) ? $this->data[$key] : null;
        }
    };
}

/**
 * @param array<string, mixed> $order
 * @return object
 */
function mtucSatrudnik_controller(array $order = array())
{
    $config = mtucSatrudnik_config();
    $orderModel = new class($order) {
        /** @var array<string, mixed> */
        private $order;

        /**
         * @param array<string, mixed> $order
         */
        public function __construct(array $order)
        {
            $this->order = $order;
        }

        /**
         * @param int|string $orderId
         * @return array<string, mixed>|false
         */
        public function getOrder($orderId)
        {
            if ((int) $orderId <= 0) {
                return false;
            }
            if ($this->order === array()) {
                return array(
                    'order_id' => (int) $orderId,
                    'date_added' => '2026-09-18 11:22:33',
                );
            }

            return $this->order;
        }
    };

    return new class($config, $orderModel) {
        /** @var object */
        public $config;
        /** @var mixed */
        public $db;
        /** @var object */
        public $load;
        /** @var object */
        public $model_checkout_order;

        /**
         * @param object $config
         * @param object $orderModel
         */
        public function __construct($config, $orderModel)
        {
            $this->config = $config;
            $this->model_checkout_order = $orderModel;
            $owner = $this;
            $this->load = new class($owner) {
                /** @var object */
                private $owner;

                /**
                 * @param object $owner
                 */
                public function __construct($owner)
                {
                    $this->owner = $owner;
                }

                /**
                 * @param string $route
                 * @return void
                 */
                public function model($route)
                {
                    unset($route);
                }
            };
        }
    };
}

/**
 * OC3-like controller: Registry __get works, isset($controller->config) is false.
 *
 * @param array<string, mixed> $order
 * @return object
 */
function mtucSatrudnik_registryController(array $order = array())
{
    $config = mtucSatrudnik_config();
    $orderModel = new class($order) {
        /** @var array<string, mixed> */
        private $order;

        /**
         * @param array<string, mixed> $order
         */
        public function __construct(array $order)
        {
            $this->order = $order;
        }

        /**
         * @param int|string $orderId
         * @return array<string, mixed>|false
         */
        public function getOrder($orderId)
        {
            if ((int) $orderId <= 0) {
                return false;
            }
            if ($this->order === array()) {
                return array(
                    'order_id' => (int) $orderId,
                    'date_added' => '2026-09-18 11:22:33',
                );
            }

            return $this->order;
        }
    };

    $data = array(
        'config' => $config,
        'model_checkout_order' => $orderModel,
    );

    $controller = new class($data) {
        /** @var array<string, mixed> */
        private $data;

        /**
         * @param array<string, mixed> $data
         */
        public function __construct(array $data)
        {
            $this->data = $data;
            $owner = $this;
            $this->data['load'] = new class($owner) {
                /** @var object */
                private $owner;

                /**
                 * @param object $owner
                 */
                public function __construct($owner)
                {
                    $this->owner = $owner;
                }

                /**
                 * @param string $route
                 * @return void
                 */
                public function model($route)
                {
                    unset($route);
                }
            };
        }

        /**
         * @param string $key
         * @return mixed
         */
        public function __get($key)
        {
            $key = (string) $key;
            if (!array_key_exists($key, $this->data)) {
                throw new RuntimeException('Undefined registry key: ' . $key);
            }

            return $this->data[$key];
        }
    };

    return $controller;
}

// ---------------------------------------------------------------------------
// Bootstrap / class load
// ---------------------------------------------------------------------------
mtucSatrudnik_assert(
    class_exists('MtUniCreditSatrudnikFailureNotifier', false),
    'bootstrap loads MtUniCreditSatrudnikFailureNotifier'
);
$bootstrapSrc = (string) file_get_contents($lib . DIRECTORY_SEPARATOR . 'bootstrap.php');
mtucSatrudnik_assert(
    strpos($bootstrapSrc, 'satrudnik_failure_notifier.php') !== false,
    'bootstrap.php require_once satrudnik_failure_notifier.php'
);
$notifierSrc = (string) file_get_contents($lib . DIRECTORY_SEPARATOR . 'satrudnik_failure_notifier.php');
mtucSatrudnik_assert(
    strpos($notifierSrc, '@mail(') === false
        && !preg_match('/(?<![A-Za-z0-9_])mail\\s*\\(/', str_replace('new Mail(', 'new X(', $notifierSrc)),
    'notifier source has no PHP mail() call'
);
mtucSatrudnik_assert(
    strpos($notifierSrc, 'PhpMailProcessTwoMailer') === false,
    'notifier does not reference PhpMailProcessTwoMailer'
);
mtucSatrudnik_assert(strpos($notifierSrc, 'new Mail(') !== false, 'notifier uses OpenCart Mail class');
mtucSatrudnik_assert(
    strpos($notifierSrc, 'isset($controller->config)') === false
        && strpos($notifierSrc, 'isset($controller -> config)') === false,
    'notifier must not use isset($controller->config) (OC3 Registry trap)'
);
mtucSatrudnik_assert(
    strpos($notifierSrc, 'oc_setting') === false && strpos($notifierSrc, 'setting/setting') === false,
    'notifier does not query oc_setting for mail config'
);

// ---------------------------------------------------------------------------
// Cache: satrudnik_email survives sanitizer; null/empty/missing do not invalidate
// ---------------------------------------------------------------------------
$snap = mtuc4_valid_shop_snapshot(array('satrudnik_email' => 'satrudnik@example.test'));
$sanitized = MtUniCreditShopSnapshotSanitizer::sanitize($snap);
mtucSatrudnik_assert(
    isset($sanitized['satrudnik_email']) && $sanitized['satrudnik_email'] === 'satrudnik@example.test',
    'cache sanitizer preserves satrudnik_email string'
);
foreach (array(null, '', 'missing') as $idx => $value) {
    $variant = mtuc4_valid_shop_snapshot();
    if ($idx === 0) {
        $variant['satrudnik_email'] = null;
    } elseif ($idx === 1) {
        $variant['satrudnik_email'] = '';
    } else {
        unset($variant['satrudnik_email']);
    }
    try {
        (new MtUniCreditShopConfigurationSnapshotValidator())->validate(
            $variant,
            (string) $variant['unicid']
        );
        mtucSatrudnik_assert(true, 'snapshot still valid with satrudnik_email variant #' . $idx);
    } catch (MtUniCreditShopSnapshotValidationException $exception) {
        mtucSatrudnik_assert(false, 'snapshot still valid with satrudnik_email variant #' . $idx);
    }
}

// ---------------------------------------------------------------------------
// Recipient resolution — no fallback
// ---------------------------------------------------------------------------
mtucSatrudnik_assert(
    MtUniCreditSatrudnikFailureNotifier::resolveRecipientEmail(array(
        'satrudnik_email' => 'ok@example.test',
        'uni_email' => 'admin@example.test',
    )) === 'ok@example.test',
    'valid satrudnik_email resolves'
);
mtucSatrudnik_assert(
    MtUniCreditSatrudnikFailureNotifier::resolveRecipientEmail(array()) === '',
    'missing satrudnik_email → skip'
);
mtucSatrudnik_assert(
    MtUniCreditSatrudnikFailureNotifier::resolveRecipientEmail(array('satrudnik_email' => null)) === '',
    'null satrudnik_email → skip'
);
mtucSatrudnik_assert(
    MtUniCreditSatrudnikFailureNotifier::resolveRecipientEmail(array('satrudnik_email' => '')) === '',
    'empty satrudnik_email → skip'
);
mtucSatrudnik_assert(
    MtUniCreditSatrudnikFailureNotifier::resolveRecipientEmail(array('satrudnik_email' => 'bad')) === '',
    'invalid satrudnik_email → skip'
);
mtucSatrudnik_assert(
    MtUniCreditSatrudnikFailureNotifier::resolveRecipientEmail(array(
        'uni_email' => 'admin@example.test',
    )) === '',
    'no fallback to uni_email'
);

// ---------------------------------------------------------------------------
// Target statuses
// ---------------------------------------------------------------------------
mtucSatrudnik_assert(
    MtUniCreditSatrudnikFailureNotifier::isTargetStatus(MtUniCreditBankStatus::SEND_FAILED_CP),
    'target: bank_send_failed_cp'
);
mtucSatrudnik_assert(
    MtUniCreditSatrudnikFailureNotifier::isTargetStatus(MtUniCreditBankStatus::SEND_FAILED_SMARTUCF),
    'target: bank_send_failed_smartucf'
);
foreach (
    array(
        MtUniCreditBankStatus::SENT_PROCESS1,
        MtUniCreditBankStatus::SENT_PROCESS2,
        MtUniCreditBankStatus::SEND_FAILED,
        'outcome_unknown',
        '42',
    ) as $nonTarget
) {
    mtucSatrudnik_assert(
        !MtUniCreditSatrudnikFailureNotifier::isTargetStatus($nonTarget),
        'non-target skipped: ' . $nonTarget
    );
}

// ---------------------------------------------------------------------------
// Body / privacy
// ---------------------------------------------------------------------------
$msg = MtUniCreditSatrudnikFailureNotifier::composeMessage(
    '1001',
    '2026-09-18 10:00:00',
    55,
    MtUniCreditBankStatus::SEND_FAILED_SMARTUCF,
    MtUniCreditBankStatus::LABEL_SEND_FAILED_SMARTUCF
);
mtucSatrudnik_assert(strpos($msg['subject'], '1001') !== false, 'subject includes order id');
mtucSatrudnik_assert(strpos($msg['body'], 'Магазин поръчка: 1001') !== false, 'body has order id');
mtucSatrudnik_assert(strpos($msg['body'], 'Дата на поръчката: 2026-09-18 10:00:00') !== false, 'body has date');
mtucSatrudnik_assert(strpos($msg['body'], 'КП поръчка: 55') !== false, 'body has CP id when > 0');
mtucSatrudnik_assert(
    strpos($msg['body'], MtUniCreditBankStatus::LABEL_SEND_FAILED_SMARTUCF) !== false,
    'body has status label'
);
mtucSatrudnik_assert(
    strpos($msg['body'], MtUniCreditBankStatus::SEND_FAILED_SMARTUCF) !== false,
    'body has status_id'
);
$msgNoCp = MtUniCreditSatrudnikFailureNotifier::composeMessage(
    '1001',
    '',
    0,
    MtUniCreditBankStatus::SEND_FAILED_CP,
    MtUniCreditBankStatus::LABEL_SEND_FAILED_CP
);
mtucSatrudnik_assert(strpos($msgNoCp['body'], 'КП поръчка:') === false, 'CP line omitted when id=0');
mtucSatrudnik_assert(strpos($msgNoCp['body'], 'Дата на поръчката:') === false, 'date line omitted when empty');
foreach (array('EGN', 'phone', 'address', 'HTTP', 'correlation', 'SmartUCF session') as $forbidden) {
    mtucSatrudnik_assert(
        stripos($msg['body'], $forbidden) === false,
        'body excludes sensitive/diagnostic token: ' . $forbidden
    );
}

// ---------------------------------------------------------------------------
// Transition helper
// ---------------------------------------------------------------------------
mtucSatrudnik_assert(
    MtUniCreditSatrudnikFailureNotifier::isFirstTransition(
        '',
        MtUniCreditBankStatus::SEND_FAILED_CP,
        MtUniCreditBankStatus::SEND_FAILED_CP
    ),
    'first CP transition eligible'
);
mtucSatrudnik_assert(
    !MtUniCreditSatrudnikFailureNotifier::isFirstTransition(
        MtUniCreditBankStatus::SEND_FAILED_CP,
        MtUniCreditBankStatus::SEND_FAILED_CP,
        MtUniCreditBankStatus::SEND_FAILED_CP
    ),
    'CP replay not eligible'
);
mtucSatrudnik_assert(
    MtUniCreditSatrudnikFailureNotifier::isFirstTransition(
        MtUniCreditBankStatus::CP_SENT,
        MtUniCreditBankStatus::SEND_FAILED_SMARTUCF,
        MtUniCreditBankStatus::SEND_FAILED_SMARTUCF
    ),
    'first SmartUCF transition eligible'
);
mtucSatrudnik_assert(
    !MtUniCreditSatrudnikFailureNotifier::isFirstTransition(
        MtUniCreditBankStatus::SEND_FAILED_SMARTUCF,
        MtUniCreditBankStatus::SEND_FAILED_SMARTUCF,
        MtUniCreditBankStatus::SEND_FAILED_SMARTUCF
    ),
    'SmartUCF replay not eligible'
);

// ---------------------------------------------------------------------------
// Persist CP failure transition capture (previous BEFORE write)
// ---------------------------------------------------------------------------
$memoryDb = new Phase2MemoryDb();
$schemaAdapter = new MtUniCreditDbAdapter($memoryDb, 'oc_');
MtUniCreditPersistenceSchema::installAll($schemaAdapter);
$memoryDb->seedOrder(2001, 0, MtUniCreditConstants::EXTENSION_CODE);
$db = $schemaAdapter;
$repo = new MtUniCreditOrderBankStatusRepository($db);

$first = MtUniCreditBankStatus::persistLocalControlPanelFailure($repo, 0, 2001);
mtucSatrudnik_assert(!empty($first['ok']), 'CP persist first: ok');
mtucSatrudnik_assert(!empty($first['bank_status_transitioned']), 'CP persist first: transitioned');

$replay = MtUniCreditBankStatus::persistLocalControlPanelFailure($repo, 0, 2001);
mtucSatrudnik_assert(!empty($replay['ok']), 'CP persist replay: still ok (durable)');
mtucSatrudnik_assert(empty($replay['bank_status_transitioned']), 'CP persist replay: not transitioned');

// ---------------------------------------------------------------------------
// Mail transport + notify
// ---------------------------------------------------------------------------
/** @var MtucSatrudnikFakeMail|null $lastMail */
$lastMail = null;
MtUniCreditSatrudnikFailureNotifier::setMailFactory(function ($engine) use (&$lastMail) {
    $lastMail = new MtucSatrudnikFakeMail($engine);

    return $lastMail;
});
MtUniCreditSatrudnikFailureNotifier::setShopResolver(function ($controller) {
    unset($controller);

    return array('satrudnik_email' => 'satrudnik@example.test');
});

$controller = mtucSatrudnik_controller();

$lastMail = null;
$sent = MtUniCreditSatrudnikFailureNotifier::maybeNotifyAfterNativeHistory($controller, array(
    'order_id' => 3001,
    'bank_status' => MtUniCreditBankStatus::SEND_FAILED_CP,
    'bank_status_transitioned' => true,
    'control_panel_order_id' => 0,
));
mtucSatrudnik_assert($sent === true, 'first CP notify sends via OpenCart Mail factory');
mtucSatrudnik_assert($lastMail instanceof MtucSatrudnikFakeMail, 'Mail instance created');
if ($lastMail instanceof MtucSatrudnikFakeMail) {
    mtucSatrudnik_assert($lastMail->engine === 'smtp', 'Mail engine from config_mail_engine');
    mtucSatrudnik_assert($lastMail->smtp_hostname === 'smtp.example.test', 'SMTP hostname configured');
    mtucSatrudnik_assert($lastMail->smtp_username === 'smtp-user', 'SMTP username configured');
    mtucSatrudnik_assert($lastMail->smtp_password === 'smtp-pass', 'SMTP password html-decoded');
    mtucSatrudnik_assert((string) $lastMail->smtp_port === '587', 'SMTP port configured');
    mtucSatrudnik_assert($lastMail->to === 'satrudnik@example.test', 'Mail to = satrudnik_email');
    mtucSatrudnik_assert($lastMail->from === 'store@example.test', 'Mail from = config_email');
    mtucSatrudnik_assert($lastMail->sender === 'Test Store', 'Mail sender = config_name');
    mtucSatrudnik_assert(strpos((string) $lastMail->text, 'Магазин поръчка: 3001') !== false, 'sent body has order');
    mtucSatrudnik_assert(strpos((string) $lastMail->text, 'Дата на поръчката:') !== false, 'sent body has date');
    mtucSatrudnik_assert(strpos((string) $lastMail->text, 'КП поръчка:') === false, 'CP omit when 0');
    mtucSatrudnik_assert($lastMail->sendCalls === 1, 'Mail::send called once');
}

$lastMail = null;
$skippedReplay = MtUniCreditSatrudnikFailureNotifier::maybeNotifyAfterNativeHistory($controller, array(
    'order_id' => 3001,
    'bank_status' => MtUniCreditBankStatus::SEND_FAILED_CP,
    'bank_status_transitioned' => false,
    'control_panel_order_id' => 0,
));
mtucSatrudnik_assert($skippedReplay === false, 'CP replay transition flag skips send');
mtucSatrudnik_assert($lastMail === null, 'CP replay creates no Mail');

$lastMail = null;
$sentSu = MtUniCreditSatrudnikFailureNotifier::maybeNotifyAfterNativeHistory($controller, array(
    'order_id' => 3002,
    'bank_status' => MtUniCreditBankStatus::SEND_FAILED_SMARTUCF,
    'bank_status_transitioned' => true,
    'control_panel_order_id' => 77,
));
mtucSatrudnik_assert($sentSu === true, 'first SmartUCF notify sends');
if ($lastMail instanceof MtucSatrudnikFakeMail) {
    mtucSatrudnik_assert(strpos((string) $lastMail->text, 'КП поръчка: 77') !== false, 'SmartUCF body includes CP id');
    mtucSatrudnik_assert(
        strpos((string) $lastMail->text, MtUniCreditBankStatus::LABEL_SEND_FAILED_SMARTUCF) !== false,
        'SmartUCF body includes label'
    );
}

$lastMail = null;
$skipReplaySu = MtUniCreditSatrudnikFailureNotifier::maybeNotifyAfterNativeHistory($controller, array(
    'order_id' => 3002,
    'bank_status' => MtUniCreditBankStatus::SEND_FAILED_SMARTUCF,
    'bank_status_transitioned' => false,
    'control_panel_order_id' => 77,
));
mtucSatrudnik_assert($skipReplaySu === false, 'SmartUCF replay transition flag skips send');

$lastMail = null;
$skipNonTarget = MtUniCreditSatrudnikFailureNotifier::maybeNotifyAfterNativeHistory($controller, array(
    'order_id' => 3003,
    'bank_status' => MtUniCreditBankStatus::SENT_PROCESS1,
    'bank_status_transitioned' => true,
));
mtucSatrudnik_assert($skipNonTarget === false, 'non-target status never sends');

MtUniCreditSatrudnikFailureNotifier::setShopResolver(function ($controller) {
    unset($controller);

    return array(
        'uni_email' => 'admin-fallback@example.test',
    );
});
$lastMail = null;
$skipMissing = MtUniCreditSatrudnikFailureNotifier::maybeNotifyAfterNativeHistory($controller, array(
    'order_id' => 3004,
    'bank_status' => MtUniCreditBankStatus::SEND_FAILED_CP,
    'bank_status_transitioned' => true,
));
mtucSatrudnik_assert($skipMissing === false, 'missing satrudnik_email silent skip (no uni_email fallback)');
mtucSatrudnik_assert($lastMail === null, 'missing recipient: no Mail instance');

MtUniCreditSatrudnikFailureNotifier::setShopResolver(function ($controller) {
    unset($controller);

    return array('satrudnik_email' => 'satrudnik@example.test');
});
MtUniCreditSatrudnikFailureNotifier::setMailFactory(function ($engine) {
    $mail = new MtucSatrudnikFakeMail($engine);
    $mail->throwOnSend = true;

    return $mail;
});
$isolated = MtUniCreditSatrudnikFailureNotifier::maybeNotifyAfterNativeHistory($controller, array(
    'order_id' => 3005,
    'bank_status' => MtUniCreditBankStatus::SEND_FAILED_CP,
    'bank_status_transitioned' => true,
));
mtucSatrudnik_assert($isolated === false, 'mail exception isolated (returns false)');
$rowStill = $repo->findByOrderId(0, 2001);
mtucSatrudnik_assert(
    is_array($rowStill) && (string) $rowStill['status_id'] === MtUniCreditBankStatus::SEND_FAILED_CP,
    'mail failure does not clear persisted bank status'
);

MtUniCreditSatrudnikFailureNotifier::setMailFactory(function ($engine) {
    $mail = new MtucSatrudnikFakeMail($engine);
    $mail->returnFalse = true;

    return $mail;
});
$isolatedFalse = MtUniCreditSatrudnikFailureNotifier::maybeNotifyAfterNativeHistory($controller, array(
    'order_id' => 3006,
    'bank_status' => MtUniCreditBankStatus::SEND_FAILED_CP,
    'bank_status_transitioned' => true,
));
mtucSatrudnik_assert($isolatedFalse === false, 'Mail::send false isolated');

MtUniCreditSatrudnikFailureNotifier::setMailFactory(null);
MtUniCreditSatrudnikFailureNotifier::setShopResolver(null);

// ---------------------------------------------------------------------------
// OC3 Registry host: isset(config)=false, but __get works — must send
// ---------------------------------------------------------------------------
MtUniCreditSatrudnikFailureNotifier::setMailFactory(function ($engine) use (&$lastMail) {
    $lastMail = new MtucSatrudnikFakeMail($engine);

    return $lastMail;
});
MtUniCreditSatrudnikFailureNotifier::setShopResolver(function ($controller) {
    unset($controller);

    return array('satrudnik_email' => 'satrudnik@example.test');
});

$registryController = mtucSatrudnik_registryController();
mtucSatrudnik_assert(
    !isset($registryController->config),
    'OC3 trap: isset(registryController->config) is false'
);
mtucSatrudnik_assert(
    is_object($registryController->config),
    'OC3 trap: __get(config) still works'
);

$lastMail = null;
$sentRegistry = MtUniCreditSatrudnikFailureNotifier::maybeNotifyAfterNativeHistory($registryController, array(
    'order_id' => 3010,
    'bank_status' => MtUniCreditBankStatus::SEND_FAILED_SMARTUCF,
    'bank_status_transitioned' => true,
    'control_panel_order_id' => 420,
));
mtucSatrudnik_assert($sentRegistry === true, 'OC3 registry controller: notify sends (no controller_config_unavailable)');
mtucSatrudnik_assert($lastMail instanceof MtucSatrudnikFakeMail, 'OC3 registry controller: Mail created from runtime config');
if ($lastMail instanceof MtucSatrudnikFakeMail) {
    mtucSatrudnik_assert($lastMail->engine === 'smtp', 'OC3 registry: config_mail_engine used');
    mtucSatrudnik_assert($lastMail->from === 'store@example.test', 'OC3 registry: config_email used');
    mtucSatrudnik_assert($lastMail->sender === 'Test Store', 'OC3 registry: config_name used');
    mtucSatrudnik_assert($lastMail->smtp_hostname === 'smtp.example.test', 'OC3 registry: SMTP hostname from Config');
    mtucSatrudnik_assert($lastMail->smtp_username === 'smtp-user', 'OC3 registry: SMTP username from Config');
    mtucSatrudnik_assert($lastMail->smtp_password === 'smtp-pass', 'OC3 registry: SMTP password from Config');
    mtucSatrudnik_assert((string) $lastMail->smtp_port === '587', 'OC3 registry: SMTP port from Config');
}

MtUniCreditSatrudnikFailureNotifier::setMailFactory(null);
MtUniCreditSatrudnikFailureNotifier::setShopResolver(null);

// ---------------------------------------------------------------------------
// Call-site wiring present
// ---------------------------------------------------------------------------
$productSrc = (string) file_get_contents(
    $root . '/upload/catalog/controller/extension/mt_uni_credit/product.php'
);
$cartSrc = (string) file_get_contents(
    $root . '/upload/catalog/controller/extension/mt_uni_credit/cart.php'
);
$checkoutSrc = (string) file_get_contents(
    $root . '/upload/catalog/controller/extension/payment/mt_uni_credit.php'
);
mtucSatrudnik_assert(
    strpos($productSrc, 'MtUniCreditSatrudnikFailureNotifier::maybeNotifyAfterNativeHistory($this, $result)') !== false,
    'Product call site wired with $this controller'
);
mtucSatrudnik_assert(
    strpos($cartSrc, 'MtUniCreditSatrudnikFailureNotifier::maybeNotifyAfterNativeHistory($this, $result)') !== false,
    'Cart call site wired with $this controller'
);
mtucSatrudnik_assert(
    strpos($checkoutSrc, 'MtUniCreditSatrudnikFailureNotifier::maybeNotifyAfterNativeHistory($this, $submit)') !== false,
    'Checkout call site wired with $this controller'
);

echo PHP_EOL . 'Passed: ' . $passes . PHP_EOL;
if ($failures !== array()) {
    echo 'Failed: ' . count($failures) . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo 'All Satrudnik failure-mail checks passed.' . PHP_EOL;
exit(0);
