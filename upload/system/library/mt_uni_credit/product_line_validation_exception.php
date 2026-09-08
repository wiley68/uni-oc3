<?php

/**
 * Product Apply line reconstruction validation (options / minimum).
 */
final class MtUniCreditProductLineValidationException extends Exception
{
    const CODE_MISSING_REQUIRED_OPTION = 'missing_required_option';
    const CODE_INVALID_OPTION = 'invalid_option';
    const CODE_QUANTITY_BELOW_MINIMUM = 'quantity_below_minimum';
    const CODE_PRODUCT_OPTIONS_UNAVAILABLE = 'product_options_unavailable';

    /** @var string */
    private $errorCode;

    /**
     * @param string $errorCode
     * @param string $message
     */
    public function __construct($errorCode, $message = '')
    {
        $this->errorCode = (string) $errorCode;
        parent::__construct($message !== '' ? (string) $message : (string) $errorCode);
    }

    /**
     * @return string
     */
    public function errorCode()
    {
        return $this->errorCode;
    }
}
