<?php

/**
 * Builds the shared Process 2 post-CP coordinator stack.
 */
final class MtUniCreditProcessTwoServiceFactory
{
    /**
     * @param MtUniCreditDbAdapter $db
     * @param MtUniCreditControlPanelClient $controlPanelClient
     * @param MtUniCreditProcessTwoMailPort|null $mailer
     * @param MtUniCreditPersistenceClock|null $clock
     * @param string|null $encryptionSecretOverride
     * @return MtUniCreditProcessTwoLifecycleCoordinator
     */
    public static function coordinator(
        MtUniCreditDbAdapter $db,
        MtUniCreditControlPanelClient $controlPanelClient,
        $mailer = null,
        $clock = null,
        $encryptionSecretOverride = null
    ) {
        $lifecycle = new MtUniCreditProcessTwoLifecycleRepository($db, $clock);
        $bankStatuses = new MtUniCreditOrderBankStatusRepository($db, $clock);
        $cipher = new MtUniCreditProcessTwoSensitiveCipher($encryptionSecretOverride);
        if (!$mailer instanceof MtUniCreditProcessTwoMailPort) {
            $mailer = new MtUniCreditPhpMailProcessTwoMailer();
        }

        return new MtUniCreditProcessTwoLifecycleCoordinator(
            $lifecycle,
            $bankStatuses,
            $controlPanelClient,
            $cipher,
            $mailer
        );
    }
}
