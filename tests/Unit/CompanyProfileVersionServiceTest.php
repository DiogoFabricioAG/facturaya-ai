<?php

namespace Tests\Unit;

use App\Data\CompanyProfileSnapshot;
use App\Data\CompanyProfileSyncDecision;
use App\Data\CompanyProfileSyncReceipt;
use App\Services\CompanyProfileVersionService;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CompanyProfileVersionServiceTest extends TestCase
{
    private const COMPANY_REF = '01J8ZK9M2Q7C3V6B4N5R8T1W0X';

    private const OTHER_COMPANY_REF = '01J8ZK9M2Q7C3V6B4N5R8T1W0Y';

    private const TENANT_ID = 'c1f2e3d4-b5a6-4789-8012-3456789abcde';

    private const SYNTHETIC_CERTIFICATE = 'synthetic-pkcs12-container-bytes-v1';

    private function service(): CompanyProfileVersionService
    {
        return new CompanyProfileVersionService;
    }

    private function snapshot(
        string $certificate = self::SYNTHETIC_CERTIFICATE,
        int $fiscalRevision = 0,
        int $profileVersion = 1,
        string $companyRef = self::COMPANY_REF,
        string $environment = 'production',
    ): CompanyProfileSnapshot {
        return $this->service()->buildSnapshot(
            companyRef: $companyRef,
            ruc: '20123456789',
            environment: $environment,
            solUsername: 'MODDATOS',
            certificateDer: $certificate,
            fiscalRevision: $fiscalRevision,
            profileVersion: $profileVersion,
        );
    }

    public function test_build_snapshot_is_deterministic_and_masks_the_username(): void
    {
        $first = $this->snapshot();
        $second = $this->snapshot();

        $this->assertSame($first->fiscalDigest, $second->fiscalDigest);
        $this->assertSame('MO***S', $first->solUsernameMasked);
        $this->assertNotSame('MODDATOS', $first->solUsernameMasked);
    }

    public function test_public_certificate_or_fiscal_revision_changes_the_digest(): void
    {
        $base = $this->snapshot();

        $rotatedCertificate = $this->snapshot(certificate: 'synthetic-public-certificate-der-v2');

        // La rotación de `certificate_password` no cambia el DER público; se
        // representa con el bump de `fiscal_revision` que hace el servidor.
        $rotatedPasswordRevision = $this->snapshot(fiscalRevision: 1);

        $this->assertNotSame($base->fiscalDigest, $rotatedCertificate->fiscalDigest);
        $this->assertNotSame($base->fiscalDigest, $rotatedPasswordRevision->fiscalDigest);
        $this->assertSame(
            $base->certificateSha256,
            $this->snapshot(fiscalRevision: 1)->certificateSha256,
            'El fingerprint depende del DER público, no de la frase de paso.',
        );
        $this->assertNotSame(
            $base->certificateSha256,
            $rotatedCertificate->certificateSha256,
        );
    }

    public function test_invalid_inputs_are_rejected_with_opaque_codes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('company_profile_invalid_environment');

        $this->snapshot(environment: 'staging');
    }

    public function test_empty_certificate_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('company_profile_missing_certificate_der');

        $this->snapshot(certificate: '');
    }

    public function test_binding_is_current_only_for_same_version_and_digest(): void
    {
        $service = $this->service();
        $snapshot = $this->snapshot();
        $binding = $service->bindingFor(self::TENANT_ID, $snapshot);

        $this->assertTrue($service->isEvidenceCurrent($binding, $snapshot));
        $this->assertTrue($binding->matches($this->snapshot()));

        $changedProfile = $this->snapshot(fiscalRevision: 2);

        $this->assertFalse($service->isEvidenceCurrent($binding, $changedProfile));
        $this->assertFalse($binding->matches($changedProfile));
    }

    public function test_binding_does_not_match_a_different_company_snapshot(): void
    {
        $service = $this->service();
        $binding = $service->bindingFor(self::TENANT_ID, $this->snapshot());
        $otherCompany = $this->snapshot(companyRef: self::OTHER_COMPANY_REF);

        $this->assertFalse($service->isEvidenceCurrent($binding, $otherCompany));
        $this->assertFalse($binding->matches($otherCompany));
    }

    public function test_sync_creates_then_keeps_unchanged_without_bumping(): void
    {
        $service = $this->service();
        $incoming = $this->snapshot();

        $created = $service->syncDecision(null, $incoming, 0, 'sync-key-1');

        $this->assertSame(CompanyProfileSyncDecision::CREATED, $created->status);
        $this->assertSame(1, $created->profileVersion);

        $unchanged = $service->syncDecision($incoming, $incoming, 0, 'sync-key-2');

        $this->assertSame(CompanyProfileSyncDecision::UNCHANGED, $unchanged->status);
        $this->assertSame(1, $unchanged->profileVersion);
        $this->assertSame($incoming->fiscalDigest, $unchanged->fiscalDigest);
        $this->assertSame('no_change_ignores_expected_version', $unchanged->reason);
    }

    public function test_sync_unchanged_with_matching_expected_version_has_no_reason(): void
    {
        $service = $this->service();
        $current = $this->snapshot();

        $unchanged = $service->syncDecision($current, $this->snapshot(), 1, 'sync-key-match');

        $this->assertSame(CompanyProfileSyncDecision::UNCHANGED, $unchanged->status);
        $this->assertNull($unchanged->reason);
    }

    public function test_create_with_non_zero_expected_version_conflicts(): void
    {
        $service = $this->service();

        $decision = $service->syncDecision(null, $this->snapshot(), 3, 'sync-key-create');

        $this->assertSame(CompanyProfileSyncDecision::VERSION_CONFLICT, $decision->status);
        $this->assertSame(0, $decision->profileVersion);
        $this->assertSame('expected_version_mismatch', $decision->reason);
    }

    public function test_zero_expected_version_conflicts_when_profile_changes(): void
    {
        $service = $this->service();
        $current = $this->snapshot();

        $decision = $service->syncDecision($current, $this->snapshot(fiscalRevision: 9), 0, 'sync-key-zero');

        $this->assertSame(CompanyProfileSyncDecision::VERSION_CONFLICT, $decision->status);
        $this->assertSame(1, $decision->profileVersion);
        $this->assertSame('expected_version_mismatch', $decision->reason);
    }

    public function test_sync_update_requires_expected_version_and_bumps_once(): void
    {
        $service = $this->service();
        $current = $this->snapshot();
        $incoming = $this->snapshot(fiscalRevision: 1);

        $updated = $service->syncDecision($current, $incoming, 1, 'sync-key-3');

        $this->assertSame(CompanyProfileSyncDecision::UPDATED, $updated->status);
        $this->assertSame(2, $updated->profileVersion);
        $this->assertSame($incoming->fiscalDigest, $updated->fiscalDigest);

        $stale = $service->syncDecision($current, $incoming, 2, 'sync-key-4');

        $this->assertSame(CompanyProfileSyncDecision::VERSION_CONFLICT, $stale->status);
        $this->assertSame('expected_version_mismatch', $stale->reason);
        $this->assertSame(1, $stale->profileVersion);
    }

    public function test_idempotency_replay_returns_the_original_result(): void
    {
        $service = $this->service();
        $incoming = $this->snapshot();
        $receipt = new CompanyProfileSyncReceipt(
            idempotencyKey: 'sync-key-replay',
            requestDigest: $service->requestDigest($incoming),
            fiscalDigest: $incoming->fiscalDigest,
            profileVersion: 1,
            recordedAt: new DateTimeImmutable('2026-10-08T00:00:00+00:00'),
        );

        $decision = $service->syncDecision($incoming, $incoming, 1, 'sync-key-replay', $receipt);

        $this->assertSame(CompanyProfileSyncDecision::REPLAYED, $decision->status);
        $this->assertTrue($decision->replayed);
        $this->assertSame(1, $decision->profileVersion);
    }

    public function test_idempotency_key_with_different_payload_conflicts(): void
    {
        $service = $this->service();
        $original = $this->snapshot();
        $different = $this->snapshot(fiscalRevision: 7);
        $receipt = new CompanyProfileSyncReceipt(
            idempotencyKey: 'sync-key-conflict',
            requestDigest: $service->requestDigest($original),
            fiscalDigest: $original->fiscalDigest,
            profileVersion: 1,
            recordedAt: new DateTimeImmutable('2026-10-08T00:00:00+00:00'),
        );

        $decision = $service->syncDecision($original, $different, 1, 'sync-key-conflict', $receipt);

        $this->assertSame(CompanyProfileSyncDecision::IDEMPOTENCY_CONFLICT, $decision->status);
        $this->assertSame('idempotency_key_reused_with_different_payload', $decision->reason);
    }

    public function test_snapshot_payload_has_no_secret_material(): void
    {
        $snapshot = $this->snapshot();

        $forbidden = ['sol_password', 'password', 'passphrase', 'certificate_bytes', 'sol_username'];
        $flatKeys = array_keys($snapshot->toArray());

        foreach ($forbidden as $key) {
            $this->assertNotContains($key, $flatKeys);
        }

        $encoded = json_encode($snapshot->toArray(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString(self::SYNTHETIC_CERTIFICATE, $encoded);
        $this->assertStringNotContainsString('MODDATOS', $encoded);
        $this->assertStringContainsString('MO***S', $encoded);
    }
}
