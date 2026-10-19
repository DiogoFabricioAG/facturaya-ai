<?php

namespace App\Data;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Fotografía pública e independiente del perfil fiscal de una empresa.
 *
 * Se calcula server-side desde la fila `companies` (nunca desde el cuerpo de
 * una request) y no contiene secretos: no expone usuario SOL completo, ni
 * contraseñas, ni bytes del certificado. El `fiscal_digest` resume el perfil
 * para detectar cambios que invalidan evidencia previa.
 */
final readonly class CompanyProfileSnapshot
{
    public const ENVIRONMENTS = ['beta', 'production'];

    /**
     * Techo defensivo de versión: acota payloads y la columna entera sin signo.
     * Las versiones solo avanzan con cambios fiscales reales (poco frecuentes),
     * por lo que un millón excede cualquier vida operativa razonable; al
     * alcanzarlo el servicio exige intervención en lugar de desbordar.
     */
    public const MAX_PROFILE_VERSION = 1_000_000;

    private const ULID_PATTERN = '/^[0-9A-HJKMNP-TV-Z]{26}$/i';

    private const RUC_PATTERN = '/^[0-9]{11}$/';

    private const SHA256_PATTERN = '/^[0-9a-f]{64}$/';

    public function __construct(
        public string $companyRef,
        public string $environment,
        public int $profileVersion,
        public string $fiscalDigest,
        public string $certificateSha256,
        public string $ruc,
        public string $solUsernameMasked,
        public DateTimeImmutable $capturedAt,
    ) {
        if (preg_match(self::ULID_PATTERN, $this->companyRef) !== 1) {
            throw new InvalidArgumentException('company_profile_invalid_company_ref');
        }

        if (! in_array($this->environment, self::ENVIRONMENTS, true)) {
            throw new InvalidArgumentException('company_profile_invalid_environment');
        }

        if ($this->profileVersion < 1 || $this->profileVersion > self::MAX_PROFILE_VERSION) {
            throw new InvalidArgumentException('company_profile_invalid_version');
        }

        if (preg_match(self::SHA256_PATTERN, $this->fiscalDigest) !== 1) {
            throw new InvalidArgumentException('company_profile_invalid_fiscal_digest');
        }

        if (preg_match(self::SHA256_PATTERN, $this->certificateSha256) !== 1) {
            throw new InvalidArgumentException('company_profile_invalid_certificate_fingerprint');
        }

        if (preg_match(self::RUC_PATTERN, $this->ruc) !== 1) {
            throw new InvalidArgumentException('company_profile_invalid_ruc');
        }

        if ($this->solUsernameMasked === '' || mb_strlen($this->solUsernameMasked) > 40) {
            throw new InvalidArgumentException('company_profile_invalid_masked_username');
        }

        if ($this->capturedAt->getTimezone() === false) {
            throw new InvalidArgumentException('company_profile_invalid_capture_time');
        }
    }

    /**
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        return [
            'company_ref' => $this->companyRef,
            'environment' => $this->environment,
            'profile_version' => $this->profileVersion,
            'fiscal_digest' => $this->fiscalDigest,
            'certificate_sha256' => $this->certificateSha256,
            'ruc' => $this->ruc,
            'sol_username_masked' => $this->solUsernameMasked,
            'captured_at' => $this->capturedAt->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
        ];
    }
}
