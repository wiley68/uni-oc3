<?php

/**
 * Shop configuration cache orchestration — lookup, refresh, validation, invalidation.
 */
class MtUniCreditShopConfigurationService
{
    /** @var MtUniCreditCredentialsRepository */
    private $credentials;

    /** @var MtUniCreditShopCacheRepository */
    private $cache;

    /** @var MtUniCreditControlPanelClient */
    private $client;

    /** @var MtUniCreditCpTokenRepository */
    private $tokens;

    /** @var MtUniCreditShopConfigurationSnapshotValidator */
    private $snapshotValidator;

    /** @var int */
    private $storeId;

    /**
     * @param MtUniCreditCredentialsRepository $credentials
     * @param MtUniCreditShopCacheRepository $cache
     * @param MtUniCreditControlPanelClient $client
     * @param MtUniCreditCpTokenRepository $tokens
     * @param int $storeId
     * @param MtUniCreditShopConfigurationSnapshotValidator|null $snapshotValidator
     */
    public function __construct(
        MtUniCreditCredentialsRepository $credentials,
        MtUniCreditShopCacheRepository $cache,
        MtUniCreditControlPanelClient $client,
        MtUniCreditCpTokenRepository $tokens,
        $storeId,
        $snapshotValidator = null
    ) {
        $this->credentials = $credentials;
        $this->cache = $cache;
        $this->client = $client;
        $this->tokens = $tokens;
        $this->storeId = (int) $storeId;
        $this->snapshotValidator = $snapshotValidator instanceof MtUniCreditShopConfigurationSnapshotValidator
            ? $snapshotValidator
            : new MtUniCreditShopConfigurationSnapshotValidator();
    }

    /**
     * @param bool $forceRefresh
     * @return array<string, mixed>
     */
    public function get($forceRefresh = false)
    {
        $unicid = $this->credentials->getUnicid($this->storeId);
        if ($unicid === '') {
            $this->purgePermanentFailure($unicid);
            throw new MtUniCreditCpAuthenticationException('UNICID is required to load the shop configuration.');
        }

        if (!$forceRefresh) {
            $cached = $this->cache->findFresh($this->storeId, $unicid);
            if ($cached !== null) {
                return $cached['shop_data'];
            }
        }

        return $this->refresh($unicid);
    }

    /**
     * Cache-only snapshot — never calls remote CP.
     *
     * @return array<string, mixed>|null
     */
    public function getCachedOnly()
    {
        $unicid = $this->credentials->getUnicid($this->storeId);
        if ($unicid === '') {
            return null;
        }

        try {
            $cached = $this->cache->findFresh($this->storeId, $unicid);

            return $cached !== null ? $cached['shop_data'] : null;
        } catch (Exception $exception) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getMetadata()
    {
        $unicid = $this->credentials->getUnicid($this->storeId);
        if ($unicid === '') {
            return null;
        }

        return $this->cache->findMetadata($this->storeId, $unicid);
    }

    /**
     * @return array<string, mixed>
     */
    public function refreshRemote()
    {
        $unicid = $this->credentials->getUnicid($this->storeId);
        if ($unicid === '') {
            throw new MtUniCreditCpAuthenticationException('UNICID is required to refresh the shop configuration.');
        }

        return $this->refresh($unicid);
    }

    /**
     * CP push path: validate and replace local shop cache (no remote GET).
     *
     * @param string $unicid
     * @param array<string, mixed> $shopData
     * @return bool
     */
    public function replaceSnapshot($unicid, array $shopData)
    {
        $unicid = trim((string) $unicid);
        if ($unicid === '' || $shopData === array()) {
            return false;
        }

        $this->snapshotValidator->validate($shopData, $unicid);
        $this->cache->replaceValidated($this->storeId, $unicid, $shopData);

        return true;
    }

    /**
     * @param string $unicid
     * @return array<string, mixed>
     */
    private function refresh($unicid)
    {
        try {
            $response = $this->client->getShop();
            $shopData = isset($response['data']) ? $response['data'] : null;
            if (!is_array($shopData) || $shopData === array()) {
                throw new MtUniCreditCpInvalidPayloadException('The Control Panel returned no usable shop configuration.');
            }

            $this->snapshotValidator->validate($shopData, $unicid);
            $this->cache->replaceValidated($this->storeId, $unicid, $shopData);

            return $shopData;
        } catch (MtUniCreditShopSnapshotValidationException $exception) {
            throw $exception;
        } catch (MtUniCreditCpAuthenticationException $exception) {
            $this->purgePermanentFailure($unicid);
            throw $exception;
        } catch (MtUniCreditCpHttpException $exception) {
            if ($exception->isPermanentAuthOrConfiguration()) {
                $this->purgePermanentFailure($unicid);
            }

            throw $exception;
        } catch (MtUniCreditCpInvalidPayloadException $exception) {
            $this->purgePermanentFailure($unicid);
            throw $exception;
        }
    }

    /**
     * @param string $unicid
     * @return void
     */
    private function purgePermanentFailure($unicid)
    {
        if ($unicid !== '') {
            $this->cache->deleteScoped($this->storeId, $unicid);
        }
        $this->tokens->invalidate();
    }
}
