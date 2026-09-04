<?php

require_once DIR_SYSTEM . 'library/mt_uni_credit/bootstrap.php';

/**
 * Homepage advertising assets — catalog/controller/common/home/before.
 */
class ControllerExtensionMtUniCreditHome extends Controller
{
    /**
     * @param string $route
     * @param mixed $data
     * @return void
     */
    public function beforeHome(&$route, &$data)
    {
        if ((string) $route !== 'common/home') {
            return;
        }
        if ($this->resolveAdvertisingContext('common/home') === null) {
            return;
        }

        $assets = $this->advertisingAssetUrls();
        if ($assets['fonts'] !== '') {
            $this->document->addStyle($assets['fonts']);
        }
        if ($assets['css'] !== '') {
            $this->document->addStyle($assets['css']);
        }
        if ($assets['js'] !== '') {
            $this->document->addScript($assets['js']);
        }
    }

    /**
     * catalog/view/common/footer/after — homepage requests only.
     *
     * @param string $route
     * @param array $data
     * @param string $output
     * @return void
     */
    public function afterFooter(&$route, &$data, &$output)
    {
        if ((string) $route !== 'common/footer') {
            return;
        }
        if (!is_string($output)) {
            return;
        }
        if (strpos($output, 'mt-uni-credit-advertising-root') !== false) {
            return;
        }

        $request = $this->request;
        $requestRoute = '';
        if (is_object($request) && isset($request->get['route'])) {
            $requestRoute = (string) $request->get['route'];
        }
        $requestRoute = MtUniCreditStorefrontRouteResolver::currentRoute($requestRoute);
        if (!MtUniCreditStorefrontRouteResolver::isHomepageRoute($requestRoute)) {
            return;
        }

        $context = $this->resolveAdvertisingContext($requestRoute);
        if ($context === null) {
            return;
        }

        $this->load->language('extension/mt_uni_credit/home');
        $viewData = array(
            'mt_uni_credit_advertising' => $context,
            'text_float_alt' => $this->language->get('text_float_alt'),
            'text_panel_label' => $this->language->get('text_panel_label'),
            'text_panel_cta' => $this->language->get('text_panel_cta'),
            'text_close' => $this->language->get('text_close'),
        );
        $fragment = $this->load->view('extension/mt_uni_credit/homepage_advertising', $viewData);
        if (!is_string($fragment) || trim($fragment) === '') {
            return;
        }
        $output .= $fragment;
    }

    /**
     * @param string $route
     * @return array<string, mixed>|null
     */
    private function resolveAdvertisingContext($route)
    {
        if (!defined('DB_PREFIX')) {
            return null;
        }

        $moduleEnabled = MtUniCreditLocalSettings::normalizeFlag(
            $this->config->get(MtUniCreditConstants::MODULE_SETTING_STATUS)
        ) === '1';
        $advertisingEnabled = MtUniCreditLocalSettings::normalizeFlag(
            $this->config->get(MtUniCreditConstants::MODULE_SETTING_ADVERTISING)
        ) === '1';

        $storeId = (int) $this->config->get('config_store_id');
        try {
            $db = MtUniCreditBootstrap::dbFromRegistry($this->db);
            $settings = new MtUniCreditSettingStore($db, MtUniCreditConstants::MODULE_SETTINGS_CODE);
            $services = MtUniCreditCpServiceFactory::create(
                $db,
                $settings,
                $storeId,
                (string) $this->config->get('config_ssl'),
                (string) $this->config->get('config_url')
            );
        } catch (Exception $exception) {
            return null;
        }

        $gate = new MtUniCreditHomepageAdvertisingGate();
        $resolver = new MtUniCreditHomepageAdvertisingContextResolver(
            $gate,
            new MtUniCreditHomepageAdvertisingPresenter($gate),
            $services['shopConfiguration'],
            $services['credentials'],
            $storeId
        );

        $ua = '';
        $request = $this->request;
        if (is_object($request) && isset($request->server['HTTP_USER_AGENT'])) {
            $ua = (string) $request->server['HTTP_USER_AGENT'];
        }

        return $resolver->resolve(
            MtUniCreditStorefrontRouteResolver::isHomepageRoute($route),
            $moduleEnabled,
            $advertisingEnabled,
            MtUniCreditStorefrontMobileDetector::isMobile($ua),
            $this->defaultLogoUrl()
        );
    }

    /**
     * @return array{css:string,js:string,fonts:string}
     */
    private function advertisingAssetUrls()
    {
        $baseFs = defined('DIR_APPLICATION')
            ? rtrim(DIR_APPLICATION, '/\\') . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'theme'
            . DIRECTORY_SEPARATOR . 'default' . DIRECTORY_SEPARATOR . 'template' . DIRECTORY_SEPARATOR
            . 'extension' . DIRECTORY_SEPARATOR . 'mt_uni_credit' . DIRECTORY_SEPARATOR
            : '';

        return array(
            'fonts' => MtUniCreditStorefrontAssetUrls::versionedUrl(
                $baseFs . 'storefront_fonts.css',
                MtUniCreditConstants::STOREFRONT_ASSET_FONTS_CSS_RELATIVE
            ),
            'css' => MtUniCreditStorefrontAssetUrls::versionedUrl(
                $baseFs . 'mt_uni_credit_homepage_advertising.css',
                MtUniCreditConstants::HOMEPAGE_ADVERTISING_CSS_RELATIVE
            ),
            'js' => MtUniCreditStorefrontAssetUrls::versionedUrl(
                $baseFs . 'mt_uni_credit_homepage_advertising.js',
                MtUniCreditConstants::HOMEPAGE_ADVERTISING_JS_RELATIVE
            ),
        );
    }

    /**
     * @return string
     */
    private function defaultLogoUrl()
    {
        $assets = MtUniCreditStorefrontRuntime::assetUrls($this);
        if (!empty($assets['logo_standard'])) {
            return (string) $assets['logo_standard'];
        }

        return MtUniCreditConstants::STOREFRONT_LOGO_STANDARD_RELATIVE;
    }
}
