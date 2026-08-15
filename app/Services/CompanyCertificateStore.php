<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

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

            throw ValidationException::withMessages([
                'certificate_password' => 'No pudimos abrir el certificado. Revisa la contraseña y confirma que sea un archivo .p12 o .pfx válido.',
            ]);
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

    private function clearOpenSslErrors(): void
    {
        while (openssl_error_string() !== false) {
            // Evita que errores previos de OpenSSL contaminen el siguiente intento.
        }
    }
}
