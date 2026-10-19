<?php

namespace App\Services\Sunat;

use InvalidArgumentException;

/**
 * Explicit infrastructure-owned issuer pin and allowlisted policy profiles.
 * This is identity-matcher configuration only, not a CA/CRL trust bundle.
 */
final readonly class CertificateIdentityProfileConfiguration
{
    private string $issuerCertificatePem;

    public string $issuerSha256Der;

    /** @var list<CertificateIdentityProfile> */
    public array $profiles;

    /**
     * @param list<CertificateIdentityProfile> $profiles
     */
    public function __construct(string $issuerCertificatePem, string $issuerSha256Der, array $profiles)
    {
        if (strlen($issuerCertificatePem) < 1 || strlen($issuerCertificatePem) > 65536
            || preg_match('/\A[a-f0-9]{64}\z/D', $issuerSha256Der) !== 1
            || ! array_is_list($profiles) || count($profiles) < 1 || count($profiles) > 32) {
            throw new InvalidArgumentException('certificate_identity_configuration_invalid');
        }

        $issuer = @openssl_x509_read($issuerCertificatePem);
        if ($issuer === false || ! @openssl_x509_export($issuer, $canonicalPem)) {
            throw new InvalidArgumentException('certificate_identity_configuration_invalid');
        }
        $issuerDer = self::pemCertificateDer($canonicalPem);
        if ($issuerDer === null || ! hash_equals($issuerSha256Der, hash('sha256', $issuerDer))) {
            throw new InvalidArgumentException('certificate_identity_configuration_invalid');
        }

        $seenProfiles = [];
        $seenPolicies = [];
        foreach ($profiles as $profile) {
            if (! $profile instanceof CertificateIdentityProfile
                || isset($seenProfiles[$profile->profileId])
                || isset($seenPolicies[$profile->policyOid])) {
                throw new InvalidArgumentException('certificate_identity_configuration_invalid');
            }
            $seenProfiles[$profile->profileId] = true;
            $seenPolicies[$profile->policyOid] = true;
        }

        $this->issuerCertificatePem = $canonicalPem;
        $this->issuerSha256Der = $issuerSha256Der;
        $this->profiles = $profiles;
    }

    public function issuerCertificatePem(): string
    {
        return $this->issuerCertificatePem;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return [
            'issuerSha256Der' => $this->issuerSha256Der,
            'profiles' => array_map(static fn (CertificateIdentityProfile $profile): string => $profile->profileId, $this->profiles),
            'issuerCertificatePem' => '[redacted public certificate material]',
        ];
    }

    private static function pemCertificateDer(string $pem): ?string
    {
        if (preg_match('/\A\s*-----BEGIN CERTIFICATE-----\s*([A-Za-z0-9+\/=\r\n]+)\s*-----END CERTIFICATE-----\s*\z/D', $pem, $matches) !== 1) {
            return null;
        }

        $der = base64_decode(preg_replace('/\s+/', '', $matches[1]), true);
        return is_string($der) && $der !== '' ? $der : null;
    }
}
