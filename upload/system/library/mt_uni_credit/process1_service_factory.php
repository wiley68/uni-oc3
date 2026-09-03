<?php

/**
 * Builds the shared Process 1 SmartUCF coordinator stack.
 */
final class MtUniCreditProcess1ServiceFactory
{
    /**
     * @param MtUniCreditDbAdapter $db
     * @param object|null $smartUcfClient Optional client double (must expose createSession)
     * @param MtUniCreditPersistenceClock|null $clock
     * @return MtUniCreditSmartUcfSessionCoordinator
     */
    public static function coordinator(MtUniCreditDbAdapter $db, $smartUcfClient = null, $clock = null)
    {
        $lifecycle = new MtUniCreditSmartUcfLifecycleRepository($db, $clock);
        $client = $smartUcfClient !== null
            ? $smartUcfClient
            : new MtUniCreditSmartUcfSessionClient();

        return new MtUniCreditSmartUcfSessionCoordinator($lifecycle, $client);
    }

    /**
     * @param MtUniCreditDbAdapter $db
     * @param MtUniCreditPersistenceClock|null $clock
     * @return MtUniCreditOrderBankStatusRepository
     */
    public static function bankStatuses(MtUniCreditDbAdapter $db, $clock = null)
    {
        return new MtUniCreditOrderBankStatusRepository($db, $clock);
    }
}
