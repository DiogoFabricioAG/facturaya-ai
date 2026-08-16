<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

final class CompanyCertificateStore
{
    public function store(
        Company $company,
        UploadedFile $file,
        #[\SensitiveParameter] string $password,
    ): string {
        $contents = file_get_contents($file->getRealPath());

        if ($contents === false) {
            throw ValidationException::withMessages(['certificate' => 'No se pudo leer el certificado.']);
        }

        $pem = $this->convertPkcs12ToPem($contents, $password);

        $path = 'company-certificates/'.$company->id.'/certificate.pem.enc';
        Storage::disk('local')->put($path, Crypt::encryptString($pem));

        return $path;
    }

    public function read(Company $company): string
    {
        if (! $company->certificate_path || ! Storage::disk('local')->exists($company->certificate_path)) {
            throw new RuntimeException('La empresa no tiene un certificado digital disponible.');
        }

        return Crypt::decryptString(Storage::disk('local')->get($company->certificate_path));
    }

    private function convertPkcs12ToPem(string $contents, #[\SensitiveParameter] string $password): string
    {
        $this->clearOpenSslErrors();
        $certificates = [];

        if (! @openssl_pkcs12_read($contents, $certificates, $password)) {
            $this->clearOpenSslErrors();
            $certificates = $this->readLegacyPkcs12($contents, $password);

            if ($certificates === null) {
                throw ValidationException::withMessages([
                    'certificate_password' => 'No pudimos abrir el certificado. Revisa la contraseña y confirma que sea un archivo .p12 o .pfx válido.',
                ]);
            }
        }

        $certificate = $certificates['cert'] ?? null;
        $privateKey = $certificates['pkey'] ?? null;

        if (! is_string($certificate) || ! is_string($privateKey)) {
            throw ValidationException::withMessages([
                'certificate' => 'El archivo debe incluir el certificado de la empresa y su clave privada.',
            ]);
        }

        if (! openssl_x509_check_private_key($certificate, $privateKey)) {
            throw ValidationException::withMessages([
                'certificate' => 'La clave privada incluida no corresponde al certificado digital.',
            ]);
        }

        $details = openssl_x509_parse($certificate);

        if (! is_array($details)) {
            throw ValidationException::withMessages([
                'certificate' => 'No pudimos leer los datos del certificado digital.',
            ]);
        }

        $now = time();

        if (isset($details['validTo_time_t']) && $details['validTo_time_t'] < $now) {
            throw ValidationException::withMessages([
                'certificate' => 'El certificado digital está vencido. Solicita o renueva uno antes de activar SUNAT.',
            ]);
        }

        if (isset($details['validFrom_time_t']) && $details['validFrom_time_t'] > $now) {
            throw ValidationException::withMessages([
                'certificate' => 'El certificado digital todavía no está vigente.',
            ]);
        }

        return trim($privateKey).PHP_EOL.trim($certificate).PHP_EOL;
    }

    /**
     * OpenSSL 3 deshabilita por defecto algunos cifrados usados por archivos
     * PKCS#12 antiguos. El fallback activa compatibilidad legacy solo en este
     * proceso aislado; la contraseña viaja por stdin y nunca por argumentos.
     *
     * @return array{cert: string, pkey: string}|null
     */
    private function readLegacyPkcs12(string $contents, #[\SensitiveParameter] string $password): ?array
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'facturaya-p12-');

        if ($temporaryPath === false) {
            return null;
        }

        try {
            @chmod($temporaryPath, 0600);

            if (file_put_contents($temporaryPath, $contents, LOCK_EX) === false) {
                return null;
            }

            $process = new Process([
                'openssl',
                'pkcs12',
                '-legacy',
                '-in',
                $temporaryPath,
                '-nodes',
                '-passin',
                'stdin',
            ]);
            $process->setTimeout(10);
            $process->setInput($password);
            $process->run();

            if (! $process->isSuccessful()) {
                return null;
            }

            $pem = $process->getOutput();
            $certificateMatch = [];
            $privateKeyMatch = [];

            if (preg_match('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $pem, $certificateMatch) !== 1) {
                return null;
            }

            if (preg_match('/-----BEGIN (?:[A-Z0-9]+ )?PRIVATE KEY-----.*?-----END (?:[A-Z0-9]+ )?PRIVATE KEY-----/s', $pem, $privateKeyMatch) !== 1) {
                return null;
            }

            return [
                'cert' => $certificateMatch[0],
                'pkey' => $privateKeyMatch[0],
            ];
        } catch (Throwable) {
            return null;
        } finally {
            @unlink($temporaryPath);
        }
    }

    private function clearOpenSslErrors(): void
    {
        while (openssl_error_string() !== false) {
            // Evita que errores previos de OpenSSL contaminen el siguiente intento.
        }
    }
}
