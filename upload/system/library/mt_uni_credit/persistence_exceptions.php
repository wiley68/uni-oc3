<?php

/**
 * Persistence layer exceptions — no sensitive data in messages.
 */
class MtUniCreditPersistenceException extends Exception {}

class MtUniCreditPersistenceValidationException extends MtUniCreditPersistenceException {}

class MtUniCreditSecretPersistException extends MtUniCreditPersistenceException
{
    /** @var string */
    private $languageKey;

    /**
     * @param string $languageKey Admin language key, e.g. error_secret_encrypt_failed
     */
    public function __construct($languageKey)
    {
        parent::__construct('secret_persist_failed');
        $this->languageKey = (string) $languageKey;
    }

    /**
     * @return string
     */
    public function getLanguageKey()
    {
        return $this->languageKey;
    }
}
