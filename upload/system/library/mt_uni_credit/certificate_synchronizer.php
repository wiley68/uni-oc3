<?php

/**
 * Synchronizes SmartUCF certificate material from authenticated CP.
 */
final class MtUniCreditCertificateSynchronizer
{
    /** @var MtUniCreditControlPanelClient */
    private $client;

    /** @var MtUniCreditCertificateLocalStore */
    private $store;

    /** @var MtUniCreditCertificatePairValidator */
    private $validator;

    /** @var MtUniCreditCertificateLocalPaths */
    private $paths;

    /** @var MtUniCreditMtlsPrivateKeyPassphraseProvider */
    private $passphrases;

    public function __construct(
        MtUniCreditControlPanelClient $client,
        $store = null,
        $validator = null,
        $paths = null,
        $passphrases = null
    ) {
        $this->client = $client;
        $this->paths = $paths instanceof MtUniCreditCertificateLocalPaths
            ? $paths
            : new MtUniCreditCertificateLocalPaths();
        $this->passphrases = $passphrases instanceof MtUniCreditMtlsPrivateKeyPassphraseProvider
            ? $passphrases
            : new MtUniCreditMtlsPrivateKeyPassphraseProvider();
        $this->validator = $validator instanceof MtUniCreditCertificatePairValidator
            ? $validator
            : new MtUniCreditCertificatePairValidator($this->passphrases);
        $this->store = $store instanceof MtUniCreditCertificateLocalStore
            ? $store
            : new MtUniCreditCertificateLocalStore($this->paths, $this->validator);
    }

    public function ensureCurrent()
    {
        $this->store->assertWritableStore();

        try {
            $passphrase = $this->passphrases->requirePassphrase($this->paths->passphrasePath());
        } catch (Throwable $exception) {
            throw new MtUniCreditCertificateSyncException(
                'SmartUCF key passphrase is missing or invalid.',
                MtUniCreditCertificateSyncException::REASON_PASSPHRASE_NOT_CONFIGURED,
                $exception
            );
        }

        try {
            $metadata = $this->client->getSslCertificateMetadata();
        } catch (MtUniCreditCpHttpException $exception) {
            if ($this->isExplicitUnavailable($exception)) {
                throw new MtUniCreditCertificateSyncException(
                    'Control Panel reports no SSL certificate available.',
                    MtUniCreditCertificateSyncException::REASON_CP_UNAVAILABLE,
                    $exception
                );
            }
            if ($exception->isTransient()) {
                return $this->failOpenOrThrow($passphrase, $exception);
            }
            throw new MtUniCreditCertificateSyncException(
                'Control Panel SSL metadata request was rejected.',
                MtUniCreditCertificateSyncException::REASON_CP_TRANSPORT,
                $exception
            );
        } catch (MtUniCreditCpException $exception) {
            if ($exception->isTransient()) {
                return $this->failOpenOrThrow($passphrase, $exception);
            }
            throw new MtUniCreditCertificateSyncException(
                'Control Panel SSL metadata response is not usable.',
                MtUniCreditCertificateSyncException::REASON_CP_TRANSPORT,
                $exception
            );
        }

        if (empty($metadata['available'])) {
            throw new MtUniCreditCertificateSyncException(
                'Control Panel SSL metadata is unavailable.',
                MtUniCreditCertificateSyncException::REASON_CP_UNAVAILABLE
            );
        }

        if ($this->matchesMetadata($this->store->validateLocalPair($passphrase), $metadata)) {
            return $this->store->withSharedLock(function () use ($passphrase) {
                return $this->store->createConsumerPairLease($passphrase);
            });
        }

        return $this->store->withExclusiveLock(function () use ($metadata, $passphrase) {
            if ($this->matchesMetadata($this->store->validateLocalPair($passphrase), $metadata)) {
                return $this->store->createConsumerPairLease($passphrase);
            }

            try {
                $bundle = $this->client->downloadSslCertificateBundle();
            } catch (MtUniCreditCpException $exception) {
                throw new MtUniCreditCertificateSyncException(
                    'SSL certificate bundle download failed.',
                    MtUniCreditCertificateSyncException::REASON_REFRESH_FAILED,
                    $exception
                );
            }

            $this->assertBundleIntegrity($bundle, $metadata);
            $this->store->replacePair(
                (string) $bundle['certificate_pem'],
                (string) $bundle['private_key_pem'],
                array(
                    'ssl_revision' => (string) (isset($bundle['ssl_revision']) ? $bundle['ssl_revision'] : (isset($metadata['ssl_revision']) ? $metadata['ssl_revision'] : '')),
                    'certificate_sha256' => (string) $bundle['certificate_sha256'],
                    'private_key_sha256' => (string) $bundle['private_key_sha256'],
                ),
                $passphrase
            );

            return $this->store->createConsumerPairLease($passphrase);
        });
    }

    /**
     * @param string $passphrase
     * @param Throwable $exception
     * @return MtUniCreditCertificateConsumerLease
     */
    private function failOpenOrThrow(string $passphrase, Throwable $exception)
    {
        if ($this->store->validateLocalPair($passphrase) !== null) {
            return $this->store->withSharedLock(function () use ($passphrase) {
                return $this->store->createConsumerPairLease($passphrase);
            });
        }

        throw new MtUniCreditCertificateSyncException(
            'Control Panel SSL metadata is unavailable and the local certificate pair is not usable.',
            MtUniCreditCertificateSyncException::REASON_CP_TRANSPORT,
            $exception
        );
    }

    private function isExplicitUnavailable(MtUniCreditCpHttpException $exception)
    {
        if ((int) $exception->getStatusCode() !== 404) {
            return false;
        }
        $payload = $exception->getErrorPayload();

        return ((isset($payload['error']) ? $payload['error'] : '') === 'ssl_certificate_unavailable')
            || (isset($payload['data']) && is_array($payload['data']) && array_key_exists('available', $payload['data']) && $payload['data']['available'] === false);
    }

    /**
     * @param array<string, string>|null $local
     * @param array<string, mixed> $metadata
     * @return bool
     */
    private function matchesMetadata(?array $local, array $metadata)
    {
        return is_array($local)
            && hash_equals((string) $metadata['certificate_sha256'], (string) $local['certificate_sha256'])
            && hash_equals((string) $metadata['private_key_sha256'], (string) $local['private_key_sha256']);
    }

    private function assertBundleIntegrity(array $bundle, array $metadata)
    {
        foreach (array('certificate_pem', 'private_key_pem', 'certificate_sha256', 'private_key_sha256') as $field) {
            if (!isset($bundle[$field]) || !is_string($bundle[$field]) || trim((string) $bundle[$field]) === '') {
                throw new MtUniCreditCertificateSyncException(
                    'SSL certificate bundle is missing required fields.',
                    MtUniCreditCertificateSyncException::REASON_INVALID_BUNDLE
                );
            }
        }

        $certificateHash = strtolower((string) $bundle['certificate_sha256']);
        $privateKeyHash = strtolower((string) $bundle['private_key_sha256']);
        if (!$this->validator->isSha256Hex($certificateHash) || !$this->validator->isSha256Hex($privateKeyHash)) {
            throw new MtUniCreditCertificateSyncException(
                'SSL certificate bundle hashes are malformed.',
                MtUniCreditCertificateSyncException::REASON_INVALID_BUNDLE
            );
        }
        if (
            !hash_equals($certificateHash, $this->validator->sha256((string) $bundle['certificate_pem']))
            || !hash_equals($privateKeyHash, $this->validator->sha256((string) $bundle['private_key_pem']))
        ) {
            throw new MtUniCreditCertificateSyncException(
                'Downloaded SSL PEM digests do not match their declared hashes.',
                MtUniCreditCertificateSyncException::REASON_INVALID_BUNDLE
            );
        }
        if (
            !hash_equals((string) $metadata['certificate_sha256'], $certificateHash)
            || !hash_equals((string) $metadata['private_key_sha256'], $privateKeyHash)
        ) {
            throw new MtUniCreditCertificateSyncException(
                'Downloaded SSL bundle does not match the metadata that triggered refresh.',
                MtUniCreditCertificateSyncException::REASON_INVALID_BUNDLE
            );
        }
    }
}
