<?php

namespace Tests\Unit;

use App\Services\SunatRucPadronImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

class SunatRucPadronImporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_imports_the_official_pipe_separated_padron_from_a_zip(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sunat-test-');
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $contents = implode("\n", [
            'RUC|NOMBRE O RAZÓN SOCIAL|ESTADO|CONDICIÓN|UBIGEO|DOMICILIO FISCAL',
            '20557288016|EMPRESA DE PRUEBA S.A.C.|ACTIVO|HABIDO|150101|AV. PRUEBA 123',
            '20123456789|SEGUNDA EMPRESA S.A.C.|ACTIVO|HABIDO|150122|CALLE DOS 456',
        ]);
        $zip->addFromString('padron_reducido_ruc.txt', mb_convert_encoding($contents, 'Windows-1252', 'UTF-8'));
        $zip->close();

        try {
            $processed = app(SunatRucPadronImporter::class)->import($path, 100);

            $this->assertSame(2, $processed);
            $this->assertDatabaseHas('sunat_taxpayers', [
                'ruc' => '20557288016',
                'legal_name' => 'EMPRESA DE PRUEBA S.A.C.',
                'status' => 'ACTIVO',
                'condition' => 'HABIDO',
            ]);
        } finally {
            @unlink($path);
        }
    }
}
