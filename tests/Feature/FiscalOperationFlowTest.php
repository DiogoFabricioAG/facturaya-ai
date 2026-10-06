<?php

namespace Tests\Feature;

use App\Contracts\SunatGateway;
use App\Models\Company;
use App\Models\CreditNote;
use App\Models\FiscalOperation;
use App\Models\FiscalPreview;
use App\Models\Invoice;
use App\Models\InvoiceDraft;
use App\Services\CompanyApiTokenService;
use App\Support\CanonicalJson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FiscalOperationFlowTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private string $companyToken;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-05 20:00:00');

        config()->set('facturaya.ai.driver', 'demo');
        config()->set('facturaya.platform.admin_token', 'platform-admin-secret-token');
        Storage::fake('local');

        [$this->company, $this->companyToken] = $this->createCompany('20111111111', 'Empresa Piloto S.A.C.');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_requires_company_token(): void
    {
        $this->postJson('/api/fiscal-operations/issue', [])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Falta el token de la empresa.');
    }

    public function test_invoice_preview_and_issue_flow(): void
    {
        $operationId = 'op_inv_1001';
        $idempotencyKey = 'idem_inv_1001';

        // 1. Create non-issuing preview
        $previewRes = $this->withToken($this->companyToken)
            ->postJson('/api/fiscal-previews', [
                'contract_version' => '1',
                'operation_id' => $operationId,
                'idempotency_key' => $idempotencyKey,
                'draft_revision' => 1,
                'document_type' => '01',
                'issue_date' => '2026-10-05',
                'currency' => 'PEN',
                'tax_mode' => 'included',
                'customer' => [
                    'document_type' => '6',
                    'name' => 'Cliente RUC S.A.C.',
                    'number' => '20555555555',
                ],
                'items' => [
                    [
                        'description' => 'Servicio de desarrollo',
                        'quantity' => '1.000',
                        'total_price' => '118.00',
                    ],
                ],
            ])
            ->assertCreated();

        $previewData = $previewRes->json('data');
        $previewDigest = $previewData['preview_digest'];
        $this->assertNotEmpty($previewDigest);
        $this->assertSame('100.00', $previewData['totals']['subtotal']);
        $this->assertSame('18.00', $previewData['totals']['igv']);
        $this->assertSame('118.00', $previewData['totals']['total']);

        // 2. Issue fiscal operation with confirmed digest
        $issueRes = $this->withToken($this->companyToken)
            ->postJson('/api/fiscal-operations/issue', [
                'contract_version' => '1',
                'operation_id' => $operationId,
                'idempotency_key' => $idempotencyKey,
                'document_type' => '01',
                'confirmation' => [
                    'actor_id' => 'actor_whatsapp_1',
                    'confirmed_at' => '2026-10-05T20:00:00Z',
                    'preview_digest' => $previewDigest,
                ],
                'payload' => [
                    'issue_date' => '2026-10-05',
                    'currency' => 'PEN',
                    'tax_mode' => 'included',
                    'customer' => [
                        'document_type' => '6',
                        'name' => 'Cliente RUC S.A.C.',
                        'number' => '20555555555',
                    ],
                    'items' => [
                        [
                            'description' => 'Servicio de desarrollo',
                            'quantity' => '1.000',
                            'total_price' => '118.00',
                        ],
                    ],
                ],
            ])
            ->assertCreated();

        $issueData = $issueRes->json();
        $this->assertSame($operationId, $issueData['operation_id']);
        $this->assertSame('accepted', $issueData['status']);
        $this->assertSame('F001', $issueData['document']['series']);
        $this->assertSame('00000001', $issueData['document']['number']);
        $this->assertSame('118.00', $issueData['document']['totals']['total']);
        $this->assertCount(3, $issueData['artifacts']);

        // Assert real PDF artifact metadata
        $pdfArtifact = collect($issueData['artifacts'])->firstWhere('kind', 'pdf');
        $this->assertNotNull($pdfArtifact);
        $this->assertSame('available', $pdfArtifact['availability']);
        $this->assertNotNull($pdfArtifact['artifact_id']);
        $this->assertGreaterThan(0, $pdfArtifact['size_bytes']);

        // Download real PDF and verify magic %PDF header and exact length
        $pdfRes = $this->withToken($this->companyToken)
            ->get("/api/fiscal-operations/{$operationId}/files/pdf")
            ->assertOk();
        $pdfContent = $pdfRes->streamedContent();
        $this->assertStringStartsWith('%PDF', $pdfContent);
        $this->assertSame($pdfArtifact['size_bytes'], strlen($pdfContent));

        // 3. Query operation by operation_id
        $getRes = $this->withToken($this->companyToken)
            ->getJson("/api/fiscal-operations/{$operationId}")
            ->assertOk();

        $this->assertSame($operationId, $getRes->json('operation_id'));
        $this->assertSame('accepted', $getRes->json('status'));
        $this->assertSame('F001', $getRes->json('document.series'));
    }

    public function test_idempotent_repeat_returns_identical_result(): void
    {
        $operationId = 'op_inv_1002';
        $idempotencyKey = 'idem_inv_1002';

        $previewRes = $this->withToken($this->companyToken)
            ->postJson('/api/fiscal-previews', [
                'contract_version' => '1',
                'operation_id' => $operationId,
                'idempotency_key' => $idempotencyKey,
                'draft_revision' => 1,
                'document_type' => '03',
                'issue_date' => '2026-10-05',
                'currency' => 'PEN',
                'tax_mode' => 'included',
                'customer' => [
                    'document_type' => '0',
                    'name' => 'Cliente Final',
                    'number' => null,
                ],
                'items' => [
                    [
                        'description' => 'Producto Boleta',
                        'quantity' => '2.000',
                        'total_price' => '50.00',
                    ],
                ],
            ])
            ->assertCreated();

        $previewDigest = $previewRes->json('data.preview_digest');

        $reqBody = [
            'contract_version' => '1',
            'operation_id' => $operationId,
            'idempotency_key' => $idempotencyKey,
            'document_type' => '03',
            'confirmation' => [
                'actor_id' => 'actor_1',
                'confirmed_at' => '2026-10-05T20:00:00Z',
                'preview_digest' => $previewDigest,
            ],
            'payload' => [
                'issue_date' => '2026-10-05',
                'currency' => 'PEN',
                'tax_mode' => 'included',
                'customer' => [
                    'document_type' => '0',
                    'name' => 'Cliente Final',
                    'number' => null,
                ],
                'items' => [
                    [
                        'description' => 'Producto Boleta',
                        'quantity' => '2.000',
                        'total_price' => '50.00',
                    ],
                ],
            ],
        ];

        $first = $this->withToken($this->companyToken)
            ->postJson('/api/fiscal-operations/issue', $reqBody)
            ->assertCreated();

        $second = $this->withToken($this->companyToken)
            ->postJson('/api/fiscal-operations/issue', $reqBody)
            ->assertCreated();

        $this->assertSame($first->json('document.number'), $second->json('document.number'));
        $this->assertSame($first->json('operation_id'), $second->json('operation_id'));
    }

    public function test_idempotency_conflict_when_payload_differs(): void
    {
        $operationId = 'op_inv_1003';
        $idempotencyKey = 'idem_inv_1003';

        $previewRes = $this->withToken($this->companyToken)
            ->postJson('/api/fiscal-previews', [
                'contract_version' => '1',
                'operation_id' => $operationId,
                'idempotency_key' => $idempotencyKey,
                'draft_revision' => 1,
                'document_type' => '01',
                'issue_date' => '2026-10-05',
                'currency' => 'PEN',
                'tax_mode' => 'included',
                'customer' => [
                    'document_type' => '6',
                    'name' => 'Cliente RUC',
                    'number' => '20555555555',
                ],
                'items' => [
                    [
                        'description' => 'Item Original',
                        'quantity' => '1.000',
                        'total_price' => '100.00',
                    ],
                ],
            ])
            ->assertCreated();

        $previewDigest = $previewRes->json('data.preview_digest');

        $this->withToken($this->companyToken)
            ->postJson('/api/fiscal-operations/issue', [
                'contract_version' => '1',
                'operation_id' => $operationId,
                'idempotency_key' => $idempotencyKey,
                'document_type' => '01',
                'confirmation' => [
                    'actor_id' => 'actor_1',
                    'confirmed_at' => '2026-10-05T20:00:00Z',
                    'preview_digest' => $previewDigest,
                ],
                'payload' => [
                    'issue_date' => '2026-10-05',
                    'currency' => 'PEN',
                    'tax_mode' => 'included',
                    'customer' => [
                        'document_type' => '6',
                        'name' => 'Cliente RUC',
                        'number' => '20555555555',
                    ],
                    'items' => [
                        [
                            'description' => 'Item Original',
                            'quantity' => '1.000',
                            'total_price' => '100.00',
                        ],
                    ],
                ],
            ])
            ->assertCreated();

        // Repeat with modified actor/confirmation -> 409 idempotency conflict
        $this->withToken($this->companyToken)
            ->postJson('/api/fiscal-operations/issue', [
                'contract_version' => '1',
                'operation_id' => $operationId,
                'idempotency_key' => $idempotencyKey,
                'document_type' => '01',
                'confirmation' => [
                    'actor_id' => 'actor_different',
                    'confirmed_at' => '2026-10-05T20:00:00Z',
                    'preview_digest' => $previewDigest,
                ],
                'payload' => [
                    'issue_date' => '2026-10-05',
                ],
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'idempotency_conflict');
    }

    public function test_credit_note_preview_and_issue_flow(): void
    {
        // 1. Create accepted origin invoice first
        $invDraft = InvoiceDraft::create([
            'company_id' => $this->company->id,
            'customer_ruc' => '20555555555',
            'customer_name' => 'Cliente Origen S.A.C.',
            'customer_document_type' => '6',
            'issue_date' => '2026-10-01',
            'tax_mode' => 'included',
            'currency' => 'PEN',
            'status' => 'issued',
            'source_path' => 'test',
            'original_name' => 'test.json',
            'mime_type' => 'application/json',
            'ai_driver' => 'manual',
            'subtotal' => 100.00,
            'igv' => 18.00,
            'total' => 118.00,
            'document_type' => '01',
        ]);
        $invDraft->items()->create([
            'position' => 1,
            'description' => 'Licencia de software',
            'quantity' => 1.000,
            'entered_unit_price' => 118.00,
            'unit_value' => 100.000000,
            'unit_price_with_igv' => 118.000000,
            'line_base' => 100.00,
            'igv' => 18.00,
            'line_total' => 118.00,
        ]);
        $originInvoice = Invoice::create([
            'company_id' => $this->company->id,
            'sunat_environment' => 'beta',
            'document_type' => '01',
            'invoice_draft_id' => $invDraft->id,
            'series' => 'F001',
            'correlative' => 99,
            'status' => 'accepted',
            'issued_at' => now(),
        ]);

        $cnOpId = 'op_cn_2001';
        $cnIdemKey = 'idem_cn_2001';

        // 2. Preview credit note (07)
        $previewRes = $this->withToken($this->companyToken)
            ->postJson('/api/fiscal-previews', [
                'contract_version' => '1',
                'operation_id' => $cnOpId,
                'idempotency_key' => $cnIdemKey,
                'draft_revision' => 1,
                'document_type' => '07',
                'issue_date' => '2026-10-05',
                'currency' => 'PEN',
                'tax_mode' => 'included',
                'origin' => [
                    'series' => 'F001',
                    'correlative' => '99',
                ],
                'reason_code' => '01',
                'reason_description' => 'Anulación total de la operación',
            ])
            ->assertCreated();

        $previewDigest = $previewRes->json('data.preview_digest');
        $this->assertNotEmpty($previewDigest);
        $this->assertSame('118.00', $previewRes->json('data.totals.total'));

        // 3. Issue credit note
        $issueRes = $this->withToken($this->companyToken)
            ->postJson('/api/fiscal-operations/issue', [
                'contract_version' => '1',
                'operation_id' => $cnOpId,
                'idempotency_key' => $cnIdemKey,
                'document_type' => '07',
                'confirmation' => [
                    'actor_id' => 'actor_admin',
                    'confirmed_at' => '2026-10-05T20:00:00Z',
                    'preview_digest' => $previewDigest,
                ],
                'payload' => [
                    'issue_date' => '2026-10-05',
                    'reason_code' => '01',
                    'reason_description' => 'Anulación total de la operación',
                    'origin' => [
                        'series' => 'F001',
                        'correlative' => '99',
                    ],
                ],
            ])
            ->assertCreated();

        $this->assertSame($cnOpId, $issueRes->json('operation_id'));
        $this->assertSame('accepted', $issueRes->json('status'));
        $this->assertSame('FC01', $issueRes->json('document.series'));
        $this->assertSame('F001-00000099', $issueRes->json('document.origin_number'));

        // Assert real credit note PDF artifact metadata
        $pdfArtifact = collect($issueRes->json('artifacts'))->firstWhere('kind', 'pdf');
        $this->assertNotNull($pdfArtifact);
        $this->assertSame('available', $pdfArtifact['availability']);
        $this->assertNotNull($pdfArtifact['artifact_id']);
        $this->assertGreaterThan(0, $pdfArtifact['size_bytes']);

        // Download real credit note PDF
        $pdfRes = $this->withToken($this->companyToken)
            ->get("/api/fiscal-operations/{$cnOpId}/files/pdf")
            ->assertOk();
        $pdfContent = $pdfRes->streamedContent();
        $this->assertStringStartsWith('%PDF', $pdfContent);
        $this->assertSame($pdfArtifact['size_bytes'], strlen($pdfContent));
        $this->assertStringContainsString('(Licencia de software)', $pdfContent);
        $this->assertGreaterThanOrEqual(3, substr_count($pdfContent, '(S/ 118.00)'));
        $this->assertStringNotContainsString('(S/ 0.00)', $pdfContent);
    }

    public function test_crash_post_effect_gateway_preserves_operation_as_unknown_without_rollback(): void
    {
        // Bind a throwing gateway in container
        $throwingGateway = new class(DB::transactionLevel()) implements SunatGateway
        {
            public int $calls = 0;

            public bool $sawPersistedClaimOutsideServiceTransaction = false;

            public function __construct(private readonly int $outerTransactionLevel) {}

            public function issue(InvoiceDraft $draft, Invoice $invoice): array
            {
                $this->calls++;
                $this->sawPersistedClaimOutsideServiceTransaction =
                    DB::transactionLevel() === $this->outerTransactionLevel
                    && FiscalOperation::where('invoice_id', $invoice->id)->where('status', 'processing')->exists();
                throw new \RuntimeException('Gateway network timeout after sending bytes');
            }

            public function issueCreditNote(CreditNote $creditNote): array
            {
                throw new \RuntimeException('Gateway network timeout');
            }
        };

        $this->app->instance(SunatGateway::class, $throwingGateway);

        $operationId = 'op_crash_1001';
        $idempotencyKey = 'idem_crash_1001';

        // 1. Create preview
        $previewRes = $this->withToken($this->companyToken)
            ->postJson('/api/fiscal-previews', [
                'contract_version' => '1',
                'operation_id' => $operationId,
                'idempotency_key' => $idempotencyKey,
                'draft_revision' => 1,
                'document_type' => '01',
                'issue_date' => '2026-10-05',
                'currency' => 'PEN',
                'tax_mode' => 'included',
                'customer' => [
                    'document_type' => '6',
                    'name' => 'Cliente Crash S.A.C.',
                    'number' => '20555555555',
                ],
                'items' => [
                    [
                        'description' => 'Servicio Test',
                        'quantity' => '1.000',
                        'total_price' => '118.00',
                    ],
                ],
            ])
            ->assertCreated();

        $previewDigest = $previewRes->json('data.preview_digest');

        // 2. Dispatch issue -> gateway crashes
        $this->withToken($this->companyToken)
            ->postJson('/api/fiscal-operations/issue', [
                'contract_version' => '1',
                'operation_id' => $operationId,
                'idempotency_key' => $idempotencyKey,
                'document_type' => '01',
                'confirmation' => [
                    'actor_id' => 'actor_crash_1',
                    'confirmed_at' => '2026-10-05T20:00:00Z',
                    'preview_digest' => $previewDigest,
                ],
            ])
            ->assertStatus(500)
            ->assertJsonPath('code', 'provider_gateway_communication_failed');

        // 3. Verify operation was committed in TX1 and updated to 'unknown' in catch (NOT rolled back!)
        $op = FiscalOperation::where('operation_id', $operationId)->first();
        $this->assertNotNull($op, 'FiscalOperation identity must not be rolled back on gateway failure');
        $this->assertSame('unknown', $op->status);
        $this->assertSame(1, $throwingGateway->calls);
        $this->assertTrue($throwingGateway->sawPersistedClaimOutsideServiceTransaction);
        $this->assertSame('GATEWAY_COMMUNICATION_ERROR', $op->sunat_code);
        $this->assertSame('Fallo de comunicacion con el proveedor fiscal', $op->sunat_message);

        // Verify preview consumed_at was NOT rolled back
        $preview = FiscalPreview::where('operation_id', $operationId)->first();
        $this->assertNotNull($preview->consumed_at);

        // 4. Querying by identity returns 200 with status=unknown
        $getRes = $this->withToken($this->companyToken)
            ->getJson("/api/fiscal-operations/{$operationId}")
            ->assertOk();
        $this->assertSame('unknown', $getRes->json('status'));
        $this->assertSame('GATEWAY_COMMUNICATION_ERROR', $getRes->json('document.provider_code'));
        $this->assertSame('GATEWAY_COMMUNICATION_ERROR', $getRes->json('error.code'));

        $this->withToken($this->companyToken)
            ->postJson('/api/fiscal-operations/issue', [
                'contract_version' => '1',
                'operation_id' => $operationId,
                'idempotency_key' => $idempotencyKey,
                'document_type' => '01',
                'confirmation' => [
                    'actor_id' => 'actor_crash_1',
                    'confirmed_at' => '2026-10-05T20:00:00Z',
                    'preview_digest' => $previewDigest,
                ],
            ])
            ->assertOk()
            ->assertJsonPath('status', 'unknown');
        $this->assertSame(1, $throwingGateway->calls, 'Unknown operation must never dispatch twice');
    }

    public function test_payload_conflict_differs_from_preview_returns_409(): void
    {
        $operationId = 'op_conflict_1001';
        $idempotencyKey = 'idem_conflict_1001';

        // 1. Create preview with Customer A
        $previewRes = $this->withToken($this->companyToken)
            ->postJson('/api/fiscal-previews', [
                'contract_version' => '1',
                'operation_id' => $operationId,
                'idempotency_key' => $idempotencyKey,
                'draft_revision' => 1,
                'document_type' => '01',
                'issue_date' => '2026-10-05',
                'currency' => 'PEN',
                'tax_mode' => 'included',
                'customer' => [
                    'document_type' => '6',
                    'name' => 'Cliente Original S.A.C.',
                    'number' => '20111111112',
                ],
                'items' => [
                    [
                        'description' => 'Servicio Original',
                        'quantity' => '1.000',
                        'total_price' => '118.00',
                    ],
                ],
            ])
            ->assertCreated();

        $previewDigest = $previewRes->json('data.preview_digest');

        // 2. Try to issue with Customer B in payload -> 409 preview_payload_mismatch
        $this->withToken($this->companyToken)
            ->postJson('/api/fiscal-operations/issue', [
                'contract_version' => '1',
                'operation_id' => $operationId,
                'idempotency_key' => $idempotencyKey,
                'document_type' => '01',
                'confirmation' => [
                    'actor_id' => 'actor_test',
                    'confirmed_at' => '2026-10-05T20:00:00Z',
                    'preview_digest' => $previewDigest,
                ],
                'payload' => [
                    'customer' => [
                        'document_type' => '6',
                        'name' => 'Cliente Modificado S.A.C.',
                        'number' => '20999999999',
                    ],
                ],
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'preview_payload_mismatch');
    }

    public function test_concurrent_issue_claim_cas_prevents_duplicate_dispatch(): void
    {
        $operationId = 'op_concur_1001';
        $idempotencyKey = 'idem_concur_1001';

        $preview = FiscalPreview::create([
            'sunat_environment' => 'beta',
            'request_hash' => str_repeat('a', 64),
            'company_id' => $this->company->id,
            'operation_id' => $operationId,
            'idempotency_key' => $idempotencyKey,
            'draft_revision' => 1,
            'document_type' => '01',
            'issue_date' => '2026-10-05',
            'currency' => 'PEN',
            'tax_mode' => 'included',
            'payload_json' => [
                'contract_version' => '1',
                'document_type' => '01',
                'customer' => ['document_type' => '6', 'name' => 'Test S.A.C.', 'number' => '20555555555'],
                'items' => [['description' => 'Item 1', 'quantity' => '1.000', 'total_price' => '118.00']],
            ],
            'lines_json' => [
                ['description' => 'Item 1', 'quantity' => '1.000', 'unit_value' => '100.00', 'unit_price_with_igv' => '118.00', 'line_base' => '100.00', 'igv' => '18.00', 'line_total' => '118.00'],
            ],
            'totals_json' => ['subtotal' => '100.00', 'igv' => '18.00', 'total' => '118.00'],
            'preview_digest' => 'sha256:'.str_repeat('a', 64),
            'valid_until' => now()->addMinutes(15),
            'calculation_version' => 1,
            'consumed_at' => now(),
        ]);

        $requestHash = CanonicalJson::digest([
            'company_id' => $this->company->id,
            'environment' => 'beta',
            'operation_id' => $operationId,
            'idempotency_key' => $idempotencyKey,
            'document_type' => '01',
            'preview_id' => $preview->id,
            'preview_digest' => $preview->preview_digest,
            'confirmation_actor' => 'actor_1',
            'confirmation_confirmed_at' => '2026-10-05T20:00:00Z',
            'payload' => $preview->payload_json,
        ]);

        // Operation already in processing status
        FiscalOperation::create([
            'preview_digest' => 'sha256:'.str_repeat('a', 64),
            'company_id' => $this->company->id,
            'operation_id' => $operationId,
            'idempotency_key' => $idempotencyKey,
            'document_type' => '01',
            'sunat_environment' => 'beta',
            'preview_id' => $preview->id,
            'preview_digest' => $preview->preview_digest,
            'request_hash' => $requestHash,
            'status' => 'processing',
            'payload_json' => $preview->payload_json,
            'confirmation_json' => ['actor_id' => 'actor_1', 'confirmed_at' => '2026-10-05T20:00:00Z'],
            'totals_json' => $preview->totals_json,
        ]);

        // Re-issuing with same key returns current processing state without re-dispatching
        $issueRes = $this->withToken($this->companyToken)
            ->postJson('/api/fiscal-operations/issue', [
                'contract_version' => '1',
                'operation_id' => $operationId,
                'idempotency_key' => $idempotencyKey,
                'document_type' => '01',
                'confirmation' => [
                    'actor_id' => 'actor_1',
                    'confirmed_at' => '2026-10-05T20:00:00Z',
                    'preview_digest' => $preview->preview_digest,
                ],
            ])
            ->assertOk();

        $this->assertSame('processing', $issueRes->json('status'));
    }

    public function test_company_token_cannot_declare_not_issued_even_with_operator_ref_and_verified_true(): void
    {
        $operationId = 'op_forgery_company_1001';

        FiscalOperation::create([
            'preview_digest' => 'sha256:'.str_repeat('a', 64),
            'company_id' => $this->company->id,
            'operation_id' => $operationId,
            'idempotency_key' => 'idem_forgery_company_1001',
            'document_type' => '01',
            'sunat_environment' => 'beta',
            'request_hash' => 'hash_test',
            'status' => 'unknown',
            'payload_json' => [],
            'confirmation_json' => [],
            'totals_json' => ['total' => '118.00'],
        ]);

        // Company token attempting manual resolution must be strictly forbidden (403)
        $this->withToken($this->companyToken)
            ->postJson("/api/fiscal-operations/{$operationId}/reconcile", [
                'evidence_ref' => 'fraud_claim_99',
                'operator_ref' => 'forged_operator_01',
                'verified' => true,
            ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'manual_resolution_forbidden_for_company_token');

        // Status must remain completely unchanged in DB
        $op = FiscalOperation::where('operation_id', $operationId)->first();
        $this->assertSame('unknown', $op->status);
        $this->assertNull($op->reconciliation_evidence);
    }

    public function test_admin_declare_not_issued_requires_platform_admin_token(): void
    {
        $operationId = 'op_admin_auth_1001';

        FiscalOperation::create([
            'preview_digest' => 'sha256:'.str_repeat('a', 64),
            'company_id' => $this->company->id,
            'operation_id' => $operationId,
            'idempotency_key' => 'idem_admin_auth_1001',
            'document_type' => '01',
            'sunat_environment' => 'beta',
            'request_hash' => 'hash_test',
            'status' => 'unknown',
            'payload_json' => [],
            'confirmation_json' => [],
            'totals_json' => ['total' => '118.00'],
        ]);

        // 1. Without any token -> 401
        $this->postJson("/api/admin/fiscal-operations/{$operationId}/declare-not-issued", [
            'evidence_ref' => 'sunat_ticket_absent_44',
        ])->assertStatus(401);

        // 2. With company token -> 401 (platform admin token required)
        $this->withToken($this->companyToken)
            ->postJson("/api/admin/fiscal-operations/{$operationId}/declare-not-issued", [
                'evidence_ref' => 'sunat_ticket_absent_44',
            ])->assertStatus(401);
    }

    public function test_admin_can_declare_not_issued_with_verified_evidence(): void
    {
        $operationId = 'op_reconcile_admin_1001';

        FiscalOperation::create([
            'preview_digest' => 'sha256:'.str_repeat('a', 64),
            'company_id' => $this->company->id,
            'operation_id' => $operationId,
            'idempotency_key' => 'idem_reconcile_admin_1001',
            'document_type' => '01',
            'sunat_environment' => 'beta',
            'request_hash' => 'hash_test',
            'status' => 'unknown',
            'payload_json' => [],
            'confirmation_json' => [],
            'totals_json' => ['total' => '118.00'],
        ]);

        $res = $this->withToken('platform-admin-secret-token')
            ->postJson("/api/admin/fiscal-operations/{$operationId}/declare-not-issued", [
                'evidence_ref' => 'sunat_ticket_absent_44',
                'operator_ref' => 'admin_operator_01',
                'notes' => 'Comprobante verificado ausente en portal SUNAT',
            ])
            ->assertOk();

        $this->assertSame('not_issued', $res->json('status'));

        $op = FiscalOperation::where('operation_id', $operationId)->first();
        $this->assertSame('not_issued', $op->status);
        $this->assertSame('sunat_ticket_absent_44', $op->reconciliation_evidence['evidence_ref']);
        $this->assertSame('admin_operator_01', $op->reconciliation_evidence['operator_ref']);
        $this->assertTrue($op->reconciliation_evidence['verified']);
        $this->assertSame('platform.admin', $op->reconciliation_evidence['authorized_by']);
    }

    public function test_admin_cannot_declare_not_issued_if_already_accepted_cas(): void
    {
        $operationId = 'op_cas_accepted_1001';

        FiscalOperation::create([
            'preview_digest' => 'sha256:'.str_repeat('a', 64),
            'company_id' => $this->company->id,
            'operation_id' => $operationId,
            'idempotency_key' => 'idem_cas_accepted_1001',
            'document_type' => '01',
            'sunat_environment' => 'beta',
            'request_hash' => 'hash_test',
            'status' => 'accepted',
            'payload_json' => [],
            'confirmation_json' => [],
            'totals_json' => ['total' => '118.00'],
        ]);

        $this->withToken('platform-admin-secret-token')
            ->postJson("/api/admin/fiscal-operations/{$operationId}/declare-not-issued", [
                'evidence_ref' => 'sunat_ticket_absent_44',
                'operator_ref' => 'admin_operator_01',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'operation_already_accepted');

        $op = FiscalOperation::where('operation_id', $operationId)->first();
        $this->assertSame('accepted', $op->status);
    }

    public function test_mismatch_confirmation_hour_fails_idempotency_request_hash(): void
    {
        $operationId = 'op_hour_mismatch_1001';
        $idempotencyKey = 'idem_hour_mismatch_1001';

        // 1. Create preview
        $previewRes = $this->withToken($this->companyToken)
            ->postJson('/api/fiscal-previews', [
                'contract_version' => '1',
                'operation_id' => $operationId,
                'idempotency_key' => $idempotencyKey,
                'draft_revision' => 1,
                'document_type' => '01',
                'issue_date' => '2026-10-05',
                'currency' => 'PEN',
                'tax_mode' => 'included',
                'customer' => ['document_type' => '6', 'name' => 'Cliente S.A.C.', 'number' => '20555555555'],
                'items' => [['description' => 'Servicio', 'quantity' => '1.000', 'total_price' => '118.00']],
            ])
            ->assertCreated();

        $previewDigest = $previewRes->json('data.preview_digest');

        // 2. Issue with confirmed_at 20:00:00Z
        $this->withToken($this->companyToken)
            ->postJson('/api/fiscal-operations/issue', [
                'contract_version' => '1',
                'operation_id' => $operationId,
                'idempotency_key' => $idempotencyKey,
                'document_type' => '01',
                'confirmation' => [
                    'actor_id' => 'actor_1',
                    'confirmed_at' => '2026-10-05T20:00:00Z',
                    'preview_digest' => $previewDigest,
                ],
            ])
            ->assertCreated();

        // 3. Replay with different confirmed_at timestamp (20:00:01Z) -> 409 conflict
        $this->withToken($this->companyToken)
            ->postJson('/api/fiscal-operations/issue', [
                'contract_version' => '1',
                'operation_id' => $operationId,
                'idempotency_key' => $idempotencyKey,
                'document_type' => '01',
                'confirmation' => [
                    'actor_id' => 'actor_1',
                    'confirmed_at' => '2026-10-05T20:00:01Z',
                    'preview_digest' => $previewDigest,
                ],
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'idempotency_conflict');
    }

    public function test_pdf_download_pending_or_unaccepted_operation_returns_404(): void
    {
        $operationId = 'op_unaccepted_pdf_1001';

        FiscalOperation::create([
            'preview_digest' => 'sha256:'.str_repeat('a', 64),
            'company_id' => $this->company->id,
            'operation_id' => $operationId,
            'idempotency_key' => 'idem_unaccepted_pdf_1001',
            'document_type' => '01',
            'sunat_environment' => 'beta',
            'request_hash' => 'hash_test',
            'status' => 'processing',
            'payload_json' => [],
            'confirmation_json' => [],
            'totals_json' => ['total' => '118.00'],
        ]);

        $this->withToken($this->companyToken)
            ->get("/api/fiscal-operations/{$operationId}/files/pdf")
            ->assertStatus(404)
            ->assertJsonPath('code', 'file_not_found');

        // And in show(), artifacts list is empty
        $showRes = $this->withToken($this->companyToken)
            ->getJson("/api/fiscal-operations/{$operationId}")
            ->assertOk();
        $this->assertEmpty($showRes->json('artifacts'));
    }

    public function test_admin_cannot_resolve_an_inflight_processing_operation(): void
    {
        FiscalOperation::create([
            'company_id' => $this->company->id,
            'operation_id' => 'op_inflight_guard',
            'idempotency_key' => 'idem_inflight_guard',
            'document_type' => '01',
            'sunat_environment' => 'beta',
            'preview_digest' => 'sha256:'.str_repeat('a', 64),
            'request_hash' => str_repeat('a', 64),
            'status' => 'processing',
            'payload_json' => [],
            'confirmation_json' => [],
        ]);
        $this->withToken('platform-admin-secret-token')
            ->postJson('/api/admin/fiscal-operations/op_inflight_guard/declare-not-issued', [
                'evidence_ref' => 'synthetic_evidence',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'operation_not_uncertain');
        $this->assertSame('processing', FiscalOperation::where('operation_id', 'op_inflight_guard')->first()->status);
    }

    private function createCompany(string $ruc, string $name): array
    {
        $company = Company::create([
            'ruc' => $ruc,
            'legal_name' => $name,
            'trade_name' => $name,
            'ubigeo' => '150101',
            'department' => 'LIMA',
            'province' => 'LIMA',
            'district' => 'LIMA',
            'address' => 'Av. Prueba 123',
            'sunat_driver' => 'fake',
            'sunat_environment' => 'beta',
            'default_series' => 'F001',
            'default_boleta_series' => 'B001',
            'default_credit_note_series' => 'FC01',
            'default_boleta_credit_note_series' => 'BC01',
            'active' => true,
        ]);
        $issued = app(CompanyApiTokenService::class)->create($company, 'Pruebas');

        return [$company, $issued['plain_text']];
    }
}
