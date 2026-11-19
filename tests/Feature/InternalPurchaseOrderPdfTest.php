<?php

namespace Tests\Feature;

use Tests\TestCase;

final class InternalPurchaseOrderPdfTest extends TestCase
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
    private function order(array $overrides = []): array
    {
        return array_replace([
            'company' => ['name' => 'CREACIONES J&D', 'legal_name' => 'CREACIONES J&D', 'ruc' => '10410461248', 'address' => 'RIMAC, LIMA'],
            'number' => 3,
            'issue_date' => '2026-11-15',
            'deliver_by' => '2026-11-20',
            'supplier' => ['name' => 'Textiles Lima S.A.C.', 'ruc' => '20207341241'],
            'lines' => [
                ['description' => 'Polo blanco M', 'quantity' => 50, 'unit_price_cents' => 1200],
                ['description' => 'Polo negro L', 'quantity' => 30, 'unit_price_cents' => 1300],
            ],
            'notes' => 'Entregar en el taller de Rímac.',
        ], $overrides);
    }

    public function test_it_draws_the_purchase_order_for_the_supplier(): void
    {
        $response = $this->postJson('/api/internal/purchase-orders/pdf', $this->order(), ['Authorization' => 'Bearer '.self::TOKEN]);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $pdf = $response->getContent();
        $encoded = static fn (string $value): string => iconv('UTF-8', 'Windows-1252', $value);
        self::assertStringStartsWith('%PDF-1.4', $pdf);
        // 50 x 12.00 + 30 x 13.00 = 990.00 with IGV included: 838.98 + 151.02.
        foreach (['(OC-000003)', '(ORDEN DE COMPRA)', '(DATOS DEL PROVEEDOR)', '(20207341241)', '(20/11/2026)',
            '(S/ 600.00)', '(S/ 990.00)', '(S/ 838.98)', '(S/ 151.02)'] as $text) {
            self::assertStringContainsString($text, $pdf);
        }
        self::assertStringContainsString($encoded('Observaciones: Entregar en el taller de Rímac.'), $pdf);
        self::assertStringNotContainsString('SUNAT', $pdf);
    }

    public function test_lines_without_a_price_stay_to_be_confirmed(): void
    {
        $order = $this->order(['deliver_by' => null, 'lines' => [
            ['description' => 'Polo blanco M', 'quantity' => 50, 'unit_price_cents' => 1200],
            ['description' => 'Tela pique', 'quantity' => 10, 'unit_price_cents' => null],
        ]]);

        $pdf = $this->postJson('/api/internal/purchase-orders/pdf', $order, ['Authorization' => 'Bearer '.self::TOKEN])
            ->assertOk()->getContent();

        foreach (['(Por confirmar)', '(A coordinar)', '(S/ 600.00)'] as $text) {
            self::assertStringContainsString($text, $pdf);
        }
        self::assertStringContainsString('(Monto por confirmar con el proveedor)', $pdf);
    }

    public function test_it_requires_the_internal_token_and_a_valid_order(): void
    {
        $this->postJson('/api/internal/purchase-orders/pdf', $this->order())->assertUnauthorized();
        $this->postJson('/api/internal/purchase-orders/pdf', $this->order(['lines' => []]), ['Authorization' => 'Bearer '.self::TOKEN])
            ->assertUnprocessable();
        $this->postJson('/api/internal/purchase-orders/pdf', $this->order(['supplier' => ['name' => 'X', 'ruc' => '123']]), ['Authorization' => 'Bearer '.self::TOKEN])
            ->assertUnprocessable();
    }
}
