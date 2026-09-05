<?php

/**
 * Wires CP auth and shop configuration services for a store scope.
 */
final class MtUniCreditCpServiceFactory
{
    /**
     * @param MtUniCreditDbAdapter $db
     * @param MtUniCreditSettingStore $settings
     * @param int $storeId
     * @param string $catalogSslUrl
     * @param string $catalogPlainUrl
     * @param MtUniCreditCpHttpTransport|null $transport
     * @param callable|null $wallClock
     * @param string|null $encryptionSecretInputOverride
     * @param string|null $environmentConfigPath
     * @return array<string, mixed>
     */
    public static function create(
        MtUniCreditDbAdapter $db,
        MtUniCreditSettingStore $settings,
        $storeId,
        $catalogSslUrl,
        $catalogPlainUrl,
        $transport = null,
        $wallClock = null,
        $encryptionSecretInputOverride = null,
        $environmentConfigPath = null
    ) {
        $provider = new MtUniCreditEncryptionKeyProvider();
        $cipher = new MtUniCreditSettingCipher($provider->resolveDerivedKey($encryptionSecretInputOverride));
        $credentials = new MtUniCreditCredentialsRepository($settings, $cipher);
        $tokens = new MtUniCreditCpTokenRepository($settings, $cipher, $storeId);
        $shopName = (new MtUniCreditCanonicalShopUrlProvider())->resolve($catalogSslUrl, $catalogPlainUrl);

        $baseUrl = null;
        if ($environmentConfigPath !== null && $environmentConfigPath !== '') {
            $baseUrl = (new MtUniCreditDeploymentEnvironment($environmentConfigPath))->controlPanelApiBaseUrl();
        }

        $client = new MtUniCreditControlPanelClient(
            $credentials,
            $tokens,
            $transport !== null ? $transport : new MtUniCreditCurlCpHttpTransport(),
            $shopName,
            $storeId,
            $baseUrl,
            $wallClock,
            $environmentConfigPath
        );

        $cache = new MtUniCreditShopCacheRepository($db);
        $shopCachePersistence = MtUniCreditBootstrap::shopCachePersistenceFromDb($db);
        $shopConfiguration = new MtUniCreditShopConfigurationService(
            $credentials,
            $cache,
            $client,
            $tokens,
            $storeId,
            null,
            $shopCachePersistence
        );
        $credentialChange = new MtUniCreditCredentialChangeHandler($tokens, $cache, $storeId);

        return array(
            'credentials' => $credentials,
            'tokens' => $tokens,
            'client' => $client,
            'shopConfiguration' => $shopConfiguration,
            'credentialChange' => $credentialChange,
        );
    }
}
