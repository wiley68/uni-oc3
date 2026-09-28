<?php

/**
 * Single classification authority for shop configuration refresh failures.
 *
 * Frozen taxonomy (REM-OC3-CACHE-001):
 *
 * - TRANSIENT (Class A)              connection / timeout / transport / 408 / 429 / 5xx
 *                                    → preserve known-good state, presentation may use eligible LKG,
 *                                      submission fails closed, NO purge, NO security fence.
 * - AUTHORITATIVE_SECURITY (Class B) 401 / explicit auth failure / explicit revocation / proven wrong
 *                                    identity / canonical authoritative forbidden-deleted-gone state
 *                                    → fail closed, never LKG, purge exact scoped cache + token,
 *                                      later transient failure must not resurrect old state.
 * - CONTRACT_INVALID (Class C)       422 / malformed JSON / malformed canonical envelope / invalid
 *                                    schema / invalid payload / missing authoritative fields
 *                                    → current attempt fails closed, NO same-attempt LKG, rejected
 *                                      payload never replaces known-good (byte-identical row/token),
 *                                      NO purge, later independent transient request may use LKG.
 *
 * Precedence is deliberate: transport/status-level evidence first, then trusted canonical CP
 * evidence, then contract-invalid. A bare status code or a malformed/untrusted body can NEVER
 * establish Class B.
 *
 * Conservatism rule: an unknown/unclassified Throwable, and any local infrastructure or
 * configuration defect that is not PROVEN to be network/timeout/408/429/5xx, is classified
 * CONTRACT_INVALID (never TRANSIENT). Programming/runtime defects must never be silently
 * converted into an availability signal, so they preserve known-good state, authorize no LKG,
 * and fail closed.
 *
 * @see docs/CONTRACTS.md CACHE-003
 */
final class MtUniCreditShopConfigurationFailureClassifier
{
    const TRANSIENT = 'TRANSIENT';

    const AUTHORITATIVE_SECURITY = 'AUTHORITATIVE_SECURITY';

    const CONTRACT_INVALID = 'CONTRACT_INVALID';

    const REASON_CONNECTION = 'connection';

    const REASON_TIMEOUT = 'timeout';

    const REASON_TRANSIENT_HTTP = 'transient_http';

    const REASON_SERVER_ERROR = 'server_error';

    const REASON_AUTHENTICATION = 'authentication';

    const REASON_CANONICAL_AUTHORITATIVE = 'canonical_authoritative';

    const REASON_CANONICAL_TEMPORARY = 'canonical_temporary';

    const REASON_CONTRACT_INVALID = 'contract_invalid';

    const REASON_MALFORMED = 'malformed_response';

    const REASON_SCHEMA = 'schema_invalid';

    const REASON_LOCAL_INFRASTRUCTURE = 'local_infrastructure';

    const REASON_UNKNOWN = 'unknown';

    /**
     * Canonical CP error codes that PROVE authoritative/security meaning.
     *
     * Only trusted canonical envelopes carrying one of these codes may establish Class B
     * for 403 / 404 / 410 (and equivalent statuses).
     *
     * @var array<int, string>
     */
    private static $authoritativeErrorCodes = array(
        'authentication_failed',
        'invalid_credentials',
        'unauthenticated',
        'unauthorized',
        'access_denied',
        'forbidden',
        'revoked',
        'token_revoked',
        'shop_revoked',
        'shop_disabled',
        'shop_deleted',
        'shop_gone',
        'shop_not_found',
        'identity_mismatch',
        'shop_identity_mismatch',
        'account_disabled',
    );

    /**
     * Canonical CP error codes that describe a TEMPORARY condition.
     *
     * @var array<int, string>
     */
    private static $temporaryErrorCodes = array(
        'temporarily_unavailable',
        'rate_limited',
        'timeout',
        'server_error',
        'internal_error',
        'service_unavailable',
        'down',
    );

    /**
     * @param mixed $exception
     * @return string One of TRANSIENT | AUTHORITATIVE_SECURITY | CONTRACT_INVALID
     */
    public function classify($exception)
    {
        $description = $this->describe($exception);

        return $description['class'];
    }

    /**
     * Internal implementation helper: classify a static error payload (no exception object
     * available). Not part of the public classification surface: callers use classify()/describe().
     *
     * @param int|null $httpStatus
     * @param bool $canonicalFailure
     * @param string|null $canonicalError
     * @return string
     */
    private function classifyStatus($httpStatus, $canonicalFailure = false, $canonicalError = null)
    {
        $status = $httpStatus !== null ? (int) $httpStatus : 0;
        $error = $canonicalError !== null ? trim((string) $canonicalError) : '';

        if ($status === 401) {
            return self::AUTHORITATIVE_SECURITY;
        }
        if ($status >= 500 || $status === 408 || $status === 429) {
            return self::TRANSIENT;
        }
        if ($error !== '' && $this->isAuthoritativeCode($error) && (bool) $canonicalFailure) {
            return self::AUTHORITATIVE_SECURITY;
        }
        if ($error !== '' && $this->isTemporaryCode($error)) {
            return self::TRANSIENT;
        }

        return self::CONTRACT_INVALID;
    }

    /**
     * Diagnostics-safe description (never contains response bodies, tokens or identity values).
     *
     * @param mixed $exception
     * @return array{class: string, reason: string, exception_class: string, http_status: int|null}
     */
    public function describe($exception)
    {
        if (!$exception instanceof Throwable) {
            return $this->description(self::CONTRACT_INVALID, self::REASON_UNKNOWN, '', null);
        }

        $class = get_class($exception);

        if ($exception instanceof MtUniCreditCpAuthenticationException) {
            return $this->description(self::AUTHORITATIVE_SECURITY, self::REASON_AUTHENTICATION, $class, 401);
        }

        if ($exception instanceof MtUniCreditCpTimeoutException) {
            return $this->description(self::TRANSIENT, self::REASON_TIMEOUT, $class, null);
        }

        if ($exception instanceof MtUniCreditCpConnectionException) {
            return $this->description(self::TRANSIENT, self::REASON_CONNECTION, $class, null);
        }

        if ($exception instanceof MtUniCreditCpHttpException) {
            $status = (int) $exception->getStatusCode();
            $canonical = (bool) $exception->isCanonicalFailure();
            $canonicalError = $exception->getCanonicalError();
            $classified = $this->classifyStatus($status, $canonical, $canonicalError);

            $reason = self::REASON_CONTRACT_INVALID;
            if ($classified === self::TRANSIENT) {
                $reason = $status >= 500 ? self::REASON_SERVER_ERROR : self::REASON_TRANSIENT_HTTP;
            } elseif ($classified === self::AUTHORITATIVE_SECURITY) {
                $reason = $status === 401
                    ? self::REASON_AUTHENTICATION
                    : self::REASON_CANONICAL_AUTHORITATIVE;
            } elseif ($canonicalError !== null && $this->isTemporaryCode(trim((string) $canonicalError))) {
                $reason = self::REASON_CANONICAL_TEMPORARY;
            } elseif ($status === 422) {
                $reason = self::REASON_SCHEMA;
            }

            return $this->description($classified, $reason, $class, $status);
        }

        if ($exception instanceof MtUniCreditShopSnapshotValidationException) {
            return $this->description(self::CONTRACT_INVALID, self::REASON_SCHEMA, $class, null);
        }

        if ($exception instanceof MtUniCreditCpInvalidPayloadException) {
            $status = $exception->getHttpStatusCode();
            if ($status !== null) {
                return $this->description(
                    $this->classifyStatus($status, false, null),
                    self::REASON_CONTRACT_INVALID,
                    $class,
                    (int) $status
                );
            }

            return $this->description(self::CONTRACT_INVALID, self::REASON_CONTRACT_INVALID, $class, null);
        }

        if ($exception instanceof MtUniCreditCpMalformedJsonException) {
            $status = $exception->getHttpStatusCode();
            if ($status !== null) {
                return $this->description(
                    $this->classifyStatus($status, false, null),
                    $status >= 500 || $status === 408 || $status === 429
                        ? self::REASON_TRANSIENT_HTTP
                        : self::REASON_MALFORMED,
                    $class,
                    (int) $status
                );
            }

            return $this->description(self::CONTRACT_INVALID, self::REASON_MALFORMED, $class, null);
        }

        if ($exception instanceof MtUniCreditCpConfigurationException) {
            // Pre-send local destination/configuration defect: attempt fails closed, state
            // preserved, NO LKG. Not proven network/timeout/408/429/5xx → never TRANSIENT.
            return $this->description(self::CONTRACT_INVALID, self::REASON_LOCAL_INFRASTRUCTURE, $class, null);
        }

        if ($exception instanceof MtUniCreditPersistenceException) {
            // Local persistence infrastructure defect: same conservatism rule.
            return $this->description(self::CONTRACT_INVALID, self::REASON_LOCAL_INFRASTRUCTURE, $class, null);
        }

        // Unknown/unclassified Throwable: conservative non-LKG result, known-good state preserved.
        return $this->description(self::CONTRACT_INVALID, self::REASON_UNKNOWN, $class, null);
    }

    /**
     * @param string $errorCode
     * @return bool
     */
    private function isAuthoritativeCode($errorCode)
    {
        return in_array(strtolower(trim((string) $errorCode)), self::$authoritativeErrorCodes, true);
    }

    /**
     * @param string $errorCode
     * @return bool
     */
    private function isTemporaryCode($errorCode)
    {
        return in_array(strtolower(trim((string) $errorCode)), self::$temporaryErrorCodes, true);
    }

    /**
     * @param string $class
     * @param string $reason
     * @param string $exceptionClass
     * @param int|null $httpStatus
     * @return array{class: string, reason: string, exception_class: string, http_status: int|null}
     */
    private function description($class, $reason, $exceptionClass, $httpStatus)
    {
        return array(
            'class' => (string) $class,
            'reason' => (string) $reason,
            'exception_class' => (string) $exceptionClass,
            'http_status' => $httpStatus !== null ? (int) $httpStatus : null,
        );
    }
}
