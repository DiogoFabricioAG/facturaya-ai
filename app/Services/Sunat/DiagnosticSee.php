<?php

namespace App\Services\Sunat;

use Greenter\See;
use ReflectionProperty;

final class DiagnosticSee extends See
{
    private readonly DiagnosticSoapClient $diagnosticClient;

    public function __construct()
    {
        parent::__construct();

        $this->diagnosticClient = new DiagnosticSoapClient;

        // Greenter 5.x does not expose a client setter. Keep the substitution isolated
        // here so application code never needs to modify the vendor package.
        $property = new ReflectionProperty(See::class, 'wsClient');
        $property->setValue($this, $this->diagnosticClient);
    }

    /**
     * @return array<string, int|string|null>
     */
    public function transportDiagnostics(): array
    {
        return $this->diagnosticClient->diagnostics();
    }
}
