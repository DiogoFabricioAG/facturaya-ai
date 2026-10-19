<?php

namespace App\Data;

use InvalidArgumentException;

/**
 * Evidencia técnica aditiva del diagnóstico fiscal no emisor (schema_version 1).
 *
 * Separa explícitamente lo comprobado técnicamente por este servicio de la
 * aceptación fiscal de SUNAT: la firma local se etiqueta `local_engine_signature`
 * y solo el bloque `credentials` puede originarse en una consulta SUNAT no
 * emisora. No declara readiness completo, no sustituye la revisión guiada de
 * permisos ni el juicio del orquestador, no incluye secretos y nunca acredita
 * emisión. Es un contrato aditivo: el envelope legacy del endpoint se conserva.
 */
final readonly class FiscalTechnicalEvidence
{
    public const SCHEMA_VERSION = 1;
    public const BOUND_SCHEMA_VERSION = 2;

    public const SOURCE = 'facturaya_non_issuing_diagnostics';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_PENDING = 'pending';

    public const STATUS_REJECTED = 'rejected';

    public const ENVIRONMENT_BETA = 'beta';

    public const ENVIRONMENT_PRODUCTION = 'production';

    private const STATUSES = [self::STATUS_VERIFIED, self::STATUS_PENDING, self::STATUS_REJECTED];

    private const ENVIRONMENTS = [self::ENVIRONMENT_BETA, self::ENVIRONMENT_PRODUCTION];

    /**
     * @param  array{status: string, source: string, environment: string, reason: ?string}  $credentials
     * @param  array{status: string, source: string, reason: ?string}  $certificate
     * @param  array{status: string, source: string, reason: ?string}  $signature
     * @param  array{status: string, source: string, reason: ?string}  $trust
     */
    public function __construct(
        public array $credentials,
        public array $certificate,
        public array $signature,
        public array $trust,
        public ?string $certificateDerSha256 = null,
    ) {
        $this->assertBlock($credentials, ['status', 'source', 'environment', 'reason']);
        $this->assertBlock($certificate, ['status', 'source', 'reason']);
        $this->assertBlock($signature, ['status', 'source', 'reason']);
        $this->assertBlock($trust, ['status', 'source', 'reason']);

        if (! in_array($credentials['environment'], self::ENVIRONMENTS, true)) {
            throw new InvalidArgumentException('fiscal_technical_evidence_invalid_environment');
        }
        if ($certificateDerSha256 !== null && preg_match('/\A[a-f0-9]{64}\z/D', $certificateDerSha256) !== 1) {
            throw new InvalidArgumentException('fiscal_technical_evidence_invalid_certificate_binding');
        }
    }

    /**
     * @return array{
     *     schema_version: int,
     *     source: string,
     *     credentials: array{status: string, source: string, environment: string, reason: ?string},
     *     certificate: array{status: string, source: string, reason: ?string},
     *     signature: array{status: string, source: string, reason: ?string},
     *     trust: array{status: string, source: string, reason: ?string}
     * }
     */
    public function toArray(): array
    {
        $value = [
            'schema_version' => $this->certificateDerSha256 === null ? self::SCHEMA_VERSION : self::BOUND_SCHEMA_VERSION,
            'source' => self::SOURCE,
            'credentials' => $this->credentials,
            'certificate' => $this->certificate,
            'signature' => $this->signature,
            'trust' => $this->trust,
        ];
        if ($this->certificateDerSha256 !== null) {
            $value['certificate_der_sha256'] = $this->certificateDerSha256;
        }
        return $value;
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  list<string>  $expectedKeys
     */
    private function assertBlock(array $block, array $expectedKeys): void
    {
        $keys = array_map('strval', array_keys($block));
        sort($keys);
        sort($expectedKeys);

        if ($keys !== $expectedKeys) {
            throw new InvalidArgumentException('fiscal_technical_evidence_invalid_block');
        }

        if (! in_array($block['status'], self::STATUSES, true)) {
            throw new InvalidArgumentException('fiscal_technical_evidence_invalid_status');
        }

        if (! is_string($block['source']) || $block['source'] === '') {
            throw new InvalidArgumentException('fiscal_technical_evidence_invalid_source');
        }

        if ($block['reason'] !== null && (! is_string($block['reason']) || $block['reason'] === '')) {
            throw new InvalidArgumentException('fiscal_technical_evidence_invalid_reason');
        }
    }
}
