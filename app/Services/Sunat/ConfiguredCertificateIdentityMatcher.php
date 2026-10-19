<?php

namespace App\Services\Sunat;

use App\Data\CertificateIdentityMatchResult;
use Throwable;
use UnexpectedValueException;

/**
 * Server-configured certificate-to-RUC matcher.
 *
 * Reads policy OIDs and the selected subject attribute with a bounded DER
 * field reader rather than parsing OpenSSL's human-readable extension dump.
 * It proves only a binding to the configured issuer/profile; it does not
 * evaluate CA/CRL trust, SUNAT recognition, permissions, or submission acceptance.
 */
final class ConfiguredCertificateIdentityMatcher implements CertificateIdentityMatcher
{
    private const MAX_CERTIFICATE_PEM_BYTES = 65536;

    private const MAX_POLICY_COUNT = 64;

    public function __construct(private readonly CertificateIdentityProfileConfiguration $configuration)
    {
    }

    public function match(string $certificatePem, string $expectedRuc): CertificateIdentityMatchResult
    {
        if ($certificatePem === '' || strlen($certificatePem) > self::MAX_CERTIFICATE_PEM_BYTES
            || preg_match('/\A[0-9]{11}\z/D', $expectedRuc) !== 1) {
            return $this->unknown('certificate_identity_input_unavailable');
        }

        try {
            $certificate = @openssl_x509_read($certificatePem);
            $issuer = @openssl_x509_read($this->configuration->issuerCertificatePem());
            if ($certificate === false || $issuer === false
                || ! @openssl_x509_export($certificate, $canonicalPem)) {
                return $this->unknown('certificate_identity_certificate_unavailable');
            }
            $der = self::pemCertificateDer($canonicalPem);
            $issuerDer = self::pemCertificateDer($this->configuration->issuerCertificatePem());
            if ($der === null || $issuerDer === null) {
                return $this->unknown('certificate_identity_certificate_unavailable');
            }

            $leafParts = self::certificateParts($der);
            $issuerParts = self::certificateParts($issuerDer);
            if (! hash_equals($issuerParts['subject_name'], $leafParts['issuer_name'])) {
                return $this->unknown('certificate_identity_issuer_not_configured');
            }

            $issuerPublicKey = @openssl_pkey_get_public($issuer);
            if ($issuerPublicKey === false) {
                return $this->unknown('certificate_identity_issuer_unavailable');
            }
            $signatureResult = @openssl_x509_verify($certificate, $issuerPublicKey);
            if ($signatureResult === 0) {
                return $this->conflict('certificate_identity_signature_conflict');
            }
            if ($signatureResult !== 1) {
                return $this->unknown('certificate_identity_signature_unavailable');
            }

            $policyOids = self::certificatePolicyOids($leafParts['extensions']);
            if ($policyOids === null || $policyOids === []) {
                return $this->unknown('certificate_identity_policy_not_demonstrated');
            }
            if (count(array_unique($policyOids)) !== count($policyOids)) {
                return $this->ambiguous('certificate_identity_policy_ambiguous');
            }
            $matchingProfiles = [];
            foreach ($this->configuration->profiles as $profile) {
                if (in_array($profile->policyOid, $policyOids, true)) {
                    $matchingProfiles[] = $profile;
                }
            }
            if ($matchingProfiles === []) {
                return $this->unknown('certificate_identity_profile_not_configured');
            }
            if (count($matchingProfiles) !== 1) {
                return $this->ambiguous('certificate_identity_profile_ambiguous');
            }

            $values = self::subjectAttributeValues(
                $leafParts['subject_name'],
                $matchingProfiles[0]->rucAttributeOid,
            );
            if ($values === null || $values === []) {
                return $this->unknown('certificate_identity_ruc_not_demonstrated');
            }
            if (count($values) !== 1) {
                return $this->ambiguous('certificate_identity_ruc_ambiguous');
            }
            if ($values[0] === null) {
                return $this->unknown('certificate_identity_ruc_unparseable');
            }
            $prefix = $matchingProfiles[0]->rucAttributePrefix;
            $expectedAttributeValue = $prefix.$expectedRuc;
            if ($values[0] !== $expectedAttributeValue) {
                if (! str_starts_with($values[0], $prefix)
                    || preg_match('/\A[0-9]{11}\z/D', substr($values[0], strlen($prefix))) !== 1) {
                    return $this->unknown('certificate_identity_ruc_unparseable');
                }
                return $this->conflict('certificate_identity_ruc_conflict');
            }

            return new CertificateIdentityMatchResult(CertificateIdentityMatchResult::STATUS_MATCH);
        } catch (Throwable) {
            return $this->unknown('certificate_identity_parse_unavailable');
        }
    }

    private function unknown(string $reason): CertificateIdentityMatchResult
    {
        return new CertificateIdentityMatchResult(CertificateIdentityMatchResult::STATUS_UNKNOWN, $reason);
    }

    private function ambiguous(string $reason): CertificateIdentityMatchResult
    {
        return new CertificateIdentityMatchResult(CertificateIdentityMatchResult::STATUS_AMBIGUOUS, $reason);
    }

    private function conflict(string $reason): CertificateIdentityMatchResult
    {
        return new CertificateIdentityMatchResult(CertificateIdentityMatchResult::STATUS_CONFLICT, $reason);
    }

    private static function pemCertificateDer(string $pem): ?string
    {
        if (preg_match('/\A\s*-----BEGIN CERTIFICATE-----\s*([A-Za-z0-9+\/=\r\n]+)\s*-----END CERTIFICATE-----\s*\z/D', $pem, $matches) !== 1) {
            return null;
        }
        $der = base64_decode(preg_replace('/\s+/', '', $matches[1]), true);
        return is_string($der) && $der !== '' ? $der : null;
    }

    /** @return array{issuer_name: string, subject_name: string, extensions: string} */
    private static function certificateParts(string $der): array
    {
        $outerOffset = 0;
        $outer = self::readTlv($der, $outerOffset);
        if ($outer['tag'] !== 0x30 || $outerOffset !== strlen($der)) {
            throw new UnexpectedValueException('invalid_der');
        }
        $outerFields = self::children($outer['content']);
        if (count($outerFields) !== 3 || $outerFields[0]['tag'] !== 0x30) {
            throw new UnexpectedValueException('invalid_certificate');
        }
        $tbs = self::children($outerFields[0]['content']);
        $index = 0;
        if (($tbs[0]['tag'] ?? null) === 0xa0) {
            $index++;
        }
        if (count($tbs) < $index + 6
            || $tbs[$index]['tag'] !== 0x02
            || $tbs[$index + 1]['tag'] !== 0x30
            || $tbs[$index + 2]['tag'] !== 0x30
            || $tbs[$index + 3]['tag'] !== 0x30
            || $tbs[$index + 4]['tag'] !== 0x30
            || $tbs[$index + 5]['tag'] !== 0x30) {
            throw new UnexpectedValueException('invalid_tbs');
        }
        $issuerName = $tbs[$index + 2]['raw'];
        $subjectName = $tbs[$index + 4]['raw'];
        $extensions = '';
        foreach (array_slice($tbs, $index + 6) as $field) {
            if ($field['tag'] === 0xa3) {
                if ($extensions !== '') {
                    throw new UnexpectedValueException('duplicate_extensions');
                }
                $wrapped = self::children($field['content']);
                if (count($wrapped) !== 1 || $wrapped[0]['tag'] !== 0x30) {
                    throw new UnexpectedValueException('invalid_extensions');
                }
                $extensions = $wrapped[0]['content'];
            }
        }
        return ['issuer_name' => $issuerName, 'subject_name' => $subjectName, 'extensions' => $extensions];
    }

    /** @return list<string>|null null means policy extension is malformed/ambiguous */
    private static function certificatePolicyOids(string $extensionsDer): ?array
    {
        if ($extensionsDer === '') {
            return null;
        }
        $policyValue = null;
        foreach (self::children($extensionsDer) as $extension) {
            if ($extension['tag'] !== 0x30) {
                return null;
            }
            $fields = self::children($extension['content']);
            if (count($fields) < 2 || count($fields) > 3 || $fields[0]['tag'] !== 0x06) {
                return null;
            }
            $oid = self::decodeOid($fields[0]['content']);
            $position = 1;
            if (($fields[$position]['tag'] ?? null) === 0x01) {
                if (strlen($fields[$position]['content']) !== 1
                    || ! in_array(ord($fields[$position]['content']), [0, 255], true)) {
                    return null;
                }
                $position++;
            }
            if ($position !== count($fields) - 1 || $fields[$position]['tag'] !== 0x04) {
                return null;
            }
            if ($oid === '2.5.29.32') {
                if ($policyValue !== null) {
                    return null;
                }
                $policyValue = $fields[$position]['content'];
            }
        }
        if ($policyValue === null) {
            return null;
        }

        $offset = 0;
        $sequence = self::readTlv($policyValue, $offset);
        if ($sequence['tag'] !== 0x30 || $offset !== strlen($policyValue)) {
            return null;
        }
        $result = [];
        foreach (self::children($sequence['content']) as $information) {
            if ($information['tag'] !== 0x30 || count($result) >= self::MAX_POLICY_COUNT) {
                return null;
            }
            $fields = self::children($information['content']);
            if (count($fields) < 1 || count($fields) > 2 || $fields[0]['tag'] !== 0x06) {
                return null;
            }
            if (count($fields) === 2) {
                if ($fields[1]['tag'] !== 0x30) {
                    return null;
                }
                self::validatePolicyQualifiers($fields[1]['content']);
            }
            $result[] = self::decodeOid($fields[0]['content']);
        }
        return $result;
    }

    private static function validatePolicyQualifiers(string $content): void
    {
        foreach (self::children($content) as $qualifier) {
            if ($qualifier['tag'] !== 0x30) {
                throw new UnexpectedValueException('invalid_policy_qualifier');
            }
            $fields = self::children($qualifier['content']);
            if (count($fields) !== 2 || $fields[0]['tag'] !== 0x06) {
                throw new UnexpectedValueException('invalid_policy_qualifier');
            }
            self::decodeOid($fields[0]['content']);
        }
    }

    /** @return list<string|null>|null */
    private static function subjectAttributeValues(string $nameDer, string $attributeOid): ?array
    {
        $offset = 0;
        $name = self::readTlv($nameDer, $offset);
        if ($name['tag'] !== 0x30 || $offset !== strlen($nameDer)) {
            return null;
        }
        $values = [];
        foreach (self::children($name['content']) as $rdn) {
            if ($rdn['tag'] !== 0x31) {
                return null;
            }
            foreach (self::children($rdn['content']) as $attribute) {
                if ($attribute['tag'] !== 0x30) {
                    return null;
                }
                $parts = self::children($attribute['content']);
                if (count($parts) !== 2 || $parts[0]['tag'] !== 0x06) {
                    return null;
                }
                if (self::decodeOid($parts[0]['content']) === $attributeOid) {
                    $values[] = self::decodeRucDirectoryString($parts[1]);
                }
            }
        }
        return $values;
    }

    /** @param array{tag:int,content:string,raw:string} $value */
    private static function decodeRucDirectoryString(array $value): ?string
    {
        if (! in_array($value['tag'], [0x0c, 0x13, 0x16], true)) {
            return null;
        }
        $text = $value['content'];
        if ($text === '' || strlen($text) > 64) {
            return null;
        }
        $printableStringPunctuation = " '()+,-./:=?";
        for ($index = 0, $length = strlen($text); $index < $length; $index++) {
            $byte = ord($text[$index]);
            if ($byte < 0x20 || $byte > 0x7e) {
                return null;
            }
            $isAsciiAlphanumeric = ($byte >= 0x30 && $byte <= 0x39)
                || ($byte >= 0x41 && $byte <= 0x5a)
                || ($byte >= 0x61 && $byte <= 0x7a);
            if ($value['tag'] === 0x13
                && ! $isAsciiAlphanumeric
                && ! str_contains($printableStringPunctuation, $text[$index])) {
                return null;
            }
        }
        return $text;
    }

    /** Decode one canonical DER OBJECT IDENTIFIER. */
    private static function decodeOid(string $content): string
    {
        if ($content === '') {
            throw new UnexpectedValueException('invalid_oid');
        }
        $subidentifiers = [];
        $value = 0;
        $atStart = true;
        $continued = false;
        $length = strlen($content);
        for ($index = 0; $index < $length; $index++) {
            $byte = ord($content[$index]);
            if ($atStart && $byte === 0x80) {
                throw new UnexpectedValueException('noncanonical_oid');
            }
            $atStart = false;
            $part = $byte & 0x7f;
            if ($value > intdiv(PHP_INT_MAX - $part, 128)) {
                throw new UnexpectedValueException('oid_overflow');
            }
            $value = ($value * 128) + $part;
            $continued = ($byte & 0x80) !== 0;
            if (! $continued) {
                $subidentifiers[] = $value;
                if (count($subidentifiers) > 32) {
                    throw new UnexpectedValueException('oid_too_long');
                }
                $value = 0;
                $atStart = true;
            }
        }
        if ($continued || $subidentifiers === []) {
            throw new UnexpectedValueException('truncated_oid');
        }

        $firstCombined = array_shift($subidentifiers);
        $first = $firstCombined < 40 ? 0 : ($firstCombined < 80 ? 1 : 2);
        $second = $firstCombined - ($first * 40);
        return implode('.', [$first, $second, ...$subidentifiers]);
    }

    /** @return array{tag:int,content:string,raw:string} */
    private static function readTlv(string $der, int &$offset): array
    {
        $start = $offset;
        $length = strlen($der);
        if ($offset + 2 > $length) {
            throw new UnexpectedValueException('truncated_der');
        }
        $tag = ord($der[$offset++]);
        if (($tag & 0x1f) === 0x1f) {
            throw new UnexpectedValueException('unsupported_der_tag');
        }
        $firstLength = ord($der[$offset++]);
        if (($firstLength & 0x80) === 0) {
            $contentLength = $firstLength;
        } else {
            $lengthBytes = $firstLength & 0x7f;
            if ($lengthBytes === 0 || $lengthBytes > 4 || $offset + $lengthBytes > $length
                || ord($der[$offset]) === 0) {
                throw new UnexpectedValueException('invalid_der_length');
            }
            $contentLength = 0;
            for ($i = 0; $i < $lengthBytes; $i++) {
                $contentLength = ($contentLength * 256) + ord($der[$offset++]);
            }
            if ($contentLength < 128) {
                throw new UnexpectedValueException('noncanonical_der_length');
            }
        }
        if ($contentLength < 0 || $offset + $contentLength > $length) {
            throw new UnexpectedValueException('truncated_der_content');
        }
        $content = substr($der, $offset, $contentLength);
        $offset += $contentLength;
        return ['tag' => $tag, 'content' => $content, 'raw' => substr($der, $start, $offset - $start)];
    }

    /** @return list<array{tag:int,content:string,raw:string}> */
    private static function children(string $content): array
    {
        $fields = [];
        $offset = 0;
        while ($offset < strlen($content)) {
            if (count($fields) >= 4096) {
                throw new UnexpectedValueException('der_field_limit');
            }
            $fields[] = self::readTlv($content, $offset);
        }
        return $fields;
    }
}
