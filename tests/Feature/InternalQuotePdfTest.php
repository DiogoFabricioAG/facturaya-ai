<?php

namespace Tests\Feature;

use Tests\TestCase;

final class InternalQuotePdfTest extends TestCase
{
    private const TOKEN = 'profile-bridge-test-token';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'company_profile.enabled' => true,
            'company_profile.allow_local_http' => true,
            'company_profile.bridge_token' => self::TOKEN,
        ]);
    }

    /** @return array<string, mixed> */
    private function quote(array $overrides = []): array
    {
        return array_replace([
            'company' => ['name' => 'CREACIONES J&D', 'legal_name' => 'CREACIONES J&D', 'ruc' => '10410461248', 'address' => 'RIMAC, LIMA'],
            'number' => 7,
            'issue_date' => '2026-11-13',
            'valid_until' => '2026-11-20',
            'customer' => ['name' => 'Comercial Lima E.I.R.L.', 'document_type' => '6', 'document_number' => '20207341241'],
            'lines' => [
                ['description' => 'Volantes A5 a full color', 'quantity' => 1000, 'unit_price_cents' => 15, 'total_cents' => 15000],
                ['description' => 'Banner 2x1 m con ojales', 'quantity' => 1, 'unit_price_cents' => 9500, 'total_cents' => 9500],
            ],
            'subtotal_cents' => 24500,
            'discount_cents' => 1500,
            'total_cents' => 23000,
            'notes' => 'Entrega en 3 días hábiles. Diseño incluido.',
        ], $overrides);
    }

    public function test_it_draws_the_quote_with_the_single_layout_and_without_fiscal_marks(): void
    {
        $response = $this->postJson('/api/internal/quotes/pdf', $this->quote(), ['Authorization' => 'Bearer '.self::TOKEN]);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $pdf = $response->getContent();
        $encoded = static fn (string $value): string => iconv('UTF-8', 'Windows-1252', $value);
        self::assertStringStartsWith('%PDF-1.4', $pdf);
        foreach (['(COT-000007)', '(NO ES UN COMPROBANTE DE PAGO)', '(DATOS DEL CLIENTE)', '(DETALLE DE PRODUCTOS)', '(RESUMEN DE IMPORTES)',
            '(Descuento)', '(-S/ 15.00)', '(S/ 230.00)', '(20/11/2026)', '(20207341241)'] as $text) {
            self::assertStringContainsString($text, $pdf);
        }
        self::assertStringContainsString($encoded('(COTIZACIÓN)'), $pdf);
        self::assertStringContainsString($encoded('Observaciones: Entrega en 3 días hábiles. Diseño incluido.'), $pdf);
        // 230.00 with IGV included: 194.92 + 35.08.
        self::assertStringContainsString('(S/ 194.92)', $pdf);
        self::assertStringContainsString('(S/ 35.08)', $pdf);
        // Not a comprobante: no QR, no SUNAT legend.
        self::assertStringNotContainsString("0 0 0 rg\n", $pdf);
        self::assertStringNotContainsString('SUNAT', $pdf);
    }

    public function test_it_requires_the_internal_token_and_a_valid_quote(): void
    {
        $this->postJson('/api/internal/quotes/pdf', $this->quote())->assertUnauthorized();
        $this->postJson('/api/internal/quotes/pdf', $this->quote(), ['Authorization' => 'Bearer wrong'])->assertUnauthorized();
        $this->postJson('/api/internal/quotes/pdf', $this->quote(['lines' => []]), ['Authorization' => 'Bearer '.self::TOKEN])
            ->assertUnprocessable();
        $this->postJson('/api/internal/quotes/pdf', $this->quote(['valid_until' => '20/11/2026']), ['Authorization' => 'Bearer '.self::TOKEN])
            ->assertUnprocessable();
    }
}
