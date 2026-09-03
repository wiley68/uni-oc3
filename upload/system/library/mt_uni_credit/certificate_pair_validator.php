<?php

/**
 * Validates SmartUCF client certificate + private key PEM files (OpenSSL, local only).
 */
final class MtUniCreditCertificatePairValidator
{
    /** @var MtUniCreditMtlsPrivateKeyPassphraseProvider */
    private $passphrases;

    /**
     * @param MtUniCreditMtlsPrivateKeyPassphraseProvider|null $passphrases
     */
    public function __construct($passphrases = null)
    {
        $this->passphrases = $passphrases instanceof MtUniCreditMtlsPrivateKeyPassphraseProvider
            ? $passphrases
            : new MtUniCreditMtlsPrivateKeyPassphraseProvider();
    }

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

    /**
     * @param string $secretsFilePath
     * @return string
     */
    public function privateKeyPassphrase($secretsFilePath)
    {
        return $this->passphrases->requirePassphrase($secretsFilePath);
    }

    /**
     * @param string $pemBytes
     * @return string
     */
    public function sha256($pemBytes)
    {
        return hash('sha256', (string) $pemBytes);
    }

    /**
     * @param string $value
     * @return bool
     */
    public function isSha256Hex($value)
    {
        return (bool) preg_match('/^[a-f0-9]{64}$/', (string) $value);
    }

    /**
     * @param string $certificatePem
     * @param string $privateKeyPem
     * @param string $passphrase
     * @return array{
     *   certificate_pem: string,
     *   private_key_pem: string,
     *   not_before: string,
     *   not_after: string,
     *   not_before_timestamp: int,
     *   not_after_timestamp: int
     * }
     */
    public function validatePemPair($certificatePem, $privateKeyPem, $passphrase)
    {
        $cert = str_replace("\r\n", "\n", trim((string) $certificatePem));
        $key = str_replace("\r\n", "\n", trim((string) $privateKeyPem));
        if ($cert === '') {
            throw new InvalidArgumentException('The certificate PEM is empty.');
        }
        if ($key === '') {
            throw new InvalidArgumentException('The private key PEM is empty.');
        }
        if (strpos($cert, '-----BEGIN CERTIFICATE-----') === false || strpos($cert, '-----END CERTIFICATE-----') === false) {
            throw new InvalidArgumentException('The certificate is not a valid PEM.');
        }
        if (strpos($key, '-----BEGIN') === false || strpos($key, 'PRIVATE KEY-----') === false || strpos($key, '-----END') === false) {
            throw new InvalidArgumentException('The private key is not a valid PEM.');
        }

        $certificate = @openssl_x509_read($cert);
        if ($certificate === false) {
            throw new InvalidArgumentException('The certificate could not be parsed as X.509.');
        }
        $privateKey = @openssl_pkey_get_private($key, (string) $passphrase);
        if ($privateKey === false) {
            throw new InvalidArgumentException('The private key could not be parsed.');
        }
        if (!@openssl_x509_check_private_key($certificate, $privateKey)) {
            throw new InvalidArgumentException('The private key does not match the certificate.');
        }

        $parsed = openssl_x509_parse($certificate, false);
        if ($parsed === false || !isset($parsed['validFrom_time_t']) || !isset($parsed['validTo_time_t'])) {
            throw new InvalidArgumentException('Certificate validity dates could not be read.');
        }
        $notBefore = (int) $parsed['validFrom_time_t'];
        $notAfter = (int) $parsed['validTo_time_t'];
        $now = time();
        if ($notBefore > $now) {
            throw new InvalidArgumentException('The certificate is not yet valid.');
        }
        if ($notAfter < $now) {
            throw new InvalidArgumentException('The certificate has expired.');
        }

        return array(
            'certificate_pem' => (string) $certificatePem,
            'private_key_pem' => (string) $privateKeyPem,
            'not_before' => gmdate('c', $notBefore),
            'not_after' => gmdate('c', $notAfter),
            'not_before_timestamp' => $notBefore,
            'not_after_timestamp' => $notAfter,
        );
    }
}
