<?php

namespace Tests\Unit;

use App\Models\InvoiceDraft;
use App\Services\BoletaCustomerPolicy;
use PHPUnit\Framework\TestCase;

class BoletaCustomerPolicyTest extends TestCase
{
    public function test_anonymous_boleta_is_allowed_at_exactly_seven_hundred_soles(): void
    {
        $draft = new InvoiceDraft([
            'document_type' => '03',
            'customer_document_type' => '0',
            'total' => '700.00',
        ]);

        $this->assertNull((new BoletaCustomerPolicy)->validate($draft));
    }

    public function test_anonymous_boleta_over_seven_hundred_requires_identification(): void
    {
        $draft = new InvoiceDraft([
            'document_type' => '03',
            'customer_document_type' => '0',
            'total' => '700.01',
        ]);

        $this->assertSame(
            'Las boletas por importes superiores a S/ 700.00 deben incluir nombres y documento del cliente (DNI o RUC).',
            (new BoletaCustomerPolicy)->validate($draft),
        );
    }

    public function test_identified_boleta_requires_a_valid_number_and_name(): void
    {
        $draft = new InvoiceDraft([
            'document_type' => '03',
            'customer_document_type' => '1',
            'customer_ruc' => '71434915',
            'customer_name' => 'Cliente de prueba',
            'total' => '120.00',
        ]);

        $this->assertNull((new BoletaCustomerPolicy)->validate($draft));
    }
}
