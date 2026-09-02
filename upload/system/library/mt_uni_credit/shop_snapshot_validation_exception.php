<?php

/**
 * Thrown when a shop configuration snapshot fails canonical structural validation.
 * Must not purge known-good cache on pull.
 */
final class MtUniCreditShopSnapshotValidationException extends RuntimeException
{
    const ERROR_CODE = 'shop_snapshot_invalid';

    /** @var array<int, array<string, string>> */
    private $violations;

    /**
     * @param array<int, array<string, string>> $violations
     * @param string $message
     */
    public function __construct(array $violations, $message = 'Конфигурацията на магазина е невалидна.')
    {
        parent::__construct($message);
        $this->violations = array_values($violations);
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function violations()
    {
        return $this->violations;
    }

    /**
     * @return string
     */
    public function errorCode()
    {
        return self::ERROR_CODE;
    }
}
