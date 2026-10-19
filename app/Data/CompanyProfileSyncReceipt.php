<?php

namespace App\Data;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Recibo durable de una sincronización previa, usado para idempotencia.
 *
 * Guarda la clave de idempotencia, la huella de la carga y el resultado
 * aplicado. Reintentar con la misma clave y la misma huella devuelve el
 * resultado original sin avanzar versión; con huella distinta es conflicto.
 */
final readonly class CompanyProfileSyncReceipt
{
    private const IDEMPOTENCY_PATTERN = '/^[A-Za-z0-9._-]{1,128}$/';

    private const SHA256_PATTERN = '/^[0-9a-f]{64}$/';

    public function __construct(
        public string $idempotencyKey,
        public string $requestDigest,
        public string $fiscalDigest,
        public int $profileVersion,
        public DateTimeImmutable $recordedAt,
    ) {
        if (preg_match(self::IDEMPOTENCY_PATTERN, $this->idempotencyKey) !== 1) {
            throw new InvalidArgumentException('company_profile_invalid_idempotency_key');
        }

        if (preg_match(self::SHA256_PATTERN, $this->requestDigest) !== 1) {
            throw new InvalidArgumentException('company_profile_invalid_request_digest');
        }

        if (preg_match(self::SHA256_PATTERN, $this->fiscalDigest) !== 1) {
            throw new InvalidArgumentException('company_profile_invalid_fiscal_digest');
        }

        if ($this->profileVersion < 1 || $this->profileVersion > CompanyProfileSnapshot::MAX_PROFILE_VERSION) {
            throw new InvalidArgumentException('company_profile_invalid_receipt_version');
        }

        if ($this->recordedAt->getTimezone() === false) {
            throw new InvalidArgumentException('company_profile_invalid_receipt_time');
        }
    }

    /**
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        return [
            'idempotency_key' => $this->idempotencyKey,
            'request_digest' => $this->requestDigest,
            'fiscal_digest' => $this->fiscalDigest,
            'profile_version' => $this->profileVersion,
            'recorded_at' => $this->recordedAt->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
        ];
    }
}
