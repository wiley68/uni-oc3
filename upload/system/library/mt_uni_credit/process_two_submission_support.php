<?php

/**
 * Persist financing presentation for both processes.
 */
final class MtUniCreditProcessTwoSubmissionSupport
{
    /**
     * Durable financing presentation for Thank You / native order mail.
     * Process 1: process2=false. Process 2: process2=true.
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
