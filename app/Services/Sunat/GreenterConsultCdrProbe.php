<?php

namespace App\Services\Sunat;

use App\Data\FiscalExternalProbeOutcome;
use App\Data\FiscalProfileDiagnosticInput;
use DateTimeImmutable;
use DateTimeZone;
use Greenter\Ws\Services\SoapClient;
use Greenter\Ws\Services\SunatEndpoints;
use InvalidArgumentException;
use SoapFault;
use Throwable;

/**
 * Comprobación externa no emisora mediante la consulta oficial de CDR
 * (`getStatusCdr` del billConsultService).
 *
 * No envía comprobantes ni produce efectos tributarios: solo invoca la consulta
 * de un comprobante sintáctico que no debe existir. La llamada se hace
 * directamente sobre el cliente SOAP inyectado (misma forma de respuesta que
 * `ConsultCdrService` de Greenter) para conservar la procedencia del resultado:
 *
 * - Respuesta de negocio con CDR presente o con el código de «comprobante no
 *   existe» (`1033`): la solicitud fue autenticada y procesada por SUNAT, por
 *   lo que acredita credenciales aceptadas (`authentication_accepted`).
 * - Cualquier otro código de negocio queda `response_unclassified`: se conserva
 *   el código para validarlo contra SUNAT real antes de interpretarlo.
 * - `SoapFault` de autenticación/permisos (401, patrones de usuario/contraseña
 *   o «no autorizado») → `authentication_rejected`.
 * - `SoapFault` con faultcode `HTTP` o de conexión → `transport_error`; otros
 *   faults → `response_unclassified` (el servicio respondió, pero no acredita
 *   nada por sí solo).
 *
 * La clasificación es conservadora y provisional hasta validarse con una
 * consulta real autorizada (usuario secundario + certificado del dashboard).
 * NO acredita cadena CA, revocación, permisos de emisión, firma XMLDSig ni
 * aceptación de comprobantes.
 */
final class GreenterConsultCdrProbe implements SunatNonIssuingProbe
{
    private const CONSULT_METHOD = 'getStatusCdr';

    private const CONSULT_RESULT_PROPERTY = 'statusCdr';

    /** Código de negocio documentado para comprobante no encontrado. */
    private const COMPROBANTE_NOT_FOUND_CODE = '1033';

    private const AUTH_PATTERNS = [
        '/unauthorized/i',
        '/no\s+autorizado/i',
        '/usuario\s+o\s+contrase/i',
        '/credenciales/i',
        '/no\s+tiene\s+permiso/i',
        '/usuario\s+no\s+v[aá]lido/i',
    ];

    public function __construct(
        private readonly SoapClient $client,
        private readonly bool $transportTrusted = false,
        private readonly string $documentType = '01',
        private readonly string $series = 'F001',
        private readonly int $correlative = 1,
    ) {
        if (preg_match('/^[0-9]{2}$/', $this->documentType) !== 1) {
            throw new InvalidArgumentException('invalid_consult_document_type');
        }

        if (preg_match('/^[A-Za-z0-9]{1,4}$/', $this->series) !== 1) {
            throw new InvalidArgumentException('invalid_consult_series');
        }

        if ($this->correlative < 1) {
            throw new InvalidArgumentException('invalid_consult_correlative');
        }
    }

    public function probe(FiscalProfileDiagnosticInput $input): FiscalExternalProbeOutcome
    {
        if ($input->environment !== FiscalProfileDiagnosticInput::ENVIRONMENT_PRODUCTION) {
            return new FiscalExternalProbeOutcome(
                outcome: FiscalExternalProbeOutcome::OUTCOME_ENVIRONMENT_UNSUPPORTED,
                transportTrusted: false,
                occurredAt: $this->now(),
            );
        }

        try {
            $this->client->setCredentials(
                $input->issuerRuc.$input->solUsername,
                $input->solPassword(),
            );
            $this->client->setService(SunatEndpoints::FE_CONSULTA_CDR);

            $response = $this->client->call(self::CONSULT_METHOD, [
                'parameters' => [
                    'rucComprobante' => $input->issuerRuc,
                    'tipoComprobante' => $this->documentType,
                    'serieComprobante' => $this->series,
                    'numeroComprobante' => $this->correlative,
                ],
            ]);

            return $this->interpretBusinessResponse($response);
        } catch (SoapFault $fault) {
            return $this->interpretSoapFault($fault);
        } catch (Throwable) {
            return $this->outcome(
                FiscalExternalProbeOutcome::OUTCOME_TRANSPORT_ERROR,
            );
        } finally {
            unset($response);
        }
    }

    private function interpretBusinessResponse(mixed $response): FiscalExternalProbeOutcome
    {
        $status = is_object($response)
            ? ($response->{self::CONSULT_RESULT_PROPERTY} ?? null)
            : null;

        if (! is_object($status)) {
            return $this->outcome(
                FiscalExternalProbeOutcome::OUTCOME_RESPONSE_UNCLASSIFIED,
                code: 'missing_status_payload',
            );
        }

        $rawCode = property_exists($status, 'statusCode') && is_scalar($status->statusCode)
            ? trim((string) $status->statusCode)
            : '';
        $message = $this->sanitize(
            property_exists($status, 'statusMessage') && is_scalar($status->statusMessage)
                ? (string) $status->statusMessage
                : null,
        );
        $hasContent = property_exists($status, 'content')
            && is_string($status->content)
            && $status->content !== '';

        if ($rawCode === '' || preg_match('/^[0-9]{1,5}$/', $rawCode) !== 1) {
            return $this->outcome(
                FiscalExternalProbeOutcome::OUTCOME_RESPONSE_UNCLASSIFIED,
                code: $rawCode === '' ? 'missing_status_code' : $rawCode,
                message: $message,
            );
        }

        $numericCode = (int) $rawCode;

        // Evidencia positiva: el CDR existe o SUNAT devolvió el código de
        // negocio de comprobante inexistente; en ambos casos la solicitud
        // autenticada fue procesada. El resto queda sin clasificar hasta
        // validarse con SUNAT real.
        if ($hasContent || $rawCode === self::COMPROBANTE_NOT_FOUND_CODE || $numericCode === 0) {
            return $this->outcome(
                FiscalExternalProbeOutcome::OUTCOME_AUTHENTICATION_ACCEPTED,
                code: $rawCode,
                message: $message,
            );
        }

        return $this->outcome(
            FiscalExternalProbeOutcome::OUTCOME_RESPONSE_UNCLASSIFIED,
            code: $rawCode,
            message: $message,
        );
    }

    private function interpretSoapFault(SoapFault $fault): FiscalExternalProbeOutcome
    {
        $faultCode = is_string($fault->faultcode) ? $fault->faultcode : '';
        $faultMessage = is_string($fault->faultstring) ? $fault->faultstring : '';
        $digits = preg_replace('/[^0-9]+/', '', $faultCode);

        if (strcasecmp($faultCode, 'HTTP') === 0 || $this->isConnectionFailure($faultMessage)) {
            return $this->outcome(
                FiscalExternalProbeOutcome::OUTCOME_TRANSPORT_ERROR,
                code: $faultCode === '' ? null : $faultCode,
                message: $this->sanitize($faultMessage),
            );
        }

        if (
            $digits === '401'
            || $this->matchesAnyPattern($faultMessage, self::AUTH_PATTERNS)
        ) {
            return $this->outcome(
                FiscalExternalProbeOutcome::OUTCOME_AUTHENTICATION_REJECTED,
                code: $digits === '' ? null : $digits,
                message: $this->sanitize($faultMessage),
            );
        }

        return $this->outcome(
            FiscalExternalProbeOutcome::OUTCOME_RESPONSE_UNCLASSIFIED,
            code: $this->sanitizeFaultCode($digits === '' ? $faultCode : $digits),
            message: $this->sanitize($faultMessage),
        );
    }

    /**
     * El contrato de código SUNAT solo admite `[A-Za-z0-9._-]` (máx. 32); los
     * faultcode SOAP suelen incluir prefijos con `:` que no deben filtrarse.
     */
    private function sanitizeFaultCode(string $code): ?string
    {
        if ($code === '') {
            return null;
        }

        $clean = preg_replace('/[^A-Za-z0-9._-]+/', '_', $code);
        $clean = $clean === null ? '' : mb_substr(trim($clean, '._-'), 0, 32);

        return $clean === '' ? null : $clean;
    }

    private function isConnectionFailure(string $message): bool
    {
        return $this->matchesAnyPattern($message, [
            '/could\s+not\s+connect/i',
            '/failed\s+to\s+connect/i',
            '/connection\s+(refused|reset|timed?\s*out)/i',
            '/no\s+se\s+pudo\s+conectar/i',
        ]);
    }

    /**
     * @param  list<string>  $patterns
     */
    private function matchesAnyPattern(string $value, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return true;
            }
        }

        return false;
    }

    private function outcome(string $outcome, ?string $code = null, ?string $message = null): FiscalExternalProbeOutcome
    {
        return new FiscalExternalProbeOutcome(
            outcome: $outcome,
            transportTrusted: $this->transportTrusted,
            endpointHost: $this->endpointHost(),
            sunatCode: $code,
            sunatMessage: $message,
            occurredAt: $this->now(),
        );
    }

    private function endpointHost(): ?string
    {
        $host = parse_url(SunatEndpoints::FE_CONSULTA_CDR, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }

    private function sanitize(?string $message): ?string
    {
        if ($message === null || trim($message) === '') {
            return null;
        }

        $plain = preg_replace('/\s+/', ' ', strip_tags($message));

        return $plain === null
            ? null
            : mb_substr(trim($plain), 0, FiscalExternalProbeOutcome::MAX_SUNAT_MESSAGE_LENGTH);
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM);
    }
}
