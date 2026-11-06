<?php

namespace App\Services;

use App\Models\Company as StoredCompany;
use App\Models\Despatch as StoredDespatch;
use App\Models\Invoice;
use DateTime;
use DateTimeZone;
use Greenter\Api;
use Greenter\Api\ApiFactory;
use Greenter\Api\InMemoryStore;
use Greenter\Model\Client\Client;
use Greenter\Model\Company\Company;
use Greenter\Model\Despatch\AdditionalDoc;
use Greenter\Model\Despatch\Despatch;
use Greenter\Model\Despatch\DespatchDetail;
use Greenter\Model\Despatch\Direction;
use Greenter\Model\Despatch\Driver;
use Greenter\Model\Despatch\Shipment;
use Greenter\Model\Despatch\Vehicle;
use Greenter\Sunat\GRE\Api\AuthApi;
use Greenter\Sunat\GRE\Api\CpeApiInterface;
use Greenter\Sunat\GRE\ApiException;
use Greenter\Sunat\GRE\Configuration;
use Greenter\Sunat\GRE\Model\CpeDocument;
use Greenter\Sunat\GRE\Model\CpeDocumentArchivo;
use Greenter\Xml\Builder\DespatchBuilder;
use Greenter\XMLSecLibs\Sunat\SignedXml;
use Greenter\Zip\ZipFly;
use GuzzleHttp\Client as HttpClient;
use RuntimeException;
use Throwable;

class GreenterDespatchGateway
{
    public function __construct(private readonly CompanyCertificateStore $certificates) {}

    public function build(StoredCompany $company, StoredDespatch $stored, Invoice $invoice): Despatch
    {
        $p = $stored->payload;
        // Greenter templates mix raw CDATA and XML text. Escape each context explicitly.
        $cdata = static fn (string $s): string => str_replace(']]>', ']]]]><![CDATA[>', $s);
        $text = static fn (string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $date = static fn (string $s): DateTime => new DateTime($s.' 12:00:00', new DateTimeZone('America/Lima'));
        $shipment = (new Shipment)->setCodTraslado('01')->setDesTraslado('Venta')->setModTraslado('02')
            ->setFecTraslado($date($p['transfer_date']))->setPesoTotal((float) $p['weight_kg'])->setUndPesoTotal('KGM')
            ->setPartida(new Direction($p['origin']['ubigeo'], $text($p['origin']['address'])))
            ->setLlegada(new Direction($p['destination']['ubigeo'], $text($p['destination']['address'])));
        if (in_array($p['vehicle_category'] ?? null, ['M1', 'L'], true)) {
            $shipment->setIndicadores(['SUNAT_Envio_IndicadorTrasladoVehiculoM1L']);
        } else {
            $shipment->setVehiculo((new Vehicle)->setPlaca($p['vehicle']['plate']))
                ->setChoferes([(new Driver)->setTipo('Principal')->setTipoDoc('1')->setNroDoc($p['driver']['dni'])
                    ->setNombres($text($p['driver']['first_name']))->setApellidos($text($p['driver']['last_name']))->setLicencia($p['driver']['license'])]);
        }

        return (new Despatch)->setVersion('2022')->setTipoDoc('09')->setSerie($stored->series)->setCorrelativo((string) $stored->correlative)
            ->setFechaEmision(new DateTime($p['issue_date'].' '.($stored->updated_at?->timezone('America/Lima')->format('H:i:s') ?? now('America/Lima')->format('H:i:s')), new DateTimeZone('America/Lima')))
            ->setCompany((new Company)->setRuc($company->ruc)->setRazonSocial($cdata($company->legal_name)))
            ->setDestinatario((new Client)->setTipoDoc('6')->setNumDoc($invoice->draft->customer_ruc)->setRznSocial($cdata($invoice->draft->customer_name)))
            ->setAddDocs([(new AdditionalDoc)->setTipo('01')->setTipoDesc('Factura')->setNro($text($invoice->number))->setEmisor($company->ruc)])
            ->setEnvio($shipment)->setDetails(array_map(fn (array $item, int $index) => (new DespatchDetail)
            ->setCodigo('ITEM-'.($index + 1))->setDescripcion($cdata($item['description']))->setUnidad($item['unit'])->setCantidad((float) $item['quantity']), $p['items'], array_keys($p['items'])));
    }

    public function xml(StoredCompany $company, StoredDespatch $guide, Invoice $invoice): string
    {
        $xml = (new DespatchBuilder(['autoescape' => false]))->build($this->build($company, $guide, $invoice));
        $document = new \DOMDocument;
        $previousErrors = libxml_use_internal_errors(true);
        try {
            if (! $document->loadXML($xml, LIBXML_NONET)) {
                throw new RuntimeException('gre_xml_invalid');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }
        if ($guide->driver === 'fake') {
            return str_replace('<DespatchAdvice ', '<DespatchAdvice demo="true" ', $xml);
        }
        $signer = new SignedXml;
        $signer->setCertificate($this->certificates->read($company));

        return $signer->signXml($xml);
    }

    /**
     * @throws GreSendException when SUNAT returns no ticket; it carries SUNAT's code and message.
     */
    public function send(StoredCompany $company, StoredDespatch $guide, string $xml): string
    {
        if ($guide->driver === 'fake') {
            return 'demo-'.$guide->id;
        }
        $name = $company->ruc.'-09-'.$guide->series.'-'.$guide->correlative;
        try {
            $api = $this->cpe($company);
        } catch (ApiException $error) {
            // The token request failed: SUNAT never received the guide.
            [$code, $message] = self::sunatError($error);
            throw new GreSendException(true, $code, $message, 'auth');
        } catch (Throwable) {
            throw new GreSendException(true, 'AUTH', 'No se obtuvo autorización de SUNAT para enviar guías.', 'auth');
        }
        $zip = (new ZipFly)->compress($name.'.xml', $xml);
        $document = (new CpeDocument)->setArchivo((new CpeDocumentArchivo)->setNomArchivo($name.'.zip')
            ->setArcGreZip(base64_encode($zip))->setHashZip(hash('sha256', $zip)));
        try {
            $ticket = $api->enviarCpe($name, $document)->getNumTicket();
        } catch (ApiException $error) {
            [$code, $message] = self::sunatError($error);
            $status = $error->getCode();
            // 4xx: SUNAT refused the request and did not register it. 5xx or no response: outcome unknown.
            // "Already registered" means a previous submission exists, so it is never treated as not sent.
            $definitive = $status >= 400 && $status < 500 && ! preg_match('/registrad|ya existe|duplicad/iu', $message);
            throw new GreSendException($definitive, $code, $message, 'send');
        }
        if (! is_string($ticket) || $ticket === '') {
            throw new GreSendException(false, 'NO_TICKET', 'SUNAT no devolvió número de ticket.', 'send');
        }

        return $ticket;
    }

    /**
     * SUNAT's error code and message from a GRE API failure, without anything resembling a credential.
     *
     * @return array{0: string, 1: string}
     */
    public static function sunatError(ApiException $error): array
    {
        $body = $error->getResponseBody();
        $data = is_string($body) ? json_decode($body, true) : json_decode((string) json_encode($body), true);
        $data = is_array($data) ? $data : [];
        $first = is_array($data['errors'] ?? null) && is_array($data['errors'][0] ?? null) ? $data['errors'][0] : null;
        [$code, $message] = match (true) {
            $first !== null => [$first['cod'] ?? null, $first['msg'] ?? null],
            isset($data['cod']) => [$data['cod'], $data['msg'] ?? null],
            isset($data['error']) => [$data['error'], $data['error_description'] ?? null],
            default => [null, null],
        };
        $fallback = $error->getCode() > 0 ? 'HTTP_'.$error->getCode() : 'SIN_RESPUESTA';

        return [self::safe(is_scalar($code) ? (string) $code : '', 20) ?: $fallback,
            self::safe(is_scalar($message) ? (string) $message : '', 300) ?: 'SUNAT no dio detalle del error.'];
    }

    private static function safe(string $value, int $max): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
        $value = preg_replace('/[A-Za-z0-9._~+\/=-]{32,}/', '[…]', $value) ?? '';

        return mb_substr(trim($value), 0, $max);
    }

    public function status(StoredCompany $company, StoredDespatch $guide): array
    {
        if ($guide->driver === 'fake') {
            return ['status' => 'accepted', 'code' => '0', 'cdr' => json_encode(['demo' => true, 'guide' => $guide->series.'-'.$guide->correlative], JSON_THROW_ON_ERROR)];
        }
        $result = $this->api($company)->getStatus($guide->ticket);
        if ((string) $result->getCode() === '98') {
            return ['status' => 'pending', 'code' => '98', 'cdr' => null];
        }
        $cdr = $result->getCdrResponse();
        if (! $result->isSuccess() || ! $cdr || ! $result->getCdrZip()
            || $cdr->getId() !== $guide->series.'-'.$guide->correlative
            || ! is_string($cdr->getCode()) || ! preg_match('/^\d{1,5}$/', $cdr->getCode())
            || ((string) $result->getCode() === '99' && $cdr->isAccepted())) {
            throw new RuntimeException('gre_status_unconfirmed');
        }

        return ['status' => $cdr->isAccepted() ? 'accepted' : 'rejected', 'code' => (string) $cdr->getCode(), 'cdr' => $result->getCdrZip()];
    }

    protected function api(StoredCompany $company): Api
    {
        return (new Api(null, $this->factory($company)))->setApiCredentials($company->gre_client_id, $company->gre_client_secret)
            ->setClaveSOL($company->ruc, $company->sol_user, $company->sol_password);
    }

    /** Authenticated GRE API client; the token request happens here, before any document is sent. */
    protected function cpe(StoredCompany $company): CpeApiInterface
    {
        // Same credential format as Api::setClaveSOL: RUC followed by the SOL user.
        return $this->factory($company)->create($company->gre_client_id, $company->gre_client_secret,
            $company->ruc.$company->sol_user, $company->sol_password);
    }

    private function factory(StoredCompany $company): ApiFactory
    {
        // Fixed official endpoints, bounded requests, no redirects or fallback from beta to production.
        if ($company->sunat_environment !== 'production' || ! $company->gre_enabled || ! $company->hasSunatCredentials()
            || ! $company->gre_client_id || ! $company->gre_client_secret) {
            throw new RuntimeException('gre_not_configured');
        }
        $client = new HttpClient(['timeout' => 30, 'connect_timeout' => 5, 'allow_redirects' => false]);

        return new ApiFactory(new AuthApi($client, (new Configuration)->setHost('https://api-seguridad.sunat.gob.pe/v1')),
            $client, new InMemoryStore, 'https://api-cpe.sunat.gob.pe/v1');
    }
}
