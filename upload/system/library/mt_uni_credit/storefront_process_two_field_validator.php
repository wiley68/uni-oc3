<?php

/**
 * Process 2 EGN / phone2 validation (OC4 ProcessTwoFieldValidator parity).
 */
final class MtUniCreditStorefrontProcessTwoFieldValidator
{
    const MSG_EGN_REQUIRED = 'Полето е задължително.';
    const MSG_EGN_INVALID =
    'ЕГН трябва да съдържа 10 цифри. Първите 8 трябва да са валидна дата във формат ГГГГММДД.';
    const MSG_PHONE2_REQUIRED = 'Полето е задължително.';
    const MSG_PHONE2_INVALID =
    'Вторият телефон може да съдържа цифри, интервали, +, -, ( и ).';

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
            $errors['egn'] = self::MSG_EGN_REQUIRED;
        } elseif (!$this->isValidEgn($egnDigits)) {
            $errors['egn'] = self::MSG_EGN_INVALID;
        }

        $phone2 = $this->sanitizePhone($phone2Raw);
        if ($phone2 === '') {
            $errors['phone2'] = self::MSG_PHONE2_REQUIRED;
        } elseif (!$this->isValidPhone($phone2)) {
            $errors['phone2'] = self::MSG_PHONE2_INVALID;
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
