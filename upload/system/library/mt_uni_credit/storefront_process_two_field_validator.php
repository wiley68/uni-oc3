<?php

/**
 * Process 2 EGN / phone2 validation (OC4 ProcessTwoFieldValidator parity).
 * Customer-facing copy is injected from language resources (F-010-02).
 */
final class MtUniCreditStorefrontProcessTwoFieldValidator
{
    /** @var array<string, string> */
    private $messages;

    /**
     * @param array<string, string> $messages Localized messages from StorefrontValidationCopy
     */
    public function __construct(array $messages = array())
    {
        // No parallel customer-facing catalogue: OpenCart language via ValidationCopy is authoritative.
        $this->messages = $messages;
    }

    /**
     * @param string $key
     * @return string
     */
    private function message($key)
    {
        return isset($this->messages[$key]) ? (string) $this->messages[$key] : '';
    }

    /**
     * @param array<string, mixed> $posted
     * @return array{ok:bool,errors:array<string,string>,egn:string,phone2:string}
     */
    public function validate(array $posted)
    {
        $errors = array();
        $egnRaw = (string) (isset($posted['egn']) ? $posted['egn'] : '');
        $phone2Raw = (string) (isset($posted['phone2']) ? $posted['phone2'] : '');

        $egnDigits = preg_replace('/\D+/', '', $egnRaw);
        if (!is_string($egnDigits)) {
            $egnDigits = '';
        }
        if ($egnDigits === '') {
            $errors['egn'] = $this->message('egn_required');
        } elseif (!$this->isValidEgn($egnDigits)) {
            $errors['egn'] = $this->message('egn_invalid');
        }

        $phone2 = $this->sanitizePhone($phone2Raw);
        if ($phone2 === '') {
            $errors['phone2'] = $this->message('phone2_required');
        } elseif (!$this->isValidPhone($phone2)) {
            $errors['phone2'] = $this->message('phone2_invalid');
        }

        return array(
            'ok' => $errors === array(),
            'errors' => $errors,
            'egn' => $egnDigits,
            'phone2' => $phone2,
        );
    }

    /**
     * @param string $digits
     * @return bool
     */
    public function isValidEgn($digits)
    {
        if (!preg_match('/^\d{10}$/', $digits)) {
            return false;
        }
        $year = (int) substr($digits, 0, 4);
        $month = (int) substr($digits, 4, 2);
        $day = (int) substr($digits, 6, 2);

        return checkdate($month, $day, $year);
    }

    /**
     * @param string $value
     * @return bool
     */
    public function isValidPhone($value)
    {
        return (bool) preg_match('/^[-0-9+() ]+$/', $value) && (bool) preg_match('/\d/', $value);
    }

    /**
     * @param string $value
     * @return string
     */
    public function sanitizePhone($value)
    {
        $cleaned = preg_replace('/[^0-9+() -]/', '', $value);
        if (!is_string($cleaned)) {
            $cleaned = '';
        }

        return trim($cleaned);
    }
}
