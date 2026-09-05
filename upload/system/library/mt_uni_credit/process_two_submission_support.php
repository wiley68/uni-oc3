<?php

/**
 * Persist Process 2 EGN/phone2 onto the financing attempt (encrypted).
 */
final class MtUniCreditProcessTwoSubmissionSupport
{
    /**
     * @param array<string, mixed> $shop
     * @param array<string, mixed> $posted
     * @return MtUniCreditProcessTwoSensitiveData|null
     */
    public static function validateIfRequired(array $shop, array $posted)
    {
        if (!MtUniCreditShopConfigurationFlags::isSecondaryProcess($shop)) {
            return null;
        }

        $result = (new MtUniCreditStorefrontProcessTwoFieldValidator())->validate($posted);
        if (empty($result['ok'])) {
            $message = isset($result['errors']['egn'])
                ? (string) $result['errors']['egn']
                : (isset($result['errors']['phone2']) ? (string) $result['errors']['phone2'] : 'validation');
            throw new InvalidArgumentException($message);
        }

        return new MtUniCreditProcessTwoSensitiveData($result['egn'], $result['phone2']);
    }

    /**
     * @param MtUniCreditProcessTwoSensitiveData $data
     * @param int $attemptId
     * @param MtUniCreditDbAdapter $db
     * @param string|null $encryptionSecretOverride
     * @return void
     */
    public static function persist(
        MtUniCreditProcessTwoSensitiveData $data,
        $attemptId,
        MtUniCreditDbAdapter $db,
        $encryptionSecretOverride = null
    ) {
        try {
            $cipher = new MtUniCreditProcessTwoSensitiveCipher($encryptionSecretOverride);
            $encrypted = $cipher->encrypt($data);
        } catch (Throwable $exception) {
            throw new RuntimeException('process2_encryption_unavailable', 0, $exception);
        }

        (new MtUniCreditProcessTwoLifecycleRepository($db))->persistSensitiveEncrypted(
            (int) $attemptId,
            $encrypted
        );
    }

    /**
     * Durable financing presentation for Thank You / native order mail.
     * Process 1: process2=false (no EGN / P2 message). Process 2: process2=true.
     *
     * @param MtUniCreditCalculationResult $calculation
     * @param int $shopOrderId
     * @param int $attemptId
     * @param MtUniCreditDbAdapter $db
     * @param int|null $cpOrderId
     * @param bool $process2
     * @return void
     */
    public static function persistLeasingSnapshot(
        MtUniCreditCalculationResult $calculation,
        $shopOrderId,
        $attemptId,
        MtUniCreditDbAdapter $db,
        $cpOrderId = null,
        $process2 = true
    ) {
        $snapshot = MtUniCreditFinancingPresentationSnapshot::fromCalculation(
            $calculation,
            (int) $shopOrderId,
            (bool) $process2,
            $cpOrderId
        );
        try {
            $json = json_encode($snapshot->toArray(), JSON_THROW_ON_ERROR);
        } catch (Throwable $ignored) {
            return;
        }
        (new MtUniCreditProcessTwoLifecycleRepository($db))->persistLeasingPresentationJson(
            (int) $attemptId,
            $json
        );
    }
}
