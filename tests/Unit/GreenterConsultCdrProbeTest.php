<?php

namespace Tests\Unit;

use App\Data\FiscalExternalProbeOutcome;
use App\Data\FiscalProfileDiagnosticInput;
use App\Services\Sunat\GreenterConsultCdrProbe;
use Greenter\Ws\Services\SoapClient;
use Greenter\Ws\Services\SunatEndpoints;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SoapFault;
use stdClass;

final class GreenterConsultCdrProbeTest extends TestCase
{
    private function input(string $environment = FiscalProfileDiagnosticInput::ENVIRONMENT_PRODUCTION): FiscalProfileDiagnosticInput
    {
        return new FiscalProfileDiagnosticInput(
            profileReference: 'tenant-ref-diagnostics',
            configVersion: 3,
            environment: $environment,
            issuerRuc: '20123456789',
            solUsername: 'USUARIO1',
            solPassword: 'synthetic-sol-secret',
            certificatePem: "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----\n",
        );
    }

    /**
     * Cliente SOAP falso: no llama al constructor de red y permite inyectar la
     * respuesta o el fallo exacto de cada caso.
     */
    private function client(callable $call): SoapClient
    {
        return new class($call) extends SoapClient
        {
            public function __construct(private $handler) {}

            public function setCredentials(?string $user, ?string $password) {}

            public function setService(?string $url) {}

            public function call($function, $arguments)
            {
                return ($this->handler)($function, $arguments);
            }
        };
    }

    private function probe(callable $call, bool $trusted = true): GreenterConsultCdrProbe
    {
        return new GreenterConsultCdrProbe(client: $this->client($call), transportTrusted: $trusted);
    }

    public function test_business_not_found_code_proves_authenticated_request(): void
    {
        $probe = $this->probe(fn () => (object) [
            'statusCdr' => (object) [
                'statusCode' => '1033',
                'statusMessage' => 'El comprobante no existe',
            ],
        ]);

        $outcome = $probe->probe($this->input());

        self::assertSame(FiscalExternalProbeOutcome::OUTCOME_AUTHENTICATION_ACCEPTED, $outcome->outcome);
        self::assertTrue($outcome->transportTrusted);
        self::assertSame('1033', $outcome->sunatCode);
        self::assertSame(
            parse_url(SunatEndpoints::FE_CONSULTA_CDR, PHP_URL_HOST),
            $outcome->endpointHost,
        );
    }

    public function test_existing_document_with_cdr_content_is_accepted(): void
    {
        $probe = $this->probe(fn () => (object) [
            'statusCdr' => (object) [
                'statusCode' => '0001',
                'statusMessage' => 'La consulta fue procesada',
                'content' => 'zip-bytes',
            ],
        ]);

        $outcome = $probe->probe($this->input());

        self::assertSame(FiscalExternalProbeOutcome::OUTCOME_AUTHENTICATION_ACCEPTED, $outcome->outcome);
    }

    public function test_unknown_business_code_stays_unclassified(): void
    {
        $probe = $this->probe(fn () => (object) [
            'statusCdr' => (object) [
                'statusCode' => '0900',
                'statusMessage' => 'Error no catalogado',
            ],
        ]);

        $outcome = $probe->probe($this->input());

        self::assertSame(FiscalExternalProbeOutcome::OUTCOME_RESPONSE_UNCLASSIFIED, $outcome->outcome);
        self::assertSame('0900', $outcome->sunatCode);
    }

    public function test_authentication_fault_is_rejected(): void
    {
        $probe = $this->probe(function (): void {
            throw new SoapFault('soap-env:Client', 'Usuario o contraseña incorrectos');
        });

        $outcome = $probe->probe($this->input());

        self::assertSame(FiscalExternalProbeOutcome::OUTCOME_AUTHENTICATION_REJECTED, $outcome->outcome);
        self::assertStringContainsString('contraseña', (string) $outcome->sunatMessage);
    }

    public function test_http_fault_is_transport_error(): void
    {
        $probe = $this->probe(function (): void {
            throw new SoapFault('HTTP', 'Could not connect to host');
        });

        $outcome = $probe->probe($this->input());

        self::assertSame(FiscalExternalProbeOutcome::OUTCOME_TRANSPORT_ERROR, $outcome->outcome);
    }

    public function test_uncatalogued_fault_is_unclassified_not_accepted(): void
    {
        $probe = $this->probe(function (): void {
            throw new SoapFault('soap-env:Server', 'Servicio no disponible');
        });

        $outcome = $probe->probe($this->input());

        self::assertSame(FiscalExternalProbeOutcome::OUTCOME_RESPONSE_UNCLASSIFIED, $outcome->outcome);
    }

    public function test_untrusted_transport_keeps_trust_flag_false(): void
    {
        $probe = $this->probe(
            fn () => (object) ['statusCdr' => (object) ['statusCode' => '1033']],
            trusted: false,
        );

        $outcome = $probe->probe($this->input());

        self::assertSame(FiscalExternalProbeOutcome::OUTCOME_AUTHENTICATION_ACCEPTED, $outcome->outcome);
        self::assertFalse($outcome->transportTrusted);
    }

    public function test_beta_environment_is_unsupported_without_network_call(): void
    {
        $called = false;
        $probe = $this->probe(function () use (&$called) {
            $called = true;

            return new stdClass;
        });

        $outcome = $probe->probe($this->input(FiscalProfileDiagnosticInput::ENVIRONMENT_BETA));

        self::assertSame(FiscalExternalProbeOutcome::OUTCOME_ENVIRONMENT_UNSUPPORTED, $outcome->outcome);
        self::assertFalse($outcome->transportTrusted);
        self::assertFalse($called);
    }

    public function test_missing_status_payload_is_unclassified(): void
    {
        $probe = $this->probe(fn () => new stdClass);

        $outcome = $probe->probe($this->input());

        self::assertSame(FiscalExternalProbeOutcome::OUTCOME_RESPONSE_UNCLASSIFIED, $outcome->outcome);
        self::assertSame('missing_status_payload', $outcome->sunatCode);
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: int}>
     */
    public static function invalidReferenceProvider(): iterable
    {
        yield 'document type' => ['1', 'F001', 1];
        yield 'series' => ['01', 'F 01', 1];
        yield 'correlative' => ['01', 'F001', 0];
    }

    /**
     * @dataProvider invalidReferenceProvider
     */
    #[DataProvider('invalidReferenceProvider')]
    public function test_constructor_rejects_invalid_reference(string $type, string $series, int $correlative): void
    {
        $this->expectException(InvalidArgumentException::class);

        new GreenterConsultCdrProbe(
            client: $this->client(fn () => new stdClass),
            documentType: $type,
            series: $series,
            correlative: $correlative,
        );
    }
}
