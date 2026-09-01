<?php

/**
 * OpenCart 3.x — IDE stubs за Intelephense.
 *
 * uni-oc3 съдържа само разширението (без OC3 core). Тези декларации описват
 * registry услугите, достъпвани през Controller/Model::__get(), за да не се
 * маркират като undefined property/method в редактора.
 *
 * Не се зареждат от OpenCart при runtime.
 */

namespace {

    if (!defined('DB_PREFIX')) {
        define('DB_PREFIX', 'oc_');
    }
    if (!defined('DIR_SYSTEM')) {
        define('DIR_SYSTEM', '');
    }
    if (!defined('DIR_APPLICATION')) {
        define('DIR_APPLICATION', '');
    }
    if (!defined('DIR_STORAGE')) {
        define('DIR_STORAGE', '');
    }
    if (!defined('VERSION')) {
        define('VERSION', '3.0.3.9');
    }

    class Registry
    {
        /** @return mixed */
        public function get($key)
        {
            return null;
        }

        /** @param mixed $value */
        public function set($key, $value) {}

        /** @return bool */
        public function has($key)
        {
            return false;
        }
    }

    class Config
    {
        /** @return mixed */
        public function get($key)
        {
            return null;
        }

        /** @param mixed $value */
        public function set($key, $value) {}

        /** @return bool */
        public function has($key)
        {
            return false;
        }
    }

    /** Прокси към зареден модел (динамични model_* свойства). */
    class Proxy
    {
        /**
         * @param array<int, mixed> $args
         * @return mixed
         */
        public function __call($name, $args)
        {
            return null;
        }
    }

    class Loader
    {
        /** @param string $route */
        public function model($route) {}

        /** @param string $route */
        public function language($route) {}

        /**
         * @param string $route
         * @param array<string, mixed> $data
         * @return string
         */
        public function view($route, $data = array())
        {
            return '';
        }

        /**
         * @param string $route
         * @param array<string, mixed> $data
         * @return mixed
         */
        public function controller($route, $data = array())
        {
            return null;
        }
    }

    class Document
    {
        /** @param string $title */
        public function setTitle($title) {}

        /** @param string $href */
        public function addStyle($href, $rel = 'stylesheet', $media = 'screen') {}

        /** @param string $href */
        public function addScript($href, $position = 'header') {}
    }

    class Language
    {
        /** @return string */
        public function get($key)
        {
            return '';
        }
    }

    class Request
    {
        /** @var array<string, mixed> */
        public $get = array();

        /** @var array<string, mixed> */
        public $post = array();

        /** @var array<string, mixed> */
        public $cookie = array();

        /** @var array<string, mixed> */
        public $server = array();
    }

    class Response
    {
        /** @param string $header */
        public function addHeader($header) {}

        /** @param string $output */
        public function setOutput($output) {}

        /** @return string */
        public function getOutput()
        {
            return '';
        }

        /** @param string $url */
        public function redirect($url) {}
    }

    class Session
    {
        /** @var array<string, mixed> */
        public $data = array();

        /** @return string */
        public function getId()
        {
            return '';
        }
    }

    class Url
    {
        /**
         * @param string $route
         * @param string $args
         * @param bool $secure
         * @return string
         */
        public function link($route, $args = '', $secure = false)
        {
            return '';
        }
    }

    class DBResult
    {
        /** @var array<int, array<string, mixed>> */
        public $rows = array();

        /** @var int */
        public $num_rows = 0;

        /** @var array<string, mixed>|null */
        public $row;
    }

    class DB
    {
        /** @return DBResult */
        public function query($sql)
        {
            return new DBResult();
        }

        /** @return string */
        public function escape($value)
        {
            return '';
        }

        /** @return int */
        public function getLastId()
        {
            return 0;
        }
    }

    /**
     * Admin/catalog базов контролер — услуги през Registry::__get().
     *
     * @property Loader $load
     * @property Language $language
     * @property Config $config
     * @property Request $request
     * @property Response $response
     * @property Session $session
     * @property Url $url
     * @property Document $document
     * @property \Cart\User $user
     * @property Proxy $model_setting_setting
     * @property ModelExtensionModuleMtUniCredit $model_extension_module_mt_uni_credit
     * @property ModelExtensionPaymentMtUniCredit $model_extension_payment_mt_uni_credit
     */
    abstract class Controller
    {
        /** @var Registry */
        protected $registry;

        /** @param Registry|null $registry */
        public function __construct($registry = null) {}

        /** @return mixed */
        public function __get($key)
        {
            return null;
        }

        /** @param mixed $value */
        public function __set($key, $value) {}
    }

    /**
     * @property DB $db
     * @property Loader $load
     * @property Config $config
     * @property Request $request
     * @property Session $session
     * @property Proxy $model_setting_setting
     */
    abstract class Model
    {
        /** @var Registry */
        protected $registry;

        /** @param Registry|null $registry */
        public function __construct($registry = null) {}

        /** @return mixed */
        public function __get($key)
        {
            return null;
        }

        /** @param mixed $value */
        public function __set($key, $value) {}
    }

    /**
     * @method mixed getSettingValue(string $key, int $storeId = 0)
     * @method array<string, mixed> getSetting(string $code, int $storeId = 0)
     * @method void editSetting(string $code, array $data, int $storeId = 0)
     * @method void deleteSetting(string $code, int $storeId = 0)
     */
    class ModelSettingSetting extends Model {}
}

namespace Cart {

    class User
    {
        /**
         * @param string $type
         * @param string $route
         * @return bool
         */
        public function hasPermission($type, $route)
        {
            return false;
        }

        /** @return int */
        public function getId()
        {
            return 0;
        }
    }
}
