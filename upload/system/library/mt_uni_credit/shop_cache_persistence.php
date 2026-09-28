<?php

/**
 * Shared validated shop snapshot persistence for outbound refresh and inbound push.
 *
 * Credential + cache replacement is failure-atomic via exact compensating restore
 * (OC3 DB has no transaction API; oc_setting is not reliably transactional with InnoDB).
 *
 * Concurrent replacements for the same (store_id, unicid) — pull refresh and push/manual —
 * are serialized with ONE connection-owned MySQL advisory lock identity held across capture,
 * writes, and rollback. There is exactly one public validated replacement API; the refresh
 * owner reuses its already-verified exact-scope ownership instead of re-acquiring the lock.
 *
 * EVERY write goes through the same hardened ownership authorization immediately before the
 * write: the exact acquisition token, the exact lock name and the live connection proof
 * (CONNECTION_ID() = captured owner CONNECTION_ID() = IS_USED_LOCK(exact name)) must all still
 * hold on the current connection. A reconnect or lost advisory lock between acquire() and the
 * write fails closed - the already-fetched payload is NOT written and is NEVER silently
 * re-acquired or downgraded into a transient/LKG path.
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

    /** @var MtUniCreditShopConfigurationRefreshLock */
    private $scopeLock;

    /** @var callable|null Secret-free diagnostic sink receiving one line per event. */
    private $infrastructureLogger;

    /**
     * @param MtUniCreditShopCacheRepository $cache
     * @param MtUniCreditShopConfigurationSnapshotValidator $validator
     * @param MtUniCreditSmartucfCredentialsRepository $smartucfCredentials
     * @param MtUniCreditShopConfigurationRefreshLock $scopeLock Shared exact-scope lock instance.
     * @param callable|null $infrastructureLogger Optional override; default error_log.
     */
    public function __construct(
        MtUniCreditShopCacheRepository $cache,
        MtUniCreditShopConfigurationSnapshotValidator $validator,
        MtUniCreditSmartucfCredentialsRepository $smartucfCredentials,
        MtUniCreditShopConfigurationRefreshLock $scopeLock,
        $infrastructureLogger = null
    ) {
        $this->cache = $cache;
        $this->validator = $validator;
        $this->smartucfCredentials = $smartucfCredentials;
        $this->scopeLock = $scopeLock;
        $this->infrastructureLogger = is_callable($infrastructureLogger) ? $infrastructureLogger : null;
    }

    /**
     * ONE validated replacement entry point for refresh, push, and manual persistence.
     *
     * A. This lock instance already holds the exact-scope proof:
     *    re-verify the exact acquisition token + exact lock name + connection-bound ownership,
     *    then persist WITHOUT re-acquiring.
     * B. No ownership proof exists: acquire the shared exact-scope lock, persist, release.
     * C. A previously-issued proof is now invalid/stale: fail closed without re-acquiring and
     *    without persisting an already-fetched response.
     *
     * @param int $storeId
     * @param string $unicid
     * @param array<string, mixed> $shopData
     * @param string|null $ownerToken Exact acquisition token issued by acquire() when the caller
     *                               already owns the exact-scope lock (refresh owner). Required
     *                               for that path; ignored when this call acquires the lock.
     * @return void
     */
    public function replaceValidatedSnapshot($storeId, $unicid, array $shopData, $ownerToken = null)
    {
        $storeId = $this->requireStoreId($storeId);
        $unicid = trim((string) $unicid);
        if ($unicid === '' || $shopData === array()) {
            throw new MtUniCreditPersistenceValidationException('Shop snapshot requires UNICID and non-empty data.');
        }

        $partition = $this->prepareReplacement($storeId, $unicid, $shopData);

        $ownership = $this->scopeLock->ownershipState($storeId, $unicid);
        if ($ownership === MtUniCreditShopConfigurationRefreshLock::OWNERSHIP_STALE) {
            throw new MtUniCreditPersistenceValidationException(
                'Shop snapshot replacement requires current exact-scope ownership.'
            );
        }

        if ($ownership === MtUniCreditShopConfigurationRefreshLock::OWNERSHIP_VALID) {
            // Refresh owner: already holding the shared scope lock on this connection.
            // The exact acquisition token must authorize the write; a missing/stale token fails
            // closed and never re-acquires for an already-fetched response.
            if (!$this->scopeLock->verifyWriteOwnership($storeId, $unicid, (string) $ownerToken)) {
                throw new MtUniCreditPersistenceValidationException(
                    'Shop snapshot replacement requires the exact-scope acquisition token.'
                );
            }

            $this->writeReplacement($storeId, $unicid, $partition);

            return;
        }

        $token = $this->scopeLock->acquire($storeId, $unicid);
        if ($token === null) {
            throw new MtUniCreditPersistenceException('Shop cache replacement is busy.');
        }

        $persistedOk = false;
        try {
            // Ownership must still be provable on the live connection immediately before the
            // write: a reconnect/lost advisory lock between acquire() and the write fails closed.
            if (!$this->scopeLock->verifyWriteOwnership($storeId, $unicid, $token)) {
                throw new MtUniCreditPersistenceException(
                    'Shop cache replacement lost exact-scope lock ownership before write.'
                );
            }

            $this->writeReplacement($storeId, $unicid, $partition);
            $persistedOk = true;
        } finally {
            $release = $this->scopeLock->release($storeId, $unicid, $token);
            if (!is_array($release) || empty($release['ok'])) {
                $outcome = is_array($release) && isset($release['outcome'])
                    ? (string) $release['outcome']
                    : MtUniCreditShopConfigurationRefreshLock::RELEASE_OUTCOME_MISSING_OR_ERROR;
                $this->recordLockReleaseAnomaly($storeId, $outcome, $persistedOk);
            }
        }
    }

    /**
     * @param int $storeId
     * @return int
     */
    private function requireStoreId($storeId)
    {
        MtUniCreditStoreScope::requireStoreId($storeId);

        return (int) $storeId;
    }

    /**
     * @param int $storeId
     * @param string $unicid
     * @param array<string, mixed> $shopData
     * @return array<string, mixed>
     */
    private function prepareReplacement($storeId, $unicid, array $shopData)
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

        return $partition;
    }

    /**
     * Failure-atomic cache + SmartUCF credential write (no lock handling).
     *
     * @param int $storeId
     * @param string $unicid
     * @param array<string, mixed> $partition
     * @return void
     */
    private function writeReplacement($storeId, $unicid, array $partition)
    {
        $credentialMutation = $partition['pair_state'] === 'complete';
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
