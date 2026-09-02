<?php

/**
 * Shared storefront helpers for Product/Cart controllers (fresh cache only, fail soft).
 */
final class MtUniCreditStorefrontRuntime
{
    /**
     * @param object $registry OpenCart registry-like controller ($this)
     * @return array{css:string,js:string}
     */
    public static function assetUrls($registry)
    {
        $baseFs = defined('DIR_APPLICATION')
            ? rtrim(DIR_APPLICATION, '/\\') . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'theme'
            . DIRECTORY_SEPARATOR . 'default' . DIRECTORY_SEPARATOR . 'template' . DIRECTORY_SEPARATOR
            . 'extension' . DIRECTORY_SEPARATOR . 'mt_uni_credit' . DIRECTORY_SEPARATOR
            : '';

        return array(
            'css' => MtUniCreditStorefrontAssetUrls::versionedUrl(
                $baseFs . 'storefront.css',
                MtUniCreditConstants::STOREFRONT_ASSET_CSS_RELATIVE
            ),
            'js' => MtUniCreditStorefrontAssetUrls::versionedUrl(
                $baseFs . 'storefront.js',
                MtUniCreditConstants::STOREFRONT_ASSET_JS_RELATIVE
            ),
        );
    }

    /**
     * Resolve storefront store URL like native OC3 checkout/confirm (no bare HTTP_SERVER access).
     *
     * @param object $controller
     * @return string
     */
    public static function storeUrl($controller)
    {
        $storeId = (int) $controller->config->get('config_store_id');
        if ($storeId > 0) {
            return (string) $controller->config->get('config_url');
        }

        $https = isset($controller->request->server['HTTPS'])
            ? $controller->request->server['HTTPS']
            : '';
        $isHttps = $https && strtolower((string) $https) !== 'off';

        if ($isHttps) {
            if (defined('HTTPS_SERVER')) {
                return (string) constant('HTTPS_SERVER');
            }
            $ssl = (string) $controller->config->get('config_ssl');
            if ($ssl !== '') {
                return $ssl;
            }
        }

        if (defined('HTTP_SERVER')) {
            return (string) constant('HTTP_SERVER');
        }

        $url = (string) $controller->config->get('config_url');
        if ($url !== '') {
            return $url;
        }

        return (string) $controller->config->get('config_ssl');
    }

    /**
     * @param object $controller
     * @return array<string, mixed>|null Fresh shop data or null
     */
    public static function loadFreshShop($controller)
    {
        try {
            $storeId = (int) $controller->config->get('config_store_id');
            $db = MtUniCreditBootstrap::dbFromRegistry($controller->db);
            $credentials = MtUniCreditBootstrap::credentialsRepositoryFromDb($db);
            $unicid = $credentials->getUnicid($storeId);
            if ($unicid === '') {
                return null;
            }
            $cache = MtUniCreditBootstrap::shopConfigurationCacheFromDb($db);
            $shop = $cache->getFreshShopData($storeId, $unicid);
            if (!is_array($shop) || $shop === array()) {
                return null;
            }

            return $shop;
        } catch (Exception $exception) {
            return null;
        }
    }

    /**
     * @param object $controller
     * @return MtUniCreditStorefrontFinancingSubmissionService
     */
    public static function submissionService($controller)
    {
        $db = MtUniCreditBootstrap::dbFromRegistry($controller->db);
        $storeId = (int) $controller->config->get('config_store_id');
        $settings = new MtUniCreditSettingStore($db, MtUniCreditConstants::MODULE_SETTINGS_CODE);
        $stack = MtUniCreditCpServiceFactory::create(
            $db,
            $settings,
            $storeId,
            (string) $controller->config->get('config_ssl'),
            (string) $controller->config->get('config_url')
        );
        $attempts = new MtUniCreditFinancingAttemptRepository($db);
        $locks = new MtUniCreditOperationLockRepository($db);
        $lifecycle = new MtUniCreditControlPanelOrderLifecycleService(
            $attempts,
            $locks,
            $stack['client']
        );

        return new MtUniCreditStorefrontFinancingSubmissionService(
            $attempts,
            $locks,
            $lifecycle,
            $stack['credentials'],
            MtUniCreditBootstrap::shopConfigurationCacheFromDb($db)
        );
    }

    /**
     * @param object $controller
     * @param int $productId
     * @return int[]
     */
    public static function productCategories($controller, $productId)
    {
        $productId = (int) $productId;
        if ($productId <= 0) {
            return array();
        }
        try {
            $controller->load->model('extension/mt_uni_credit/product');
            if (isset($controller->model_extension_mt_uni_credit_product)) {
                return $controller->model_extension_mt_uni_credit_product->getCategories($productId);
            }
        } catch (Exception $exception) {
            // fall through
        }

        $rows = $controller->db->query(
            "SELECT category_id FROM `" . DB_PREFIX . "product_to_category`"
                . " WHERE product_id = '" . (int) $productId . "'"
        );
        $categories = array();
        if (is_object($rows) && isset($rows->rows) && is_array($rows->rows)) {
            foreach ($rows->rows as $row) {
                $categories[] = (int) $row['category_id'];
            }
        }

        return $categories;
    }

    /**
     * @param object $controller
     * @param int $productId
     * @param int $quantity
     * @param array<int|string, mixed> $options
     * @return MtUniCreditProductLine|null
     */
    public static function resolveProductLine($controller, $productId, $quantity, array $options)
    {
        $controller->load->model('catalog/product');
        $product = $controller->model_catalog_product->getProduct((int) $productId);
        if (!is_array($product) || empty($product['product_id'])) {
            return null;
        }
        if ((int) (isset($product['status']) ? $product['status'] : 1) !== 1) {
            return null;
        }

        $baseCurrency = (string) $controller->config->get('config_currency');
        $displayCurrency = isset($controller->session->data['currency'])
            ? (string) $controller->session->data['currency']
            : $baseCurrency;

        $tax = function ($price, $taxClassId) use ($controller) {
            return (float) $controller->tax->calculate(
                (float) $price,
                (int) $taxClassId,
                (bool) $controller->config->get('config_tax')
            );
        };
        $convert = function ($amount, $from, $to) use ($controller) {
            if (strtoupper((string) $from) === strtoupper((string) $to)) {
                return (float) $amount;
            }

            return (float) $controller->currency->convert((float) $amount, (string) $from, (string) $to);
        };

        $self = $controller;
        $optionLoader = function ($productOptionId, $value) use ($self) {
            $productOptionId = (int) $productOptionId;
            if (!is_numeric($value)) {
                return null;
            }
            $productOptionValueId = (int) $value;
            $query = $self->db->query(
                "SELECT pov.product_option_value_id, pov.price, pov.price_prefix, ovd.name,"
                    . " od.name AS option_name, o.type"
                    . " FROM `" . DB_PREFIX . "product_option_value` pov"
                    . " LEFT JOIN `" . DB_PREFIX . "option_value_description` ovd"
                    . " ON (pov.option_value_id = ovd.option_value_id)"
                    . " LEFT JOIN `" . DB_PREFIX . "product_option` po"
                    . " ON (pov.product_option_id = po.product_option_id)"
                    . " LEFT JOIN `" . DB_PREFIX . "option` o ON (po.option_id = o.option_id)"
                    . " LEFT JOIN `" . DB_PREFIX . "option_description` od"
                    . " ON (o.option_id = od.option_id AND od.language_id = '" . (int) $self->config->get('config_language_id') . "')"
                    . " WHERE pov.product_option_value_id = '" . (int) $productOptionValueId . "'"
                    . " AND pov.product_option_id = '" . (int) $productOptionId . "'"
                    . " AND ovd.language_id = '" . (int) $self->config->get('config_language_id') . "'"
                    . " LIMIT 1"
            );
            if (!is_object($query) || !isset($query->num_rows) || (int) $query->num_rows !== 1) {
                return null;
            }

            return $query->row;
        };

        $resolver = new MtUniCreditOc3ProductLineResolver(
            $tax,
            $convert,
            function ($pid) use ($controller) {
                return MtUniCreditStorefrontRuntime::productCategories($controller, $pid);
            },
            $optionLoader
        );

        try {
            $line = $resolver->resolve(
                $product,
                $quantity,
                $options,
                $baseCurrency,
                $displayCurrency
            );
        } catch (Exception $exception) {
            return null;
        }

        if ($line->financingPrice <= 0) {
            return null;
        }

        return $line;
    }

    /**
     * @param object $controller
     * @return MtUniCreditCartContext|null
     */
    public static function resolveCartContext($controller)
    {
        try {
            $products = $controller->cart->getProducts();
            if (!is_array($products) || $products === array()) {
                return null;
            }
            $total = (float) $controller->cart->getTotal();
            $factory = new MtUniCreditOc3CartContextFactory(
                function ($productId) use ($controller) {
                    return MtUniCreditStorefrontRuntime::productCategories($controller, $productId);
                },
                function ($price, $taxClassId) use ($controller) {
                    return (float) $controller->tax->calculate(
                        (float) $price,
                        (int) $taxClassId,
                        (bool) $controller->config->get('config_tax')
                    );
                }
            );

            return $factory->create($products, $total);
        } catch (Exception $exception) {
            return null;
        }
    }

    /**
     * @param object $controller
     * @param array<string, mixed> $json
     * @return void
     */
    public static function respondJson($controller, array $json)
    {
        $controller->response->addHeader('Content-Type: application/json; charset=utf-8');
        $controller->response->setOutput(json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
