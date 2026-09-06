<?php

/**
 * Shared validated shop snapshot persistence for outbound refresh and inbound push.
 *
 * Credential + cache replacement is failure-atomic via exact compensating restore
 * (OC3 DB has no transaction API; oc_setting is not reliably transactional with InnoDB).
 *
 * Concurrent replacements for the same (store_id, unicid) are serialized with a
 * connection-owned MySQL advisory lock held across capture, writes, and rollback.
 */
final class MtUniCreditShopCachePersistence
{
    /** @var MtUniCreditShopCacheRepository */
    private $cache;

    /** @var MtUniCreditShopConfigurationSnapshotValidator */
    private $validator;

    /** @var MtUniCreditSmartucfCredentialsRepository */
    private $smartucfCredentials;

    /** @var MtUniCreditShopCachePersistenceLock */
    private $persistenceLock;

    /**
     * @param MtUniCreditShopCacheRepository $cache
     * @param MtUniCreditShopConfigurationSnapshotValidator $validator
     * @param MtUniCreditSmartucfCredentialsRepository $smartucfCredentials
     * @param MtUniCreditShopCachePersistenceLock $persistenceLock
     */
    public function __construct(
        MtUniCreditShopCacheRepository $cache,
        MtUniCreditShopConfigurationSnapshotValidator $validator,
        MtUniCreditSmartucfCredentialsRepository $smartucfCredentials,
        MtUniCreditShopCachePersistenceLock $persistenceLock
    ) {
        $this->cache = $cache;
        $this->validator = $validator;
        $this->smartucfCredentials = $smartucfCredentials;
        $this->persistenceLock = $persistenceLock;
    }

    /**
     * @param int $storeId
     * @param string $unicid
     * @param array<string, mixed> $shopData
     * @return void
     */
    public function replaceValidatedSnapshot($storeId, $unicid, array $shopData)
    {
        MtUniCreditStoreScope::requireStoreId($storeId);
        $unicid = trim($unicid);
        if ($unicid === '' || $shopData === array()) {
            throw new MtUniCreditPersistenceValidationException('Shop snapshot requires UNICID and non-empty data.');
        }

        $this->validator->validate($shopData, $unicid);
        $partition = MtUniCreditShopSnapshotSanitizer::partitionSensitiveFields($shopData);

        if (!$this->persistenceLock->acquire($storeId, $unicid)) {
            throw new MtUniCreditPersistenceException('Shop cache replacement is busy.');
        }

        $lockHeld = true;
        $persistedOk = false;
        $credentialMutation = false;
        $previousCredentialState = null;

        try {
            $credentialMutation = $partition['smartucf_password'] !== null || $partition['smartucf_user'] !== null;
            $previousCredentialState = $credentialMutation
                ? $this->smartucfCredentials->capturePairState($storeId)
                : null;

            try {
                if ($partition['smartucf_password'] !== null) {
                    $this->smartucfCredentials->savePair(
                        $storeId,
                        $partition['smartucf_user'],
                        $partition['smartucf_password']
                    );
                } elseif ($partition['smartucf_user'] !== null) {
                    $this->smartucfCredentials->savePair($storeId, $partition['smartucf_user'], null);
                }

                $this->cache->replaceValidated($storeId, $unicid, $partition['sanitized']);
                $persistedOk = true;
            } catch (Exception $exception) {
                if ($credentialMutation && is_array($previousCredentialState)) {
                    try {
                        $this->smartucfCredentials->restorePairState($storeId, $previousCredentialState);
                    } catch (Exception $rollbackException) {
                        throw new MtUniCreditPersistenceException(
                            'Shop cache credential rollback failed after a persistence error.',
                            0,
                            $exception
                        );
                    }
                }
                throw $exception;
            }
        } finally {
            if ($lockHeld) {
                try {
                    $this->persistenceLock->release($storeId, $unicid);
                } catch (Exception $releaseException) {
                    // Persistence already committed or rolled back under the lock.
                    // Do not convert a successful replacement into a caller-visible failure.
                    if (!$persistedOk) {
                        // Keep the original failure path dominant; release noise is secondary.
                        unset($releaseException);
                    }
                }
            }
        }
    }
}
