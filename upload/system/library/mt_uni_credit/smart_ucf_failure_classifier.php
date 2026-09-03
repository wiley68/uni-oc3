<?php

/**
 * Maps SmartUCF throwables to lifecycle failure classifications.
 */
final class MtUniCreditSmartUcfFailureClassifier
{
    /**
     * @param Throwable $exception
     * @return MtUniCreditSmartUcfFailureClassification
     */
    public function classifyThrowable($exception)
    {
        if (!$exception instanceof MtUniCreditSmartUcfSessionException) {
            return new MtUniCreditSmartUcfFailureClassification(
                MtUniCreditSmartUcfLifecycleStates::FAILED,
                true,
                MtUniCreditSmartUcfFailureClassification::CLASS_PRE_SEND
            );
        }

        $kind = $exception->failureKind();
        $httpCode = $exception->httpCode();
        $raw = strtolower($exception->rawResponse() . ' ' . $exception->getMessage());

        if ($kind === MtUniCreditSmartUcfSessionException::KIND_PRE_SEND) {
            return new MtUniCreditSmartUcfFailureClassification(
                MtUniCreditSmartUcfLifecycleStates::FAILED,
                true,
                MtUniCreditSmartUcfFailureClassification::CLASS_PRE_SEND,
                $httpCode
            );
        }

        // Structured business rejection (errorCode present) is conclusive remote_reject —
        // even when errorText mentions duplicate/съществува wording.
        if ($this->hasStructuredBusinessError($exception->rawResponse())) {
            if ($httpCode === 0 || $httpCode >= 500) {
                return new MtUniCreditSmartUcfFailureClassification(
                    MtUniCreditSmartUcfLifecycleStates::OUTCOME_UNKNOWN,
                    false,
                    MtUniCreditSmartUcfFailureClassification::CLASS_TRANSPORT_AMBIGUOUS,
                    $httpCode
                );
            }

            return new MtUniCreditSmartUcfFailureClassification(
                MtUniCreditSmartUcfLifecycleStates::FAILED,
                false,
                MtUniCreditSmartUcfFailureClassification::CLASS_REMOTE_REJECT,
                $httpCode
            );
        }

        if ($kind === MtUniCreditSmartUcfSessionException::KIND_DUPLICATE || $this->looksLikeDuplicate($raw)) {
            return new MtUniCreditSmartUcfFailureClassification(
                MtUniCreditSmartUcfLifecycleStates::OUTCOME_UNKNOWN,
                false,
                MtUniCreditSmartUcfFailureClassification::CLASS_DUPLICATE_ORDER_NO,
                $httpCode
            );
        }
        if ($kind === MtUniCreditSmartUcfSessionException::KIND_TRANSPORT || $httpCode === 0 || $httpCode >= 500) {
            return new MtUniCreditSmartUcfFailureClassification(
                MtUniCreditSmartUcfLifecycleStates::OUTCOME_UNKNOWN,
                false,
                MtUniCreditSmartUcfFailureClassification::CLASS_TRANSPORT_AMBIGUOUS,
                $httpCode
            );
        }

        return new MtUniCreditSmartUcfFailureClassification(
            MtUniCreditSmartUcfLifecycleStates::FAILED,
            false,
            MtUniCreditSmartUcfFailureClassification::CLASS_REMOTE_REJECT,
            $httpCode
        );
    }

    /**
     * @param string $raw
     * @return bool
     */
    private function hasStructuredBusinessError($raw)
    {
        $decoded = json_decode($raw);
        if (!is_object($decoded) || !property_exists($decoded, 'errorCode')) {
            return false;
        }

        $code = $decoded->errorCode;

        return $code !== null && $code !== '' && !(is_string($code) && trim($code) === '');
    }

    /**
     * @param string $value
     * @return bool
     */
    private function looksLikeDuplicate($value)
    {
        return ((strpos($value, 'duplicate') !== false && strpos($value, 'order') !== false)
            || strpos($value, 'already exists') !== false
            || strpos($value, 'order already') !== false
            || strpos($value, 'съществува') !== false);
    }
}
