<?php

namespace Tests\Unit;

use App\Services\Sunat\DiagnosticSoapClient;
use PHPUnit\Framework\TestCase;

final class DiagnosticSoapClientTest extends TestCase
{
    public function test_it_reports_transport_metadata_without_exposing_the_request_or_credentials(): void
    {
        $secret = 'very-secret-sol-password';
        $invoice = str_repeat('A', 240);
        $request = '<soap><Username>1041046124871434915</Username><Password>'.$secret.'</Password><content>'.$invoice.'</content></soap>';
        $requestHeaders = "POST /billService HTTP/1.1\r\nSOAPAction: \"sendBill\"\r\n";
        $response = '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Fault><faultcode>HTTP</faultcode><faultstring>Bad Request</faultstring></soap:Fault></soap:Envelope>';
        $responseHeaders = "HTTP/1.1 400 Bad Request\r\nContent-Type: text/xml; charset=utf-8\r\n";

        $diagnostics = DiagnosticSoapClient::summarizeExchange($request, $requestHeaders, $response, $responseHeaders);
        $encoded = json_encode($diagnostics, JSON_THROW_ON_ERROR);

        self::assertSame('400', $diagnostics['http_status']);
        self::assertSame('Bad Request', $diagnostics['http_reason']);
        self::assertSame('sendBill', $diagnostics['soap_action']);
        self::assertStringContainsString('faultstring: Bad Request', (string) $diagnostics['response_summary']);
        self::assertStringNotContainsString($secret, $encoded);
        self::assertStringNotContainsString('1041046124871434915', $encoded);
        self::assertStringNotContainsString($invoice, $encoded);
    }
}
