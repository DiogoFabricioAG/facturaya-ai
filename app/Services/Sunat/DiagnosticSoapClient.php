<?php

namespace App\Services\Sunat;

use Greenter\Ws\Services\SoapClient;

final class DiagnosticSoapClient extends SoapClient
{
    private ?string $serviceUrl = null;

    public function __construct()
    {
        parent::__construct('', [
            'trace' => true,
            'exceptions' => true,
            'stream_context' => stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ],
            ]),
        ]);
    }

    public function setService(?string $url)
    {
        $this->serviceUrl = $url;

        parent::setService($url);
    }

    /**
     * Return transport metadata without exposing the WS-Security header or invoice XML.
     *
     * @return array<string, int|string|null>
     */
    public function diagnostics(): array
    {
        $diagnostics = self::summarizeExchange(
            (string) ($this->__getLastRequest() ?: ''),
            (string) ($this->__getLastRequestHeaders() ?: ''),
            (string) ($this->__getLastResponse() ?: ''),
            (string) ($this->__getLastResponseHeaders() ?: ''),
        );

        $parts = $this->serviceUrl ? parse_url($this->serviceUrl) : false;
        $diagnostics['endpoint'] = is_array($parts)
            ? (($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').($parts['path'] ?? ''))
            : null;

        return $diagnostics;
    }

    /**
     * @return array<string, int|string|null>
     */
    public static function summarizeExchange(
        string $request,
        string $requestHeaders,
        string $response,
        string $responseHeaders,
    ): array
    {
        preg_match_all('/^HTTP\/\S+\s+(\d{3})(?:\s+([^\r\n]+))?/mi', $responseHeaders, $statusMatches, PREG_SET_ORDER);
        $lastStatus = $statusMatches === [] ? null : end($statusMatches);

        preg_match_all('/^Content-Type:\s*([^\r\n]+)/mi', $responseHeaders, $contentTypeMatches);
        $contentTypes = $contentTypeMatches[1] ?? [];

        preg_match('/^SOAPAction:\s*"?([^"\r\n]+)"?/mi', $requestHeaders, $actionMatch);

        return [
            'http_status' => $lastStatus[1] ?? null,
            'http_reason' => isset($lastStatus[2]) ? trim($lastStatus[2]) : null,
            'content_type' => $contentTypes === [] ? null : trim((string) end($contentTypes)),
            'soap_action' => $actionMatch[1] ?? null,
            'request_bytes' => strlen($request),
            'request_sha256' => $request === '' ? null : hash('sha256', $request),
            'response_bytes' => strlen($response),
            'response_sha256' => $response === '' ? null : hash('sha256', $response),
            'response_summary' => self::summarizeResponse($response),
        ];
    }

    private static function summarizeResponse(string $response): ?string
    {
        if (trim($response) === '') {
            return null;
        }

        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($response, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded) {
            $xpath = new \DOMXPath($document);
            $values = [];

            foreach (['faultcode', 'faultstring', 'ResponseCode', 'Description'] as $name) {
                $nodes = $xpath->query(sprintf('//*[local-name()="%s"]', $name));
                if ($nodes === false) {
                    continue;
                }

                foreach ($nodes as $node) {
                    $value = trim((string) $node->textContent);
                    if ($value !== '') {
                        $values[] = $name.': '.$value;
                    }
                }
            }

            return $values === []
                ? 'Respuesta XML sin detalle de error legible.'
                : substr(implode(' | ', array_unique($values)), 0, 800);
        }

        $redacted = preg_replace(
            '~<(?:[A-Za-z0-9_.-]+:)?(?:Username|Password|BinarySecurityToken|content)\b[^>]*>.*?</(?:[A-Za-z0-9_.-]+:)?(?:Username|Password|BinarySecurityToken|content)>~is',
            '[REDACTED]',
            $response,
        );
        $redacted = preg_replace('/[A-Za-z0-9+\/=]{80,}/', '[REDACTED_BLOB]', (string) $redacted);
        $plain = trim((string) preg_replace('/\s+/', ' ', strip_tags((string) $redacted)));

        return $plain === '' ? 'Respuesta no vacía sin texto legible.' : substr($plain, 0, 800);
    }
}
