<?php

namespace App\Services\Sunat;

use Greenter\Ws\Services\SoapClient;
use RuntimeException;

/**
 * Cliente SOAP de consulta CDR con TLS verificado por el servidor.
 *
 * - `verify_peer`/`verify_peer_name` activos y sin autofirmados.
 * - CA: bundle explícito de infraestructura o, si no se configura, el almacén
 *   CA por defecto del servidor, validado por existencia. Nunca se degrada a
 *   verificación deshabilitada.
 * - WSDL local de Greenter (`WsdlProvider::getConsultPath()` por defecto); no
 *   se descarga WSDL remoto.
 * - Sin `trace` (no retiene cuerpos con credenciales).
 * - Límites de tiempo honestos: `connection_timeout` acota solo el
 *   establecimiento de conexión y no las respuestas lentas; la espera de
 *   lectura depende de `default_socket_timeout` (INI) según el manual oficial
 *   de SoapClient. La opción `timeout` del contexto HTTP no figura entre las
 *   opciones que SOAP respeta, así que no se simula con ella. En lugar de
 *   mutar INI global, se valida que `default_socket_timeout` esté entre 1 y el
 *   máximo configurado; incluso así el tiempo total no está garantizado
 *   (una llamada puede encadenar varias lecturas).
 * - Constructor sin red: solo valida archivos locales y configura el cliente.
 */
final class VerifiedConsultSoapClient extends SoapClient
{
    public function __construct(
        string $wsdlPath,
        ?string $caBundlePath = null,
        int $connectionTimeoutSeconds = 10,
        int $maxReadTimeoutSeconds = 15,
    ) {
        if ($wsdlPath === '' || ! is_file($wsdlPath) || ! is_readable($wsdlPath)) {
            throw new RuntimeException('fiscal_diagnostics_consult_wsdl_unavailable');
        }

        if ($caBundlePath !== null) {
            if ($caBundlePath === '' || ! is_file($caBundlePath) || ! is_readable($caBundlePath)) {
                throw new RuntimeException('fiscal_diagnostics_consult_ca_unavailable');
            }
        } else {
            $this->assertSystemCaStoreAvailable();
        }

        if ($connectionTimeoutSeconds < 1 || $connectionTimeoutSeconds > 30) {
            throw new RuntimeException('fiscal_diagnostics_consult_timeout_invalid');
        }

        if ($maxReadTimeoutSeconds < 1 || $maxReadTimeoutSeconds > 30) {
            throw new RuntimeException('fiscal_diagnostics_consult_read_timeout_invalid');
        }

        $this->assertReadTimeoutBounded($maxReadTimeoutSeconds);

        $ssl = [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'SNI_enabled' => true,
        ];

        if ($caBundlePath !== null) {
            $ssl['cafile'] = $caBundlePath;
        }

        parent::__construct($wsdlPath, [
            'trace' => false,
            'exceptions' => true,
            'connection_timeout' => $connectionTimeoutSeconds,
            'cache_wsdl' => WSDL_CACHE_NONE,
            'stream_context' => stream_context_create(['ssl' => $ssl]),
        ]);
    }

    private function assertReadTimeoutBounded(int $maxReadTimeoutSeconds): void
    {
        $configured = ini_get('default_socket_timeout');

        if (! is_string($configured) || ! ctype_digit(trim($configured))) {
            throw new RuntimeException('fiscal_diagnostics_consult_read_timeout_unbounded');
        }

        $value = (int) trim($configured);

        if ($value < 1 || $value > $maxReadTimeoutSeconds) {
            throw new RuntimeException('fiscal_diagnostics_consult_read_timeout_unbounded');
        }
    }

    private function assertSystemCaStoreAvailable(): void
    {
        $locations = openssl_get_cert_locations();
        $defaultFile = $locations['default_cert_file'] ?? null;
        $defaultDir = $locations['default_cert_dir'] ?? null;
        $iniCaFile = ini_get('openssl.cafile') ?: null;
        $iniCaPath = ini_get('openssl.capath') ?: null;

        foreach ([$iniCaFile, $defaultFile] as $candidate) {
            if (is_string($candidate) && $candidate !== '' && is_file($candidate)) {
                return;
            }
        }

        foreach ([$iniCaPath, $defaultDir] as $candidate) {
            if (is_string($candidate) && $candidate !== '' && is_dir($candidate)) {
                return;
            }
        }

        throw new RuntimeException('fiscal_diagnostics_consult_ca_unavailable');
    }
}
