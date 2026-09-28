<?php

/**
 * Thrown by the shared resolver when a strict (submission) or manual resolution cannot
 * produce a valid snapshot. Callers fail closed — LKG is never returned through this type.
 */
final class MtUniCreditShopConfigurationUnavailableException extends RuntimeException
{
    const REASON_NOT_CONFIGURED = 'not_configured';

    const REASON_CONTENDED = 'contended';

    const REASON_TRANSIENT = 'transient';

    const REASON_AUTHORITATIVE = 'authoritative';

    const REASON_CONTRACT_INVALID = 'contract_invalid';

    /** @var string */
    private $reason;

    /** @var string|null */
    private $failureClass;

    /**
     * @param string $reason
     * @param string|null $failureClass
     * @param string $message
     * @param Throwable|null $previous
     */
    public function __construct($reason, $failureClass = null, $message = '', $previous = null)
    {
        $this->reason = (string) $reason;
        $this->failureClass = $failureClass !== null ? (string) $failureClass : null;
        parent::__construct(
            $message !== '' ? (string) $message : 'Shop configuration is not available for this request.',
            0,
            $previous
        );
    }

    /**
     * @return string
     */
    public function reason()
    {
        return $this->reason;
    }

    /**
     * @return string|null
     */
    public function failureClass()
    {
        return $this->failureClass;
    }
}

/**
 * Shared shop configuration resolver.
 *
 * Frozen lifecycle contract (REM-OC3-CACHE-001):
 *
 * - TTL 86400s from the validated replacement.
 * - fresh:          now < expires_at                          (local-only, no CP GET)
 * - stale / LKG:    expires_at <= now <= expires_at + 21600   (PRESENTATION only, and only when
 *                                                              the current refresh attempt failed TRANSIENTLY)
 * - purge-eligible: now > expires_at + 21600
 *
 * PRESENTATION (getForPresentation) never throws: it returns the validated snapshot, an eligible
 * last-known-good snapshot, or null. SUBMISSION (getForSubmission) never returns LKG: it requires a
 * valid fresh snapshot or a successful current-request coordinated refresh, otherwise it throws and
 * the caller fails closed.
 *
 * LKG is returned ONLY after THIS request owns the exact-scope refresh, performs the remote
 * refresh, and receives an explicit TRANSIENT (Class A) failure. No other condition authorizes it:
 * a contender never serves LKG, never infers Class A and never consumes the owner's failure class,
 * and a missing/unreadable local credential never serves LKG or purges known-good state.
 *
 * One owner protocol per exact (store_id, UNICID) scope: MySQL/MariaDB advisory lock
 * MtUniCreditShopConfigurationRefreshLock. Contenders never call the CP.
 *
 * @see docs/CONTRACTS.md CACHE-003
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

    /** @var MtUniCreditShopCachePersistence|null */
    private $shopCachePersistence;

    /** @var int */
    private $storeId;

    /** @var MtUniCreditShopConfigurationFailureClassifier */
    private $failureClassifier;

    /** @var MtUniCreditShopConfigurationRefreshLock */
    private $refreshLock;

    /** @var callable|null Receives an integer microsecond duration (bounded contender wait). */
    private $sleeper;

    /** @var callable|null Receives one sanitized lifecycle diagnostic line. */
    private $diagnosticsLogger;

    /**
     * @param MtUniCreditCredentialsRepository $credentials
     * @param MtUniCreditShopCacheRepository $cache
     * @param MtUniCreditControlPanelClient $client
     * @param MtUniCreditCpTokenRepository $tokens
     * @param int $storeId
     * @param MtUniCreditShopConfigurationSnapshotValidator|null $snapshotValidator
     * @param MtUniCreditShopCachePersistence|null $shopCachePersistence
     * @param MtUniCreditShopConfigurationFailureClassifier|null $failureClassifier
     * @param MtUniCreditShopConfigurationRefreshLock|null $refreshLock
     * @param callable|null $sleeper Bounded-wait sleeper (test seam); default usleep.
     * @param callable|null $diagnosticsLogger Sanitized lifecycle diagnostics sink; default error_log.
     */
    public function __construct(
        MtUniCreditCredentialsRepository $credentials,
        MtUniCreditShopCacheRepository $cache,
        MtUniCreditControlPanelClient $client,
        MtUniCreditCpTokenRepository $tokens,
        $storeId,
        $snapshotValidator = null,
        $shopCachePersistence = null,
        $failureClassifier = null,
        $refreshLock = null,
        $sleeper = null,
        $diagnosticsLogger = null
    ) {
        $this->credentials = $credentials;
        $this->cache = $cache;
        $this->client = $client;
        $this->tokens = $tokens;
        $this->storeId = (int) $storeId;
        $this->snapshotValidator = $snapshotValidator instanceof MtUniCreditShopConfigurationSnapshotValidator
            ? $snapshotValidator
            : new MtUniCreditShopConfigurationSnapshotValidator();
        $this->shopCachePersistence = $shopCachePersistence instanceof MtUniCreditShopCachePersistence
            ? $shopCachePersistence
            : null;
        $this->failureClassifier = $failureClassifier instanceof MtUniCreditShopConfigurationFailureClassifier
            ? $failureClassifier
            : new MtUniCreditShopConfigurationFailureClassifier();
        $this->refreshLock = $refreshLock instanceof MtUniCreditShopConfigurationRefreshLock
            ? $refreshLock
            : null;
        $this->sleeper = is_callable($sleeper) ? $sleeper : null;
        $this->diagnosticsLogger = is_callable($diagnosticsLogger) ? $diagnosticsLogger : null;
    }

    /**
     * PRESENTATION resolution — never throws.
     *
     * @param callable|null $localGate Local module/surface gate applied BEFORE any network activity.
     * @return array<string, mixed>|null
     */
    public function getForPresentation($localGate = null)
    {
        try {
            if (is_callable($localGate) && !call_user_func($localGate)) {
                $this->diagnose('presentation_gate_blocked', array('state' => 'gate_blocked'));

                return null;
            }

            $unicid = $this->credentials->getUnicid($this->storeId);
            if ($unicid === '') {
                return null;
            }

            // Fresh path is local-only: no CP GET while expires_at is in the future.
            $inspection = $this->cache->inspectScope($this->storeId, $unicid);
            if ($inspection['state'] === MtUniCreditShopCacheRepository::STATE_FRESH) {
                return $inspection['shop_data'];
            }

            // Local credential gate: never attempt a remote refresh (and never purge) when the
            // local credentials are incomplete/unreadable. Presentation stays fail-soft and LKG
            // is NOT authorized — only this request's own refresh failure may authorize LKG.
            if (!$this->localRefreshGatePasses()) {
                $this->diagnose('presentation_gate_blocked', array(
                    'state' => $inspection['state'],
                    'reason' => 'credentials_unavailable',
                    'lkg' => 'no',
                ), $unicid);

                return null;
            }

            $token = $this->acquireRefreshOwnership($unicid);
            if ($token === null) {
                // Contender: another request owns the exact scope. Never call CP, never infer
                // Class A, never consume the owner's failure class, never serve LKG. Only a
                // bounded re-read of a freshly published snapshot may succeed here.
                $fresh = $this->waitForFreshSnapshot($unicid);
                if ($fresh !== null) {
                    return $fresh;
                }
                $this->diagnose('presentation_contention_failed_closed', array(
                    'state' => $inspection['state'],
                    'lock' => 'contender',
                    'lkg' => 'no',
                ), $unicid);

                return null;
            }

            try {
                $recheck = $this->cache->inspectScope($this->storeId, $unicid);
                if ($recheck['state'] === MtUniCreditShopCacheRepository::STATE_FRESH) {
                    return $recheck['shop_data'];
                }

                // Eligible LKG is computed for the owner path only. It may be returned ONLY
                // after THIS request performs the remote refresh and fails TRANSIENTLY.
                $lkg = $this->eligibleLkgCandidate($unicid, $recheck);

                try {
                    return $this->performRemoteRefresh($unicid, $token);
                } catch (Throwable $exception) {
                    return $this->presentationAfterRefreshFailure($unicid, $exception, $lkg);
                }
            } finally {
                $this->releaseRefreshOwnership($unicid, $token);
            }
        } catch (Throwable $exception) {
            $this->diagnose('presentation_unexpected_failure', array(
                'state' => 'unexpected',
                'refresh_class' => $this->failureClassifier->classify($exception),
            ));

            return null;
        }
    }

    /**
     * SUBMISSION resolution — never returns LKG. Throws on any failure so callers fail closed.
     *
     * @return array<string, mixed>
     * @throws MtUniCreditShopConfigurationUnavailableException
     */
    public function getForSubmission()
    {
        $unicid = $this->credentials->getUnicid($this->storeId);
        if ($unicid === '') {
            throw new MtUniCreditShopConfigurationUnavailableException(
                MtUniCreditShopConfigurationUnavailableException::REASON_NOT_CONFIGURED
            );
        }

        $inspection = $this->cache->inspectScope($this->storeId, $unicid);
        if ($inspection['state'] === MtUniCreditShopCacheRepository::STATE_FRESH) {
            return $inspection['shop_data'];
        }

        if (!$this->localRefreshGatePasses()) {
            throw new MtUniCreditShopConfigurationUnavailableException(
                MtUniCreditShopConfigurationUnavailableException::REASON_NOT_CONFIGURED
            );
        }

        $token = $this->acquireRefreshOwnership($unicid);
        if ($token === null) {
            // Contender: wait for the owner, re-read, then fail closed. Never LKG.
            $fresh = $this->waitForFreshSnapshot($unicid);
            if ($fresh !== null) {
                return $fresh;
            }
            $this->diagnose('submission_contention_failed_closed', array(
                'state' => $inspection['state'],
                'lock' => 'contender',
                'lkg' => 'no',
            ), $unicid);

            throw new MtUniCreditShopConfigurationUnavailableException(
                MtUniCreditShopConfigurationUnavailableException::REASON_CONTENDED
            );
        }

        try {
            $recheck = $this->cache->inspectScope($this->storeId, $unicid);
            if ($recheck['state'] === MtUniCreditShopCacheRepository::STATE_FRESH) {
                return $recheck['shop_data'];
            }

            try {
                return $this->performRemoteRefresh($unicid, $token);
            } catch (Throwable $exception) {
                $description = $this->failureClassifier->describe($exception);
                if ($description['class'] === MtUniCreditShopConfigurationFailureClassifier::AUTHORITATIVE_SECURITY) {
                    $this->purgeScoped($unicid, $description);
                }
                $this->diagnose('submission_refresh_failed', array(
                    'state' => $inspection['state'],
                    'refresh_class' => $description['class'],
                    'reason' => $description['reason'],
                    'lock' => 'owner',
                    'lkg' => 'no',
                ), $unicid);

                throw $this->unavailableFromFailure($description, $exception);
            }
        } finally {
            $this->releaseRefreshOwnership($unicid, $token);
        }
    }

    /**
     * Manual / operator refresh: one live refresh, validate, replace, reset TTL.
     *
     * Class A preserves state and reports a technical failure; Class B purges the exact scoped
     * state; Class C preserves the row/token and reports an invalid response. LKG is never
     * returned as success.
     *
     * @return array<string, mixed>
     */
    public function refreshRemote()
    {
        $unicid = $this->credentials->getUnicid($this->storeId);
        if ($unicid === '') {
            throw new MtUniCreditCpAuthenticationException('UNICID is required to refresh the shop configuration.');
        }

        $token = $this->acquireRefreshOwnership($unicid);
        if ($token === null) {
            throw new MtUniCreditCpConnectionException(
                'A shop configuration refresh is already in progress for this store.'
            );
        }

        try {
            return $this->performRemoteRefresh($unicid, $token);
        } catch (Throwable $exception) {
            $description = $this->failureClassifier->describe($exception);
            if ($description['class'] === MtUniCreditShopConfigurationFailureClassifier::AUTHORITATIVE_SECURITY) {
                $this->purgeScoped($unicid, $description);
            }
            $this->diagnose('manual_refresh_failed', array(
                'refresh_class' => $description['class'],
                'reason' => $description['reason'],
                'lock' => 'owner',
            ), $unicid);

            throw $exception;
        } finally {
            $this->releaseRefreshOwnership($unicid, $token);
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
     * CP push path: validate and replace local shop cache (no remote GET).
     *
     * Push does not join the storefront contender wait protocol, but it serializes through the
     * SAME exact-scope lock identity as the pull refresh. There is no unlocked fallback: when the
     * shared validated persistence is not configured the call fails closed.
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

        if ($this->shopCachePersistence !== null) {
            $this->shopCachePersistence->replaceValidatedSnapshot($this->storeId, $unicid, $shopData);

            return true;
        }

        // Fail closed: never write the shop snapshot through an unlocked path.
        throw new MtUniCreditPersistenceException(
            'Shop snapshot replacement requires the shared validated persistence path.'
        );
    }

    /**
     * Presentation failure handling after an owner refresh attempt.
     *
     * @param string $unicid
     * @param Throwable $exception
     * @param array<string, mixed>|null $lkg Pre-existing LKG candidate.
     * @return array<string, mixed>|null
     */
    private function presentationAfterRefreshFailure($unicid, $exception, $lkg)
    {
        $description = $this->failureClassifier->describe($exception);
        $class = $description['class'];

        if ($class === MtUniCreditShopConfigurationFailureClassifier::AUTHORITATIVE_SECURITY) {
            $this->purgeScoped($unicid, $description);
            $this->diagnose('presentation_refresh_failed', array(
                'refresh_class' => $class,
                'reason' => $description['reason'],
                'lock' => 'owner',
                'lkg' => 'no',
                'state' => 'purged',
            ), $unicid);

            return null;
        }

        if ($class === MtUniCreditShopConfigurationFailureClassifier::TRANSIENT) {
            // Class A: preserve known-good state; presentation may use eligible LKG.
            $this->diagnose('presentation_refresh_failed', array(
                'refresh_class' => $class,
                'reason' => $description['reason'],
                'lock' => 'owner',
                'lkg' => $lkg !== null ? 'yes' : 'no',
                'state' => 'preserved',
            ), $unicid);

            return $lkg;
        }

        // Class C: current attempt fails closed, NO same-attempt LKG, no purge, no mutation.
        $this->diagnose('presentation_refresh_failed', array(
            'refresh_class' => $class,
            'reason' => $description['reason'],
            'lock' => 'owner',
            'lkg' => 'no',
            'state' => 'preserved',
        ), $unicid);

        return null;
    }

    /**
     * @param string $unicid
     * @param string $ownershipToken Exact-scope acquisition token held by THIS request.
     * @return array<string, mixed>
     */
    private function performRemoteRefresh($unicid, $ownershipToken)
    {
        $response = $this->client->getShop();
        $shopData = isset($response['data']) ? $response['data'] : null;
        if (!is_array($shopData) || $shopData === array()) {
            throw new MtUniCreditCpInvalidPayloadException(
                'The Control Panel returned no usable shop configuration.'
            );
        }

        $this->snapshotValidator->validate($shopData, $unicid);
        $this->persistRefreshedSnapshot($unicid, $shopData, $ownershipToken);

        return $shopData;
    }

    /**
     * Replace the exact-scope snapshot through the single validated replacement API.
     *
     * This request holds the exact-scope refresh lock, so the shared persistence re-verifies the
     * exact acquisition token + exact lock name + live connection ownership and persists without
     * re-acquiring the named lock. There is NO direct cache fallback: when the shared validated
     * persistence is not configured the call fails closed instead of writing outside the one
     * hardened replacement path.
     *
     * @param string $unicid
     * @param array<string, mixed> $shopData
     * @param string $ownershipToken Exact-scope acquisition token held by THIS request.
     * @return void
     */
    private function persistRefreshedSnapshot($unicid, array $shopData, $ownershipToken)
    {
        if ($this->shopCachePersistence === null) {
            // Fail closed: never persist a validated snapshot outside the shared hardened path.
            throw new MtUniCreditPersistenceException(
                'Shop snapshot replacement requires the shared validated persistence path.'
            );
        }

        $this->shopCachePersistence->replaceValidatedSnapshot(
            $this->storeId,
            $unicid,
            $shopData,
            $ownershipToken
        );
    }

    /**
     * Validated, exact-scope, structural LKG candidate (6h window enforced by the repository).
     *
     * @param string $unicid
     * @param array<string, mixed> $inspection
     * @return array<string, mixed>|null
     */
    private function eligibleLkgCandidate($unicid, array $inspection)
    {
        if (empty($inspection['lkg_eligible'])) {
            return null;
        }

        $shopData = isset($inspection['shop_data']) ? $inspection['shop_data'] : null;
        if (!is_array($shopData) || $shopData === array()) {
            return null;
        }

        try {
            // Persisted artifact: credential pair lives in encrypted settings (CACHE-001a).
            $this->snapshotValidator->validateStoredSnapshot($shopData, $unicid);
        } catch (Throwable $exception) {
            return null;
        }

        return $shopData;
    }

    /**
     * Local refresh gate — incomplete/unreadable local credentials never trigger a remote
     * refresh attempt and never purge known-good state.
     *
     * @return bool
     */
    private function localRefreshGatePasses()
    {
        try {
            return $this->credentials->hasCompleteCredentials($this->storeId)
                && $this->credentials->isSecretReadable($this->storeId);
        } catch (Throwable $exception) {
            return false;
        }
    }

    /**
     * Enter the single-flight owner protocol for the exact scope.
     *
     * @param string $unicid
     * @return string|null Ownership token, or null when another connection owns the scope.
     */
    private function acquireRefreshOwnership($unicid)
    {
        if ($this->refreshLock === null) {
            // Fail closed: no single-flight owner protocol configured means no remote refresh.
            throw new MtUniCreditPersistenceException(
                'Shop configuration refresh lock is not configured for this store.'
            );
        }

        return $this->refreshLock->acquire($this->storeId, $unicid);
    }

    /**
     * @param string $unicid
     * @param string $token
     * @return void
     */
    private function releaseRefreshOwnership($unicid, $token)
    {
        if ($this->refreshLock === null) {
            return;
        }

        $release = $this->refreshLock->release($this->storeId, $unicid, $token);
        if (is_array($release) && !empty($release['ok'])) {
            return;
        }

        $outcome = is_array($release) && isset($release['outcome'])
            ? (string) $release['outcome']
            : MtUniCreditShopConfigurationRefreshLock::RELEASE_OUTCOME_MISSING_OR_ERROR;
        $this->diagnose('refresh_lock_release_anomaly', array('reason' => $outcome), $unicid);
    }

    /**
     * Bounded contender wait then exact-scope re-read (fail closed when still unavailable).
     *
     * @param string $unicid
     * @return array<string, mixed>|null
     */
    private function waitForFreshSnapshot($unicid)
    {
        $budgetMs = (int) MtUniCreditSecurityConstants::SHOP_CONFIGURATION_CONTENDER_WAIT_MILLISECONDS;
        $pollMs = max(1, (int) MtUniCreditSecurityConstants::SHOP_CONFIGURATION_CONTENDER_POLL_MILLISECONDS);
        $elapsedMs = 0;

        while (true) {
            $inspection = $this->cache->inspectScope($this->storeId, $unicid);
            if ($inspection['state'] === MtUniCreditShopCacheRepository::STATE_FRESH) {
                return $inspection['shop_data'];
            }

            if ($elapsedMs >= $budgetMs) {
                return null;
            }

            $sleepMs = min($pollMs, $budgetMs - $elapsedMs);
            $this->sleepMilliseconds($sleepMs);
            $elapsedMs += $sleepMs;
        }
    }

    /**
     * @param int $milliseconds
     * @return void
     */
    private function sleepMilliseconds($milliseconds)
    {
        $microseconds = max(1, (int) $milliseconds) * 1000;
        if ($this->sleeper !== null) {
            call_user_func($this->sleeper, $microseconds);

            return;
        }

        usleep($microseconds);
    }

    /**
     * Class B only: purge exact scoped cache + token so later transient failures cannot resurrect
     * the old authoritative state.
     *
     * @param string $unicid
     * @param array<string, mixed> $description
     * @return void
     */
    private function purgeScoped($unicid, array $description)
    {
        if ($unicid !== '') {
            $this->cache->deleteScoped($this->storeId, $unicid);
        }
        $this->tokens->invalidate();

        $this->diagnose('scoped_purge', array(
            'refresh_class' => MtUniCreditShopConfigurationFailureClassifier::AUTHORITATIVE_SECURITY,
            'reason' => isset($description['reason']) ? (string) $description['reason'] : 'authoritative',
            'state' => 'purged',
        ), $unicid);
    }

    /**
     * @param array<string, mixed> $description
     * @param Throwable $exception
     * @return MtUniCreditShopConfigurationUnavailableException
     */
    private function unavailableFromFailure(array $description, $exception)
    {
        $class = isset($description['class']) ? (string) $description['class'] : '';
        $reason = MtUniCreditShopConfigurationUnavailableException::REASON_TRANSIENT;
        if ($class === MtUniCreditShopConfigurationFailureClassifier::AUTHORITATIVE_SECURITY) {
            $reason = MtUniCreditShopConfigurationUnavailableException::REASON_AUTHORITATIVE;
        } elseif ($class === MtUniCreditShopConfigurationFailureClassifier::CONTRACT_INVALID) {
            $reason = MtUniCreditShopConfigurationUnavailableException::REASON_CONTRACT_INVALID;
        }

        return new MtUniCreditShopConfigurationUnavailableException($reason, $class, '', $exception);
    }

    /**
     * Short non-reversible scope fingerprint for diagnostics (no raw UNICID).
     *
     * @param string $unicid
     * @return string
     */
    private function scopeFingerprint($unicid)
    {
        if ($this->refreshLock !== null) {
            return $this->refreshLock->scopeFingerprint($this->storeId, $unicid);
        }

        return substr(hash('sha256', (string) $this->storeId . '|' . trim((string) $unicid)), 0, 12);
    }

    /**
     * Minimal lifecycle diagnostics — no token, secret, credentials, payload or raw UNICID.
     *
     * @param string $event
     * @param array<string, mixed> $fields
     * @param string|null $unicid Scope identity — never logged raw, only as a short fingerprint.
     * @return void
     */
    private function diagnose($event, array $fields = array(), $unicid = null)
    {
        $exceptional = array(
            'presentation_refresh_failed',
            'presentation_contention_failed_closed',
            'submission_contention_failed_closed',
            'submission_refresh_failed',
            'manual_refresh_failed',
            'scoped_purge',
            'refresh_lock_release_anomaly',
            'presentation_unexpected_failure',
        );
        if (!in_array($event, $exceptional, true)) {
            return;
        }

        $parts = array('mt_uni_credit: shop_configuration', 'event=' . (string) $event);
        if ($unicid !== null && trim((string) $unicid) !== '') {
            $parts[] = 'store_id=' . (int) $this->storeId;
            $parts[] = 'scope=' . $this->scopeFingerprint((string) $unicid);
        }
        foreach ($fields as $key => $value) {
            $key = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $key));
            if (!is_string($key) || $key === '') {
                continue;
            }
            if (is_bool($value)) {
                $value = $value ? 'yes' : 'no';
            }
            if (!is_scalar($value)) {
                continue;
            }
            // Only lifecycle constants reach this point; strip line breaks to keep one line
            // per event (no log injection) without silently mangling values.
            $safeValue = str_replace(array("\r", "\n", "\t"), '', (string) $value);
            $parts[] = $key . '=' . $safeValue;
        }

        $message = implode(' ', $parts);

        try {
            if ($this->diagnosticsLogger !== null) {
                call_user_func($this->diagnosticsLogger, $message);

                return;
            }
            error_log($message);
        } catch (Throwable $exception) {
            // Observability must never convert a resolution outcome into a failure.
            unset($exception);
        }
    }
}
