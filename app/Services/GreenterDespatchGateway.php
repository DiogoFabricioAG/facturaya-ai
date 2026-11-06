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
use Greenter\Sunat\GRE\Configuration;
use Greenter\Xml\Builder\DespatchBuilder;
use Greenter\XMLSecLibs\Sunat\SignedXml;
use GuzzleHttp\Client as HttpClient;
use RuntimeException;

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

    public function send(StoredCompany $company, StoredDespatch $guide, string $xml): string
    {
        if ($guide->driver === 'fake') {
            return 'demo-'.$guide->id;
        }
        $result = $this->api($company)->sendXml($company->ruc.'-09-'.$guide->series.'-'.$guide->correlative, $xml);
        if (! $result?->isSuccess() || ! method_exists($result, 'getTicket') || ! $result->getTicket()) {
            throw new RuntimeException('gre_send_result_unconfirmed');
        }

        return $result->getTicket();
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
        // Fixed official endpoints, bounded requests, no redirects or fallback from beta to production.
        if ($company->sunat_environment !== 'production' || ! $company->gre_enabled || ! $company->hasSunatCredentials()
            || ! $company->gre_client_id || ! $company->gre_client_secret) {
            throw new RuntimeException('gre_not_configured');
        }
        $client = new HttpClient(['timeout' => 30, 'connect_timeout' => 5, 'allow_redirects' => false]);
        $factory = new ApiFactory(new AuthApi($client, (new Configuration)->setHost('https://api-seguridad.sunat.gob.pe/v1')),
            $client, new InMemoryStore, 'https://api-cpe.sunat.gob.pe/v1');

        return (new Api(null, $factory))->setApiCredentials($company->gre_client_id, $company->gre_client_secret)
            ->setClaveSOL($company->ruc, $company->sol_user, $company->sol_password);
    }
}
