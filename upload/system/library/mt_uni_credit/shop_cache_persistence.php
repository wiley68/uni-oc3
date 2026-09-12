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
    const EVENT_LOCK_RELEASE_ANOMALY = 'shop_cache_lock_release_anomaly';

    /** @var MtUniCreditShopCacheRepository */
    private $cache;

    /** @var MtUniCreditShopConfigurationSnapshotValidator */
    private $validator;

    /** @var MtUniCreditSmartucfCredentialsRepository */
    private $smartucfCredentials;

    /** @var MtUniCreditShopCachePersistenceLock */
    private $persistenceLock;

    /** @var callable|null fn(string $message): void */
    private $infrastructureLogger;

    /**
     * @param MtUniCreditShopCacheRepository $cache
     * @param MtUniCreditShopConfigurationSnapshotValidator $validator
     * @param MtUniCreditSmartucfCredentialsRepository $smartucfCredentials
     * @param MtUniCreditShopCachePersistenceLock $persistenceLock
     * @param callable|null $infrastructureLogger Optional override; default error_log.
     */
    public function __construct(
        MtUniCreditShopCacheRepository $cache,
        MtUniCreditShopConfigurationSnapshotValidator $validator,
        MtUniCreditSmartucfCredentialsRepository $smartucfCredentials,
        MtUniCreditShopCachePersistenceLock $persistenceLock,
        $infrastructureLogger = null
    ) {
        $this->cache = $cache;
        $this->validator = $validator;
        $this->smartucfCredentials = $smartucfCredentials;
        $this->persistenceLock = $persistenceLock;
        $this->infrastructureLogger = is_callable($infrastructureLogger) ? $infrastructureLogger : null;
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
        if ($partition['pair_state'] === 'invalid') {
            throw new MtUniCreditPersistenceValidationException(
                'SmartUCF credential pair is incomplete or invalid.'
            );
        }

        if (!$this->persistenceLock->acquire($storeId, $unicid)) {
            throw new MtUniCreditPersistenceException('Shop cache replacement is busy.');
        }

        $lockHeld = true;
        $persistedOk = false;
        $credentialMutation = $partition['pair_state'] === 'complete';
        $previousCredentialState = null;

        try {
            $previousCredentialState = $credentialMutation
                ? $this->smartucfCredentials->capturePairState($storeId)
                : null;

            try {
                if ($credentialMutation) {
                    $this->smartucfCredentials->savePair(
                        $storeId,
                        $partition['smartucf_user'],
                        $partition['smartucf_password']
                    );
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
                $release = $this->persistenceLock->release($storeId, $unicid);
                if (!is_array($release) || empty($release['ok'])) {
                    $outcome = is_array($release) && isset($release['outcome'])
                        ? (string) $release['outcome']
                        : MtUniCreditShopCachePersistenceLock::RELEASE_OUTCOME_MISSING_OR_ERROR;
                    $this->recordLockReleaseAnomaly((int) $storeId, $outcome, $persistedOk);
                }
            }
        }
    }

    /**
     * Local infrastructure diagnostic only — never affects business success/failure.
     *
     * @param int $storeId
     * @param string $outcome
     * @param bool $persistedOk
     * @return void
     */
    private function recordLockReleaseAnomaly($storeId, $outcome, $persistedOk)
    {
        $safeOutcome = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $outcome));
        if (!is_string($safeOutcome) || $safeOutcome === '') {
            $safeOutcome = 'unknown';
        }

        $message = 'mt_uni_credit: ' . self::EVENT_LOCK_RELEASE_ANOMALY
            . ' component=shop_cache_persistence'
            . ' event=' . self::EVENT_LOCK_RELEASE_ANOMALY
            . ' store_id=' . (int) $storeId
            . ' outcome=' . $safeOutcome
            . ' persistence=' . ($persistedOk ? 'committed' : 'failed');

        try {
            if ($this->infrastructureLogger !== null) {
                call_user_func($this->infrastructureLogger, $message);

                return;
            }
            error_log($message);
        } catch (Exception $exception) {
            // Observability must not convert committed persistence into business failure.
            unset($exception);
        }
    }
}
