<?php

namespace App\Data;

use InvalidArgumentException;

/**
 * Entrada efímera del diagnóstico fiscal backend.
 *
 * Transporta el perfil de facturación (referencia, versión de configuración y
 * ambiente) junto con credenciales secundarias y el certificado en memoria.
 * Nunca se persiste, ni se registra, ni se devuelve al navegador: el llamador
 * de backend la construye desde la bóveda (Python) o desde el perfil cifrado
 * (PHP) y la descarta al terminar el diagnóstico.
 */
final readonly class FiscalProfileDiagnosticInput
{
    public const ENVIRONMENT_BETA = 'beta';

    public const ENVIRONMENT_PRODUCTION = 'production';

    public const MAX_CERTIFICATE_BYTES = 65_536;

    public const MAX_PEM_BYTES = 262_144;

    public const MAX_SOL_PASSWORD_LENGTH = 128;

    private const PROFILE_REFERENCE_PATTERN = '/^[A-Za-z0-9._-]{1,128}$/';

    private const RUC_PATTERN = '/^[0-9]{11}$/';

    private const SOL_USERNAME_PATTERN = '/^[A-Za-z0-9_.-]{4,32}$/';

    public function __construct(
        public string $profileReference,
        public int $configVersion,
        public string $environment,
        public string $issuerRuc,
        public string $solUsername,
        #[\SensitiveParameter] private string $solPassword,
        #[\SensitiveParameter] private ?string $certificatePem = null,
        #[\SensitiveParameter] private ?string $pkcs12Contents = null,
        #[\SensitiveParameter] private string $pkcs12Passphrase = '',
    ) {
        if (preg_match(self::PROFILE_REFERENCE_PATTERN, $this->profileReference) !== 1) {
            throw new InvalidArgumentException('fiscal_diagnostics_invalid_profile_reference');
        }

        if ($this->configVersion < 1 || $this->configVersion > 1_000_000) {
            throw new InvalidArgumentException('fiscal_diagnostics_invalid_config_version');
        }

        if (! in_array($this->environment, [self::ENVIRONMENT_BETA, self::ENVIRONMENT_PRODUCTION], true)) {
            throw new InvalidArgumentException('fiscal_diagnostics_invalid_environment');
        }

        if (preg_match(self::RUC_PATTERN, $this->issuerRuc) !== 1) {
            throw new InvalidArgumentException('fiscal_diagnostics_invalid_issuer_ruc');
        }

        if (preg_match(self::SOL_USERNAME_PATTERN, $this->solUsername) !== 1) {
            throw new InvalidArgumentException('fiscal_diagnostics_invalid_sol_username');
        }

        if ($this->solPassword === '' || strlen($this->solPassword) > self::MAX_SOL_PASSWORD_LENGTH) {
            throw new InvalidArgumentException('fiscal_diagnostics_invalid_sol_password');
        }

        $hasPem = $this->certificatePem !== null && $this->certificatePem !== '';
        $hasPkcs12 = $this->pkcs12Contents !== null && $this->pkcs12Contents !== '';

        if ($hasPem === $hasPkcs12) {
            throw new InvalidArgumentException('fiscal_diagnostics_certificate_source_required');
        }

        if ($hasPem && strlen($this->certificatePem) > self::MAX_PEM_BYTES) {
            throw new InvalidArgumentException('fiscal_diagnostics_certificate_too_large');
        }

        if ($hasPkcs12 && (strlen($this->pkcs12Contents) < 64 || strlen($this->pkcs12Contents) > self::MAX_CERTIFICATE_BYTES)) {
            throw new InvalidArgumentException('fiscal_diagnostics_certificate_too_large');
        }
    }

    public function usesPkcs12(): bool
    {
        return $this->pkcs12Contents !== null && $this->pkcs12Contents !== '';
    }

    public function solPassword(): string
    {
        return $this->solPassword;
    }

    public function certificatePem(): ?string
    {
        return $this->certificatePem;
    }

    public function pkcs12Contents(): ?string
    {
        return $this->pkcs12Contents;
    }

    public function pkcs12Passphrase(): string
    {
        return $this->pkcs12Passphrase;
    }

    /**
     * @return array<string, int|string|null>
     */
    public function __debugInfo(): array
    {
        return [
            'profileReference' => $this->profileReference,
            'configVersion' => $this->configVersion,
            'environment' => $this->environment,
            'issuerRuc' => $this->issuerRuc,
            'solUsername' => $this->solUsername,
            'solPassword' => '***',
            'certificatePem' => $this->certificatePem === null ? null : '[redacted]',
            'pkcs12Contents' => $this->pkcs12Contents === null ? null : '[redacted]',
            'pkcs12Passphrase' => '***',
        ];
    }
}
