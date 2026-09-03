<?php

/**
 * Safe Phase 9 lifecycle markers for remote verification (no secrets / PII).
 */
final class MtUniCreditPhase9LifecycleLog
{
    const EVENT_PROCESS_RAW = 'phase9.process.raw';

    const EVENT_PROCESS_NORMALIZED = 'phase9.process.normalized';

    const EVENT_ENTER = 'phase9.enter';

    const EVENT_SKIP = 'phase9.skip';

    const EVENT_SMARTUCF_BEGIN = 'phase9.smartucf.begin';

    const EVENT_SMARTUCF_RESULT = 'phase9.smartucf.result';

    /** @var MtUniCreditDiagnosticDebugLogRepository|null */
    private $repo;

    /**
     * @param MtUniCreditDbAdapter|null $db
     */
    public function __construct($db = null)
    {
        if ($db instanceof MtUniCreditDbAdapter) {
            try {
                $this->repo = new MtUniCreditDiagnosticDebugLogRepository($db);
            } catch (Exception $exception) {
                $this->repo = null;
            }
        }
    }

    /**
     * @param int $storeId
     * @param int $orderId
     * @param string $entryPoint
     * @param string $eventCode
     * @param array<string, mixed> $summary
     * @return void
     */
    public function record($storeId, $orderId, $entryPoint, $eventCode, array $summary)
    {
        if (!$this->repo instanceof MtUniCreditDiagnosticDebugLogRepository) {
            return;
        }
        try {
            $this->repo->insert(
                (int) $storeId,
                (int) $orderId,
                (string) $entryPoint,
                (string) $eventCode,
                null,
                $summary
            );
        } catch (Exception $exception) {
            // Fail-soft: diagnostics must never break financing.
        }
    }
}
