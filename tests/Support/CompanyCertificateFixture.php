<?php

namespace Tests\Support;

use App\Models\Company;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Certificado sintético para pruebas: genera un par RSA autofirmado en
 * memoria, guarda el PEM cifrado como lo haría la carga real y devuelve la
 * ruta. Nunca usa certificados ni claves reales.
 */
final class CompanyCertificateFixture
{
    public static function storeFor(Company $company): string
    {
        [$combinedPem] = self::generatePem();
        $path = 'company-certificates/'.$company->id.'/certificate.pem.enc';

        Storage::disk('local')->put($path, Crypt::encryptString($combinedPem));

        return $path;
    }

    /**
     * @return array{0: string, 1: string} PEM combinado (clave+cert) y solo certificado
     */
    public static function generatePem(): array
    {
        [$combined, $certificatePem] = self::generatePemMatching();

        return [$combined, $certificatePem];
    }

    /**
     * PKCS#12 sintético reutilizable para probar la recarga del certificado.
     *
     * @return array{0: string, 1: string} contenido binario y contraseña
     */
    public static function makePkcs12(string $password): array
    {
        [$combined, $certificatePem] = self::generatePemMatching();
        $keyPem = explode('-----BEGIN CERTIFICATE-----', $combined)[0];

        $pkcs12 = '';

        if (! @openssl_pkcs12_export($certificatePem, $pkcs12, trim($keyPem), $password)) {
            throw new RuntimeException('fixture_pkcs12_export_failed');
        }

        return [$pkcs12, $password];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function generatePemMatching(): array
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($key === false) {
            throw new RuntimeException('fixture_key_generation_failed');
        }

        $csr = openssl_csr_new(['commonName' => 'perfil-fiscal-sintetico'], $key, ['digest_alg' => 'sha256']);
        $certificate = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);

        if ($certificate === false) {
            throw new RuntimeException('fixture_certificate_generation_failed');
        }

        openssl_x509_export($certificate, $certificatePem);
        openssl_pkey_export($key, $keyPem);

        return [
            trim((string) $keyPem).PHP_EOL.trim((string) $certificatePem).PHP_EOL,
            trim((string) $certificatePem),
        ];
    }
}
