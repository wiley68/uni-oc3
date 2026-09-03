<?php

/**
 * Validates SmartUCF client certificate + private key PEM files (OpenSSL, local only).
 */
final class MtUniCreditCertificatePairValidator
{
    /**
     * @param string $certPemPath
     * @param string $keyPemPath
     * @param string $passphrase
     * @return array{ok: bool, errors: array<int, string>}
     */
    public function validate($certPemPath, $keyPemPath, $passphrase)
    {
        $errors = array();
        $certPemPath = (string) $certPemPath;
        $keyPemPath = (string) $keyPemPath;

        if ($certPemPath === '' || !is_file($certPemPath) || !is_readable($certPemPath)) {
            $errors[] = 'Certificate PEM file is missing or unreadable.';
        }
        if ($keyPemPath === '' || !is_file($keyPemPath) || !is_readable($keyPemPath)) {
            $errors[] = 'Private key PEM file is missing or unreadable.';
        }
        if (!empty($errors)) {
            return array('ok' => false, 'errors' => $errors);
        }

        $certPem = file_get_contents($certPemPath);
        $keyPem = file_get_contents($keyPemPath);
        if (!is_string($certPem) || trim($certPem) === '') {
            $errors[] = 'Certificate PEM file is empty.';
        }
        if (!is_string($keyPem) || trim($keyPem) === '') {
            $errors[] = 'Private key PEM file is empty.';
        }
        if (!empty($errors)) {
            return array('ok' => false, 'errors' => $errors);
        }

        $certificate = @openssl_x509_read($certPem);
        if ($certificate === false) {
            $errors[] = 'Certificate could not be parsed as X.509.';
        }

        $privateKey = @openssl_pkey_get_private($keyPem, (string) $passphrase);
        if ($privateKey === false) {
            $errors[] = 'Private key could not be parsed with the provided passphrase.';
        }

        if (!empty($errors)) {
            return array('ok' => false, 'errors' => $errors);
        }

        if (!@openssl_x509_check_private_key($certificate, $privateKey)) {
            $errors[] = 'Private key does not match the certificate.';
        }

        return array(
            'ok' => empty($errors),
            'errors' => $errors,
        );
    }
}
