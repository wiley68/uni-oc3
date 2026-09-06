<?php

/**
 * Shared validated shop snapshot persistence for outbound refresh and inbound push.
 *
 * Credential + cache replacement is failure-atomic via exact compensating restore
 * (OC3 DB has no transaction API; oc_setting is not reliably transactional with InnoDB).
 */
final class MtUniCreditShopCachePersistence
{
    /** @var MtUniCreditShopCacheRepository */
    private $cache;

    /** @var MtUniCreditShopConfigurationSnapshotValidator */
    private $validator;

    /** @var MtUniCreditSmartucfCredentialsRepository */
    private $smartucfCredentials;

    /**
     * @param MtUniCreditShopCacheRepository $cache
     * @param MtUniCreditShopConfigurationSnapshotValidator $validator
     * @param MtUniCreditSmartucfCredentialsRepository $smartucfCredentials
     */
    public function __construct(
        MtUniCreditShopCacheRepository $cache,
        MtUniCreditShopConfigurationSnapshotValidator $validator,
        MtUniCreditSmartucfCredentialsRepository $smartucfCredentials
    ) {
        $this->cache = $cache;
        $this->validator = $validator;
        $this->smartucfCredentials = $smartucfCredentials;
    }

    /**
     * @param int $storeId
     * @param string $unicid
     * @param array<string, mixed> $shopData
     * @return void
     */
    public function replaceValidatedSnapshot($storeId, $unicid, array $shopData)
    {
        $unicid = trim($unicid);
        if ($unicid === '' || $shopData === array()) {
            throw new MtUniCreditPersistenceValidationException('Shop snapshot requires UNICID and non-empty data.');
        }

        $this->validator->validate($shopData, $unicid);
        $partition = MtUniCreditShopSnapshotSanitizer::partitionSensitiveFields($shopData);

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
}
