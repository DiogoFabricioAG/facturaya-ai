<?php

namespace Tests\Unit;

use App\Services\Extraction\OpenAiDocumentExtractor;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiDocumentExtractorTest extends TestCase
{
    public function test_it_extracts_natural_language_with_a_strict_json_schema(): void
    {
        config()->set('facturaya.ai.openai.api_key', 'test-api-key');
        config()->set('facturaya.ai.openai.model', 'gpt-5.4');
        config()->set('facturaya.ai.openai.base_url', 'https://api.openai.com/v1');

        $structuredOutput = [
            'document_type' => 'texto_libre',
            'currency' => 'PEN',
            'items' => [[
                'description' => 'Laptop Lenovo',
                'quantity' => 2,
                'unit_price' => 2500,
                'line_total' => 5000,
                'source_page' => null,
                'confidence' => 0.99,
            ]],
            'document_total' => 5000,
            'warnings' => [],
        ];

        Http::fake([
            '*' => Http::response([
                'output' => [[
                    'type' => 'message',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => json_encode($structuredOutput, JSON_THROW_ON_ERROR),
                    ]],
                ]],
            ]),
        ]);

        $result = app(OpenAiDocumentExtractor::class)
            ->extractText('Vendí 2 laptops Lenovo a S/ 2,500 cada una.');

        $this->assertSame($structuredOutput, $result);

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();
            $userText = data_get($body, 'input.0.content.0.text', '');

            return $request->url() === 'https://api.openai.com/v1/responses'
                && $body['model'] === 'gpt-5.4'
                && $body['store'] === false
                && data_get($body, 'text.format.type') === 'json_schema'
                && data_get($body, 'text.format.strict') === true
                && str_contains($userText, '2 laptops Lenovo')
                && $request->hasHeader('Authorization', 'Bearer test-api-key');
        });
    }

    public function test_it_defines_a_line_total_as_quantity_times_unit_price(): void
    {
        config()->set('facturaya.ai.openai.api_key', 'test-api-key');
        config()->set('facturaya.ai.openai.model', 'gpt-5.4');
        config()->set('facturaya.ai.openai.base_url', 'https://api.openai.com/v1');

        Http::fake([
            '*' => Http::response([
                'output' => [[
                    'type' => 'message',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => json_encode([
                            'document_type' => 'texto_libre',
                            'currency' => 'PEN',
                            'items' => [[
                                'description' => 'motos',
                                'quantity' => 2,
                                'unit_price' => 7800,
                                'line_total' => 15600,
                                'source_page' => null,
                                'confidence' => 1,
                            ]],
                            'document_total' => 15600,
                            'warnings' => [],
                        ], JSON_THROW_ON_ERROR),
                    ]],
                ]],
            ]),
        ]);

        $result = app(OpenAiDocumentExtractor::class)->extractText('2 motos a 15600 total');

        $this->assertSame(7800, $result['items'][0]['unit_price']);
        $this->assertSame(15600, $result['items'][0]['line_total']);

        Http::assertSent(fn (Request $request): bool => str_contains(
            (string) data_get($request->data(), 'instructions'),
            '"2 motos a 15600 total" significa quantity=2, unit_price=7800 y line_total=15600',
        ));
    }

    public function test_it_sends_text_and_pdf_together_and_cleans_up_the_remote_file(): void
    {
        config()->set('facturaya.ai.openai.api_key', 'test-api-key');
        config()->set('facturaya.ai.openai.model', 'gpt-5.4');
        config()->set('facturaya.ai.openai.base_url', 'https://api.openai.com/v1');

        $structuredOutput = [
            'document_type' => 'fuentes_combinadas',
            'currency' => 'PEN',
            'items' => [[
                'description' => 'Soporte mensual',
                'quantity' => 3,
                'unit_price' => 250,
                'line_total' => 750,
                'source_page' => 1,
                'confidence' => 0.98,
            ]],
            'document_total' => 750,
            'warnings' => [],
        ];

        Http::fake(function (Request $request) use ($structuredOutput) {
            if ($request->method() === 'POST' && $request->url() === 'https://api.openai.com/v1/files') {
                return Http::response(['id' => 'file_combined_test']);
            }

            if ($request->method() === 'DELETE') {
                return Http::response(['id' => 'file_combined_test', 'deleted' => true]);
            }

            return Http::response([
                'output' => [[
                    'type' => 'message',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => json_encode($structuredOutput, JSON_THROW_ON_ERROR),
                    ]],
                ]],
            ]);
        });

        $file = UploadedFile::fake()->createWithContent(
            'cotizacion.pdf',
            "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF",
        );

        $result = app(OpenAiDocumentExtractor::class)->extract(
            'Corrige el soporte a 3 meses y evita duplicarlo.',
            $file,
        );

        $this->assertSame($structuredOutput, $result);

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://api.openai.com/v1/responses') {
                return false;
            }

            $content = data_get($request->data(), 'input.0.content', []);

            return data_get($content, '0.type') === 'input_text'
                && str_contains(data_get($content, '0.text', ''), 'No dupliques')
                && str_contains(data_get($content, '0.text', ''), 'Corrige el soporte')
                && data_get($content, '1.type') === 'input_file'
                && data_get($content, '1.file_id') === 'file_combined_test';
        });

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://api.openai.com/v1/files/file_combined_test');
    }
}
