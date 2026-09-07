<?php

/**
 * Shared Product/Cart ordinary applicant field validation (native OC3 bounds).
 *
 * Authority: OpenCart 3 checkout guest/register/payment_address utf8_strlen rules
 * (reference-oc3-core catalog/controller/checkout/*.php) + oc_order column widths.
 *
 * Fail-closed: values must be valid UTF-8 before character-length is trusted.
 */
final class MtUniCreditStorefrontApplicantFieldValidator
{
    const FIRSTNAME_MIN = 1;
    const FIRSTNAME_MAX = 32;
    const LASTNAME_MIN = 1;
    const LASTNAME_MAX = 32;
    const EMAIL_MAX = 96;
    const TELEPHONE_MIN = 3;
    const TELEPHONE_MAX = 32;
    const ADDRESS_MIN = 3;
    const ADDRESS_MAX = 128;

    const MSG_REQUIRED = 'Полето е задължително.';
    const MSG_NAME_LENGTH = 'Полето трябва да бъде между 1 и 32 символа.';
    const MSG_ADDRESS_LENGTH = 'Полето трябва да бъде между 3 и 128 символа.';
    const MSG_PHONE_LENGTH = 'Полето трябва да бъде между 3 и 32 символа.';
    const MSG_PHONE_INVALID = 'Въведете валиден телефонен номер.';
    const MSG_EMAIL_INVALID = 'Въведете валиден e-mail адрес.';
    const MSG_EMAIL_LENGTH = 'Полето трябва да бъде максимум 96 символа.';
    const MSG_INVALID_ENCODING = 'Невалидни символи.';

    /**
     * @param array<string, mixed> $normalized Output of StorefrontPopupFormNormalizer
     * @return array<string, string> Public field-keyed errors (firstname/lastname/address/phone/email)
     */
    public function validate(array $normalized)
    {
        $errors = array();

        $firstname = trim((string) (isset($normalized['firstname']) ? $normalized['firstname'] : ''));
        $lastname = trim((string) (isset($normalized['lastname']) ? $normalized['lastname'] : ''));
        $email = trim((string) (isset($normalized['email']) ? $normalized['email'] : ''));
        $telephone = trim((string) (isset($normalized['telephone']) ? $normalized['telephone'] : ''));
        $address1 = trim((string) (isset($normalized['address_1']) ? $normalized['address_1'] : ''));

        if ($firstname === '') {
            $errors['firstname'] = self::MSG_REQUIRED;
        } elseif (!$this->isValidUtf8($firstname)) {
            $errors['firstname'] = self::MSG_INVALID_ENCODING;
        } else {
            $firstLen = $this->characterLength($firstname);
            if ($firstLen < self::FIRSTNAME_MIN || $firstLen > self::FIRSTNAME_MAX) {
                $errors['firstname'] = self::MSG_NAME_LENGTH;
            }
        }

        if ($lastname === '') {
            $errors['lastname'] = self::MSG_REQUIRED;
        } elseif (!$this->isValidUtf8($lastname)) {
            $errors['lastname'] = self::MSG_INVALID_ENCODING;
        } else {
            $lastLen = $this->characterLength($lastname);
            if ($lastLen < self::LASTNAME_MIN || $lastLen > self::LASTNAME_MAX) {
                $errors['lastname'] = self::MSG_NAME_LENGTH;
            }
        }

        if ($address1 === '') {
            $errors['address'] = self::MSG_REQUIRED;
        } elseif (!$this->isValidUtf8($address1)) {
            $errors['address'] = self::MSG_INVALID_ENCODING;
        } else {
            $addressLen = $this->characterLength($address1);
            if ($addressLen < self::ADDRESS_MIN || $addressLen > self::ADDRESS_MAX) {
                $errors['address'] = self::MSG_ADDRESS_LENGTH;
            }
        }

        if ($telephone === '') {
            $errors['phone'] = self::MSG_REQUIRED;
        } elseif (!$this->isValidUtf8($telephone)) {
            $errors['phone'] = self::MSG_INVALID_ENCODING;
        } else {
            $phoneLen = $this->characterLength($telephone);
            if ($phoneLen < self::TELEPHONE_MIN || $phoneLen > self::TELEPHONE_MAX) {
                $errors['phone'] = self::MSG_PHONE_LENGTH;
            } elseif (!(new MtUniCreditStorefrontProcessTwoFieldValidator())->isValidPhone($telephone)) {
                $errors['phone'] = self::MSG_PHONE_INVALID;
            }
        }

        if ($email === '') {
            $errors['email'] = self::MSG_EMAIL_INVALID;
        } elseif (!$this->isValidUtf8($email)) {
            $errors['email'] = self::MSG_INVALID_ENCODING;
        } else {
            $emailLen = $this->characterLength($email);
            if ($emailLen > self::EMAIL_MAX) {
                $errors['email'] = self::MSG_EMAIL_LENGTH;
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = self::MSG_EMAIL_INVALID;
            }
        }

        return $errors;
    }

    /**
     * True only for well-formed UTF-8 (ASCII and Bulgarian included).
     *
     * @param string $value
     * @return bool
     */
    public function isValidUtf8($value)
    {
        $value = (string) $value;
        if (function_exists('mb_check_encoding')) {
            return (bool) mb_check_encoding($value, 'UTF-8');
        }

        // No mbstring: //u succeeds iff the subject is valid UTF-8.
        return preg_match('//u', $value) === 1;
    }

    /**
     * UTF-8 character length after validity is established.
     * Malformed UTF-8 must not be treated as a bounded acceptable string.
     *
     * @param string $value
     * @return int|null Character length, or null when value is not valid UTF-8
     */
    public function characterLength($value)
    {
        $value = (string) $value;
        if (!$this->isValidUtf8($value)) {
            return null;
        }
        if (function_exists('mb_strlen')) {
            return (int) mb_strlen($value, 'UTF-8');
        }
        if (function_exists('iconv_strlen')) {
            $length = iconv_strlen($value, 'UTF-8');
            if ($length !== false) {
                return (int) $length;
            }
        }
        if (preg_match_all('/./us', $value, $matches)) {
            return count($matches[0]);
        }

        return strlen($value);
    }
}
