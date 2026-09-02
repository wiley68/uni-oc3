<?php

/**
 * Offline shop configuration cache orchestration — validate, persist, read (no CP network).
 */
final class MtUniCreditShopConfigurationCache
{
    /** @var MtUniCreditShopCacheRepository */
    private $cache;

    /** @var MtUniCreditShopConfigurationSnapshotValidator */
    private $validator;

    /** @var MtUniCreditShopCachePersistence|null */
    private $persistence;

    /**
     * @param MtUniCreditShopCacheRepository $cache
     * @param MtUniCreditShopConfigurationSnapshotValidator|null $validator
     * @param MtUniCreditShopCachePersistence|null $persistence
     */
    public function __construct(MtUniCreditShopCacheRepository $cache, $validator = null, $persistence = null)
    {
        $this->cache = $cache;
        $this->validator = $validator instanceof MtUniCreditShopConfigurationSnapshotValidator
            ? $validator
            : new MtUniCreditShopConfigurationSnapshotValidator();
        $this->persistence = $persistence instanceof MtUniCreditShopCachePersistence ? $persistence : null;
    }

    /**
     * @param int $storeId
     * @param string $unicid
     * @param array<string, mixed> $shopData
     * @return bool
     */
    public function replaceSnapshot($storeId, $unicid, array $shopData)
    {
        $unicid = trim($unicid);
        if ($unicid === '' || $shopData === array()) {
            return false;
        }

        if ($this->persistence !== null) {
            try {
                $this->persistence->replaceValidatedSnapshot($storeId, $unicid, $shopData);
            } catch (MtUniCreditShopSnapshotValidationException $exception) {
                return false;
            } catch (MtUniCreditPersistenceValidationException $exception) {
                return false;
            }

            return true;
        }

        try {
            $this->validator->validate($shopData, $unicid);
        } catch (MtUniCreditShopSnapshotValidationException $exception) {
            return false;
        }

        $this->cache->replaceValidated($storeId, $unicid, MtUniCreditShopSnapshotSanitizer::sanitize($shopData));

        return true;
    }

    /**
     * @param int $storeId
     * @param string $unicid
     * @return array<string, mixed>|null
     */
    public function getFreshShopData($storeId, $unicid)
    {
        $row = $this->cache->findFresh($storeId, $unicid);

        return $row !== null ? $row['shop_data'] : null;
    }

    /**
     * @param int $storeId
     * @param string $unicid
     * @return array<string, mixed>|null
     */
    public function getLatestShopData($storeId, $unicid)
    {
        $row = $this->cache->findLatest($storeId, $unicid);

        return $row !== null ? $row['shop_data'] : null;
    }

    /**
     * @param int $storeId
     * @param string $unicid
     * @return array<string, mixed>|null
     */
    public function getMetadata($storeId, $unicid)
    {
        return $this->cache->findMetadata($storeId, $unicid);
    }

    /**
     * @param int $storeId
     * @param string $unicid
     * @return void
     */
    public function deleteScoped($storeId, $unicid)
    {
        $this->cache->deleteScoped($storeId, $unicid);
    }
}
