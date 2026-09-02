<?php

/** Invalidates CP auth and scoped shop cache when local credentials change. */
final class MtUniCreditCredentialChangeHandler
{
    /** @var MtUniCreditCpTokenRepository */
    private $tokens;

    /** @var MtUniCreditShopCacheRepository */
    private $cache;

    /** @var int */
    private $storeId;

    /**
     * @param MtUniCreditCpTokenRepository $tokens
     * @param MtUniCreditShopCacheRepository $cache
     * @param int $storeId
     */
    public function __construct(MtUniCreditCpTokenRepository $tokens, MtUniCreditShopCacheRepository $cache, $storeId)
    {
        $this->tokens = $tokens;
        $this->cache = $cache;
        $this->storeId = (int) $storeId;
    }

    /**
     * @param string $previousUnicid
     * @param string $newUnicid
     * @return void
     */
    public function onCredentialsChanged($previousUnicid, $newUnicid)
    {
        $this->tokens->invalidate();

        $previous = trim((string) $previousUnicid);
        if ($previous !== '') {
            $this->cache->deleteScoped($this->storeId, $previous);
        }

        $new = trim((string) $newUnicid);
        if ($new !== '' && $new !== $previous) {
            $this->cache->deleteScoped($this->storeId, $new);
        }
    }

    /**
     * @return void
     */
    public function onSecretDeploymentChanged()
    {
        $this->tokens->invalidate();
    }
}
