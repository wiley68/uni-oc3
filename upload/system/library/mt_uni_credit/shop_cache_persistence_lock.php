<?php

/**
 * Connection-scoped MySQL/MariaDB advisory lock for the exact (store_id, UNICID)
 * shop configuration scope.
 *
 * ONE lock identity serializes both the outbound `/shop` refresh and the inbound
 * `shop_cache` push / manual persistence for that scope:
 *
 *   mtuc_sr_ + substr(sha256(store_id + '|' + UNICID), 0, 56)
 *
 * The raw UNICID never appears in the lock name or the DB process list.
 *
 * Ownership proof is bound to THIS lock instance and to the DB connection that issued
 * GET_LOCK. A successful acquire() records:
 *
 * - the exact scope / lock name
 * - an opaque ownership token
 * - the connection id captured from the same adapter connection
 *
 * Process-global static state is NOT authoritative. Immediately before an owner-proven
 * write the ownership is re-verified through the CURRENT connection:
 *
 *   SELECT CONNECTION_ID() AS current_connection_id,
 *          IS_USED_LOCK(<exact lock name>) AS owner_connection_id
 *
 * Ownership is valid only when all hold:
 *
 * - the presented instance token matches the stored token for the exact lock name
 * - current_connection_id equals the connection id IS_USED_LOCK reports as the owner
 * - current_connection_id equals the connection id captured at acquire time
 *
 * IS_FREE_LOCK() is never used as ownership proof, and GET_LOCK() is never re-issued
 * merely to verify ownership.
 *
 * Every protected persistence write is authorized through verifyWriteOwnership(): the writer
 * must present the exact acquisition token issued by acquire() for the exact lock name, and the
 * live connection proof above must still hold on the current connection immediately before the
 * write. A missing/mismatched token, a replaced connection or a lost advisory lock fails closed.
 *
 * @see docs/CONTRACTS.md CACHE-003
 */
final class MtUniCreditShopConfigurationRefreshLock
{
    const LOCK_NAME_PREFIX = 'mtuc_sr_';

    /** Hex chars after prefix; prefix(8)+56 = 64 (MySQL GET_LOCK name limit). */
    const LOCK_NAME_HASH_HEX_LENGTH = 56;

    /**
     * Bounded GET_LOCK wait (seconds). Keep in sync with
     * MtUniCreditSecurityConstants::SHOP_CONFIGURATION_REFRESH_LOCK_TIMEOUT_SECONDS.
     */
    const ACQUIRE_TIMEOUT_SECONDS = 5;

    const RELEASE_OUTCOME_RELEASED = 'released';

    const RELEASE_OUTCOME_NOT_OWNER_TOKEN = 'not_owner_token';

    const RELEASE_OUTCOME_NOT_OWNED = 'not_owned';

    const RELEASE_OUTCOME_MISSING_OR_ERROR = 'missing_or_error';

    const RELEASE_OUTCOME_QUERY_EXCEPTION = 'query_exception';

    /** This instance never acquired the exact scope. */
    const OWNERSHIP_NONE = 'none';

    /** This instance holds a connection-verified, still-valid proof. */
    const OWNERSHIP_VALID = 'valid';

    /** A proof existed but is now stale/invalid (connection changed, lock lost, error). */
    const OWNERSHIP_STALE = 'stale';

    /** @var MtUniCreditDbAdapter */
    private $db;

    /**
     * Instance-bound ownership proofs: exact lock name => {token, connection_id}.
     *
     * Never static: a stale proof from an earlier request must not authorize a later write.
     *
     * @var array<string, array{token: string, connection_id: int}>
     */
    private $ownedScopes = array();

    /**
     * @param MtUniCreditDbAdapter $db Same adapter/connection used for credential+cache writes.
     */
    public function __construct(MtUniCreditDbAdapter $db)
    {
        $this->db = $db;
    }

    /**
     * Deterministic lock identity — hashes store_id|UNICID (no raw UNICID in the name).
     *
     * @param int $storeId
     * @param string $unicid
     * @return string
     */
    public function lockName($storeId, $unicid)
    {
        MtUniCreditStoreScope::requireStoreId($storeId);
        $unicid = trim((string) $unicid);
        if ($unicid === '') {
            throw new MtUniCreditPersistenceValidationException(
                'Shop cache scope lock requires UNICID.'
            );
        }

        $material = (string) ((int) $storeId) . '|' . $unicid;
        $hash = substr(hash('sha256', $material), 0, self::LOCK_NAME_HASH_HEX_LENGTH);

        return self::LOCK_NAME_PREFIX . $hash;
    }

    /**
     * Diagnostics-safe scope fingerprint (no raw UNICID / no raw scope values).
     *
     * @param int $storeId
     * @param string $unicid
     * @return string
     */
    public function scopeFingerprint($storeId, $unicid)
    {
        $material = (string) ((int) $storeId) . '|' . trim((string) $unicid);

        return substr(hash('sha256', $material), 0, 12);
    }

    /**
     * Acquire single-flight ownership for the exact scope.
     *
     * @param int $storeId
     * @param string $unicid
     * @return string|null Opaque ownership token, or null when another connection owns the scope
     */
    public function acquire($storeId, $unicid)
    {
        $name = $this->lockName($storeId, $unicid);
        $timeout = (int) self::ACQUIRE_TIMEOUT_SECONDS;
        $sql = "SELECT GET_LOCK('" . $this->db->escape($name) . "', " . $timeout . ") AS `mtuc_lock`";
        $value = $this->scalarValue($this->db->query($sql), 'mtuc_lock');

        if ($value === 1) {
            $connectionId = $this->currentConnectionId();
            if ($connectionId === null) {
                // Cannot bind a trustworthy proof to this connection: release and fail closed.
                $this->releaseLockByName($name);
                throw new MtUniCreditPersistenceException(
                    'Shop cache scope lock could not be bound to the current DB connection.'
                );
            }

            $token = MtUniCreditLockOwnerTokenGenerator::generate();
            $this->ownedScopes[$name] = array(
                'token' => $token,
                'connection_id' => $connectionId,
            );

            return $token;
        }

        if ($value === 0) {
            return null;
        }

        throw new MtUniCreditPersistenceException('Shop configuration refresh lock subsystem failed.');
    }

    /**
     * Classify this instance's ownership of the exact scope.
     *
     * A previously-issued but no-longer-valid proof is reported as OWNERSHIP_STALE and is
     * dropped immediately — it must never authorize an owner-proven write.
     *
     * @param int $storeId
     * @param string $unicid
     * @return string One of OWNERSHIP_NONE | OWNERSHIP_VALID | OWNERSHIP_STALE
     */
    public function ownershipState($storeId, $unicid)
    {
        $name = $this->lockName($storeId, $unicid);
        if (!isset($this->ownedScopes[$name])) {
            return self::OWNERSHIP_NONE;
        }

        if ($this->verifyOwnership($name, $this->ownedScopes[$name])) {
            return self::OWNERSHIP_VALID;
        }

        // Stale/uncertain proof: drop it so it can never be reused for a write.
        unset($this->ownedScopes[$name]);

        return self::OWNERSHIP_STALE;
    }

    /**
     * Hardened write authorization for ONE protected persistence write.
     *
     * Evaluated on the CURRENT connection immediately before the write, all of the following
     * must hold:
     *
     * - the lock name is derived from the exact (store_id, UNICID) scope of the write;
     * - the presented acquisition token equals the token stored for that exact lock name;
     * - the captured owner CONNECTION_ID() equals the live CONNECTION_ID();
     * - IS_USED_LOCK(<exact lock name>) equals that same connection id.
     *
     * Required equality:
     *
     *   current_connection_id = captured_owner_connection_id = IS_USED_LOCK(exact_lock_name)
     *   AND presented_token = stored_token
     *
     * Any failure fails closed: no write is authorized, GET_LOCK() is never re-issued here and
     * IS_FREE_LOCK() is never consulted. When the live connection proof fails the local proof is
     * dropped so it can never be reused; a mere token mismatch keeps the proof so the genuine
     * owner can still release the exact lock.
     *
     * @param int $storeId
     * @param string $unicid
     * @param string $token Exact acquisition token presented by the writer.
     * @return bool True only when the exact-scope ownership proof is valid for this writer.
     */
    public function verifyWriteOwnership($storeId, $unicid, $token)
    {
        $name = $this->lockName($storeId, $unicid);
        if (!isset($this->ownedScopes[$name])) {
            return false;
        }

        $proof = $this->ownedScopes[$name];
        $storedToken = isset($proof['token']) ? (string) $proof['token'] : '';
        $presentedToken = (string) $token;
        if ($presentedToken === '' || $storedToken === '' || !hash_equals($storedToken, $presentedToken)) {
            return false;
        }

        if (!$this->verifyOwnership($name, $proof)) {
            unset($this->ownedScopes[$name]);

            return false;
        }

        return true;
    }

    /**
     * Release single-flight ownership. Never throws.
     *
     * Only a caller presenting the exact token issued by acquire() on this instance can release.
     * The local proof is always cleared on success, mismatch, connection change, SQL exception
     * or any ambiguous outcome — an uncertain release never leaves a usable proof behind.
     *
     * @param int $storeId
     * @param string $unicid
     * @param string $token
     * @return array{ok: bool, outcome: string, value: int|null}
     */
    public function release($storeId, $unicid, $token)
    {
        try {
            $name = $this->lockName($storeId, $unicid);
        } catch (Exception $exception) {
            unset($exception);

            return array(
                'ok' => false,
                'outcome' => self::RELEASE_OUTCOME_QUERY_EXCEPTION,
                'value' => null,
            );
        }

        $token = (string) $token;
        $storedToken = isset($this->ownedScopes[$name]['token'])
            ? (string) $this->ownedScopes[$name]['token']
            : '';

        if ($token === '' || $storedToken === '' || !hash_equals($storedToken, $token)) {
            return array(
                'ok' => false,
                'outcome' => self::RELEASE_OUTCOME_NOT_OWNER_TOKEN,
                'value' => null,
            );
        }

        if (!$this->verifyOwnership($name, $this->ownedScopes[$name])) {
            unset($this->ownedScopes[$name]);

            return array(
                'ok' => false,
                'outcome' => self::RELEASE_OUTCOME_NOT_OWNED,
                'value' => 0,
            );
        }

        try {
            $value = $this->releaseLockByName($name);
        } catch (Exception $exception) {
            unset($exception);
            unset($this->ownedScopes[$name]);

            return array(
                'ok' => false,
                'outcome' => self::RELEASE_OUTCOME_QUERY_EXCEPTION,
                'value' => null,
            );
        }

        unset($this->ownedScopes[$name]);

        if ($value === 1) {
            return array(
                'ok' => true,
                'outcome' => self::RELEASE_OUTCOME_RELEASED,
                'value' => 1,
            );
        }

        if ($value === 0) {
            return array(
                'ok' => false,
                'outcome' => self::RELEASE_OUTCOME_NOT_OWNED,
                'value' => 0,
            );
        }

        return array(
            'ok' => false,
            'outcome' => self::RELEASE_OUTCOME_MISSING_OR_ERROR,
            'value' => null,
        );
    }

    /**
     * Connection-bound ownership verification for one exact lock name.
     *
     * @param string $name
     * @param array{token: string, connection_id: int} $proof
     * @return bool
     */
    private function verifyOwnership($name, array $proof)
    {
        try {
            $result = $this->db->query(
                "SELECT CONNECTION_ID() AS `current_connection_id`,"
                    . " IS_USED_LOCK('" . $this->db->escape($name) . "') AS `owner_connection_id`"
            );
        } catch (Exception $exception) {
            unset($exception);

            return false;
        }

        $current = $this->scalarValue($result, 'current_connection_id');
        $owner = $this->scalarValue($result, 'owner_connection_id');
        $captured = isset($proof['connection_id']) ? (int) $proof['connection_id'] : 0;

        if ($current === null || $owner === null || $captured <= 0) {
            return false;
        }

        return $current === $captured && $owner === $current;
    }

    /**
     * @return int|null
     */
    private function currentConnectionId()
    {
        try {
            $result = $this->db->query('SELECT CONNECTION_ID() AS `mtuc_connection_id`');
        } catch (Exception $exception) {
            unset($exception);

            return null;
        }

        return $this->scalarValue($result, 'mtuc_connection_id');
    }

    /**
     * @param string $name
     * @return int|null
     */
    private function releaseLockByName($name)
    {
        $sql = "SELECT RELEASE_LOCK('" . $this->db->escape($name) . "') AS `mtuc_lock`";

        return $this->scalarValue($this->db->query($sql), 'mtuc_lock');
    }

    /**
     * @param mixed $result
     * @param string $key
     * @return int|null
     */
    private function scalarValue($result, $key)
    {
        if (!is_object($result) || !isset($result->row) || !is_array($result->row)) {
            return null;
        }

        if (!array_key_exists($key, $result->row)) {
            return null;
        }

        $raw = $result->row[$key];
        if ($raw === null) {
            return null;
        }

        if (is_int($raw) || is_float($raw) || (is_string($raw) && is_numeric($raw))) {
            return (int) $raw;
        }

        return null;
    }
}
