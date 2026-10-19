<?php

namespace Tests\Unit;

use App\Data\CertificateIdentityMatchResult;
use App\Services\Sunat\CertificateIdentityProfile;
use App\Services\Sunat\CertificateIdentityProfileConfiguration;
use App\Services\Sunat\ConfiguredCertificateIdentityMatcher;
use InvalidArgumentException;
use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use OpenSSLCertificateSigningRequest;
use PHPUnit\Framework\TestCase;

/** Pure synthetic-certificate tests; no app bootstrap or external trust data. */
final class ConfiguredCertificateIdentityMatcherTest extends TestCase
{
    private const POLICY_A = '1.3.6.1.4.1.55555.20.1';

    private const POLICY_B = '1.3.6.1.4.1.55555.20.2';

    private const RUC_OID = '1.3.6.1.4.1.55555.20.3';

    private string $directory;

    private string $opensslConfig;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'identity-matcher-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700));
        $this->opensslConfig = $this->directory.DIRECTORY_SEPARATOR.'openssl.cnf';
        file_put_contents($this->opensslConfig, $this->opensslConfigText());
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->directory);
        parent::tearDown();
    }

    public function test_exact_configured_issuer_profile_and_ruc_match(): void
    {
        [$issuer, $issuerKey] = $this->issueIssuer();
        $leaf = $this->issueLeaf($issuer, $issuerKey, '20123456789', [self::POLICY_A]);

        $result = $this->matcher($issuer, [self::POLICY_A])->match($leaf, '20123456789');
        self::assertSame(CertificateIdentityMatchResult::STATUS_MATCH, $result->status, $result->reason ?? '');
    }

    public function test_wrong_ruc_is_a_conflict_but_cn_is_never_used_as_ruc(): void
    {
        [$issuer, $issuerKey] = $this->issueIssuer();
        $leaf = $this->issueLeaf($issuer, $issuerKey, '20123456789', [self::POLICY_A]);
        $matcher = $this->matcher($issuer, [self::POLICY_A]);

        self::assertSame(CertificateIdentityMatchResult::STATUS_CONFLICT, $matcher->match($leaf, '20987654321')->status);

        $withoutRuc = $this->issueLeaf($issuer, $issuerKey, null, [self::POLICY_A], null, '20123456789');
        self::assertSame(CertificateIdentityMatchResult::STATUS_UNKNOWN, $matcher->match($withoutRuc, '20123456789')->status);
    }

    public function test_missing_unconfigured_and_ambiguous_policy_evidence_stays_pending(): void
    {
        [$issuer, $issuerKey] = $this->issueIssuer();
        $matcher = $this->matcher($issuer, [self::POLICY_A, self::POLICY_B]);

        $missing = $this->issueLeaf($issuer, $issuerKey, '20123456789', []);
        $unconfigured = $this->issueLeaf($issuer, $issuerKey, '20123456789', ['1.3.6.1.4.1.55555.20.99']);
        $ambiguous = $this->issueLeaf($issuer, $issuerKey, '20123456789', [self::POLICY_A, self::POLICY_B]);

        self::assertSame(CertificateIdentityMatchResult::STATUS_UNKNOWN, $matcher->match($missing, '20123456789')->status);
        self::assertSame(CertificateIdentityMatchResult::STATUS_UNKNOWN, $matcher->match($unconfigured, '20123456789')->status);
        self::assertSame(CertificateIdentityMatchResult::STATUS_AMBIGUOUS, $matcher->match($ambiguous, '20123456789')->status);
    }

    public function test_profile_literal_prefix_is_compared_exactly_and_bad_prefix_stays_unknown(): void
    {
        [$issuer, $issuerKey] = $this->issueIssuer();
        $matcher = $this->matcher($issuer, [self::POLICY_A], 'NTRPE-');
        $matching = $this->issueLeaf($issuer, $issuerKey, 'NTRPE-20123456789', [self::POLICY_A]);
        $contraryRuc = $this->issueLeaf($issuer, $issuerKey, 'NTRPE-20987654321', [self::POLICY_A]);
        $malformedPrefix = $this->issueLeaf($issuer, $issuerKey, 'OTHER-20123456789', [self::POLICY_A]);

        self::assertSame(CertificateIdentityMatchResult::STATUS_MATCH, $matcher->match($matching, '20123456789')->status);
        self::assertSame(CertificateIdentityMatchResult::STATUS_CONFLICT, $matcher->match($contraryRuc, '20123456789')->status);
        self::assertSame(CertificateIdentityMatchResult::STATUS_UNKNOWN, $matcher->match($malformedPrefix, '20123456789')->status);
    }

    public function test_duplicate_identical_policy_oid_is_ambiguous(): void
    {
        [$issuer, $issuerKey] = $this->issueIssuer();
        $leaf = $this->issueLeaf($issuer, $issuerKey, '20123456789', [self::POLICY_A, self::POLICY_A]);

        self::assertSame(
            CertificateIdentityMatchResult::STATUS_AMBIGUOUS,
            $this->matcher($issuer, [self::POLICY_A])->match($leaf, '20123456789')->status,
        );
    }

    public function test_duplicate_ruc_attribute_is_ambiguous(): void
    {
        [$issuer, $issuerKey] = $this->issueIssuer();
        $leaf = $this->issueLeaf($issuer, $issuerKey, '20123456789', [self::POLICY_A], '20123456789');

        self::assertSame(
            CertificateIdentityMatchResult::STATUS_AMBIGUOUS,
            $this->matcher($issuer, [self::POLICY_A])->match($leaf, '20123456789')->status,
        );
    }

    public function test_signature_from_another_key_is_a_conflict_even_when_issuer_name_matches(): void
    {
        [$approvedIssuer] = $this->issueIssuer();
        [$otherIssuer, $otherIssuerKey] = $this->issueIssuer();
        $rogueLeaf = $this->issueLeaf($otherIssuer, $otherIssuerKey, '20123456789', [self::POLICY_A]);

        self::assertSame(
            CertificateIdentityMatchResult::STATUS_CONFLICT,
            $this->matcher($approvedIssuer, [self::POLICY_A])->match($rogueLeaf, '20123456789')->status,
        );
    }

    public function test_issuer_der_pin_is_mandatory_and_checked_at_configuration_time(): void
    {
        [$issuer] = $this->issueIssuer();
        $this->expectException(InvalidArgumentException::class);
        new CertificateIdentityProfileConfiguration(
            $issuer,
            str_repeat('0', 64),
            [new CertificateIdentityProfile('synthetic', self::POLICY_A, self::RUC_OID)],
        );
    }

    public function test_invalid_expected_ruc_remains_unknown(): void
    {
        [$issuer, $issuerKey] = $this->issueIssuer();
        $leaf = $this->issueLeaf($issuer, $issuerKey, '20123456789', [self::POLICY_A]);

        self::assertSame(
            CertificateIdentityMatchResult::STATUS_UNKNOWN,
            $this->matcher($issuer, [self::POLICY_A])->match($leaf, '2012345678x')->status,
        );
    }

    /** @param list<string> $policyOids */
    private function matcher(string $issuerPem, array $policyOids, string $rucPrefix = ''): ConfiguredCertificateIdentityMatcher
    {
        $profiles = [];
        foreach ($policyOids as $index => $policyOid) {
            $profiles[] = new CertificateIdentityProfile('synthetic-'.$index, $policyOid, self::RUC_OID, $rucPrefix);
        }
        return new ConfiguredCertificateIdentityMatcher(
            new CertificateIdentityProfileConfiguration($issuerPem, hash('sha256', $this->pemDer($issuerPem)), $profiles),
        );
    }

    /** @return array{string, OpenSSLAsymmetricKey} */
    private function issueIssuer(): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        self::assertInstanceOf(OpenSSLAsymmetricKey::class, $key);
        $csr = openssl_csr_new(['commonName' => 'Synthetic infrastructure issuer'], $key, [
            'digest_alg' => 'sha256',
            'config' => $this->opensslConfig,
        ]);
        self::assertInstanceOf(OpenSSLCertificateSigningRequest::class, $csr);
        $certificate = openssl_csr_sign($csr, null, $key, 30, [
            'digest_alg' => 'sha256',
            'config' => $this->opensslConfig,
            'x509_extensions' => 'v3_ca',
        ]);
        self::assertInstanceOf(OpenSSLCertificate::class, $certificate);
        self::assertTrue(openssl_x509_export($certificate, $pem));
        return [$pem, $key];
    }

    /** @param list<string> $policyOids */
    private function issueLeaf(
        string $issuerPem,
        OpenSSLAsymmetricKey $signingKey,
        ?string $ruc,
        array $policyOids,
        ?string $duplicateRuc = null,
        string $commonName = 'Synthetic leaf',
    ): string {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        self::assertInstanceOf(OpenSSLAsymmetricKey::class, $key);
        $distinguishedName = ['commonName' => $commonName];
        if ($ruc !== null) {
            $distinguishedName['testRuc'] = $ruc;
        }
        if ($duplicateRuc !== null) {
            // The dotted OID is a second explicit DN entry for the same field.
            $distinguishedName[self::RUC_OID] = $duplicateRuc;
        }
        $csr = openssl_csr_new($distinguishedName, $key, [
            'digest_alg' => 'sha256',
            'config' => $this->opensslConfig,
        ]);
        self::assertInstanceOf(OpenSSLCertificateSigningRequest::class, $csr);
        $config = $this->opensslConfigText($policyOids);
        file_put_contents($this->opensslConfig, $config);
        $issuer = openssl_x509_read($issuerPem);
        self::assertInstanceOf(OpenSSLCertificate::class, $issuer);
        $certificate = openssl_csr_sign($csr, $issuer, $signingKey, 30, [
            'digest_alg' => 'sha256',
            'config' => $this->opensslConfig,
            'x509_extensions' => 'v3_leaf',
        ]);
        self::assertInstanceOf(OpenSSLCertificate::class, $certificate);
        self::assertTrue(openssl_x509_export($certificate, $pem));
        return $pem;
    }

    /** @param list<string>|null $policies */
    private function opensslConfigText(?array $policies = null): string
    {
        $policyExtension = $policies === null || $policies === [] ? '' : 'certificatePolicies = '.implode(',', $policies)."\n";
        return "oid_section = custom_oids\n"
            ."[custom_oids]\n"
            .'testRuc = '.self::RUC_OID."\n"
            ."[req]\ndistinguished_name = request_dn\nprompt = no\ndefault_md = sha256\n"
            ."[request_dn]\ncommonName = Synthetic request\n"
            ."[v3_ca]\nbasicConstraints = critical,CA:TRUE\nkeyUsage = critical,keyCertSign,cRLSign\n"
            ."[v3_leaf]\nbasicConstraints = critical,CA:FALSE\nkeyUsage = critical,digitalSignature\n"
            .$policyExtension;
    }

    private function pemDer(string $pem): string
    {
        $body = preg_replace('/-----BEGIN CERTIFICATE-----|-----END CERTIFICATE-----|\s+/', '', $pem);
        $der = base64_decode($body, true);
        self::assertIsString($der);
        return $der;
    }
}
