<?php

/**
 * Signed operation binding for CP→module inbound endpoints.
 */
final class MtUniCreditInboundApiOperations
{
    const SHOP_CACHE = 'shop-cache';

    const ORDER_BANK_STATUS = 'order-bank-status';

    const SMARTUCF_DEBUG_LOG = 'smartucf-debug-log';

    /**
     * @return array<int, string>
     */
    public static function all()
    {
        return array(
            self::SHOP_CACHE,
            self::ORDER_BANK_STATUS,
            self::SMARTUCF_DEBUG_LOG,
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @param string $expected
     * @return void
     */
    public static function assertExact(array $payload, $expected)
    {
        $operation = isset($payload['operation']) ? $payload['operation'] : null;
        if (!is_string($operation) || $operation === '') {
            throw new MtUniCreditInboundApiException(
                'Полето operation е задължително.',
                400,
                'unsupported_operation'
            );
        }
        if ($operation !== (string) $expected) {
            throw new MtUniCreditInboundApiException(
                'Неподдържана операция за този endpoint.',
                400,
                'unsupported_operation'
            );
        }
    }
}
