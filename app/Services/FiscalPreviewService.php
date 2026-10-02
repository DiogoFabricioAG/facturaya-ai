<?php

namespace App\Services;

use App\Models\Company;
use App\Models\FiscalPreview;
use App\Support\CanonicalJson;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Vista previa fiscal no emisora de factura y boleta.
 *
 * No usa IA, ni gateway SUNAT, ni Greenter, ni correlativos, ni tablas de
 * emisión. Solo normaliza, calcula con IgvCalculator, guarda la vista previa
 * y responde con la misma forma que espera el adaptador de MeowLab.
 *
 * ``environment`` sale siempre de ``company.sunat_environment`` y la empresa
 * del token (CompanyContext), nunca del cuerpo. ``tax_mode`` lo envía el
 * llamador autenticado con el token de empresa, después de verificar las
 * preferencias del tenant; este servicio no lee ni escribe configuración
 * fiscal. Sincronizar esa configuración y su validación persistente sigue
 * pendiente.
 */
final class FiscalPreviewService
{
    public const PREVIEW_TTL_SECONDS = 900;

    private const SUPPORTED_CONTRACT_VERSION = '1';

    private const MAX_WHOLE_DIGITS = 12;

    public function __construct(private readonly IgvCalculator $calculator) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function preview(Company $company, array $input): array
    {
        $documentType = (string) ($input['document_type'] ?? '');

        if ($documentType === '07') {
            $this->fail(422, 'unsupported_document_type');
        }

        if ((string) ($input['contract_version'] ?? '') !== self::SUPPORTED_CONTRACT_VERSION) {
            $this->fail(422, 'unsupported_contract_version');
        }

        $environment = (string) $company->sunat_environment;

        if (! in_array($environment, ['beta', 'production'], true)) {
            $this->fail(500, 'sunat_environment_invalid');
        }

        $taxMode = (string) ($input['tax_mode'] ?? '');

        // Preferencia enviada por el llamador autenticado con el token de la
        // empresa (la plataforma la verifica antes). No se persiste como
        // configuración; el acabado fiscal la sincronizará más adelante.
        if (! in_array($taxMode, ['included', 'excluded'], true)) {
            throw ValidationException::withMessages([
                'tax_mode' => 'El modo de IGV no es válido.',
            ]);
        }

        if ((string) ($input['currency'] ?? '') !== 'PEN') {
            throw ValidationException::withMessages([
                'currency' => 'La vista previa del piloto solo opera en PEN.',
            ]);
        }

        $issueDate = (string) ($input['issue_date'] ?? '');
        $customer = $this->normalizeCustomer($documentType, (array) ($input['customer'] ?? []));
        $items = $this->normalizeItems((array) ($input['items'] ?? []));
        $calculated = $this->calculator->calculateDocumentFromTotals($items, $taxMode);
        $this->assertWireAmounts($calculated);

        $lines = [];

        foreach ($items as $index => $item) {
            $lines[] = ['description' => $item['description']] + $calculated['items'][$index];
        }

        $totals = [
            'subtotal' => $calculated['subtotal'],
            'igv' => $calculated['igv'],
            'total' => $calculated['total'],
        ];

        $payload = [
            'contract_version' => self::SUPPORTED_CONTRACT_VERSION,
            'document_type' => $documentType,
            'issue_date' => $issueDate,
            'currency' => 'PEN',
            'tax_mode' => $taxMode,
            'customer' => $customer,
            'items' => $items,
        ];

        $operationId = (string) $input['operation_id'];
        $idempotencyKey = (string) $input['idempotency_key'];
        $revision = (int) $input['draft_revision'];
        $requestHash = CanonicalJson::digest([
            'company_id' => $company->id,
            'environment' => $environment,
            'calculation_version' => IgvCalculator::CALCULATION_VERSION,
            'payload' => $payload,
        ]);

        $previewDigest = 'sha256:'.CanonicalJson::digest([
            'company_id' => $company->id,
            'environment' => $environment,
            'calculation_version' => IgvCalculator::CALCULATION_VERSION,
            'operation_id' => $operationId,
            'draft_revision' => $revision,
            'idempotency_key' => $idempotencyKey,
            'document_type' => $documentType,
            'issue_date' => $issueDate,
            'currency' => 'PEN',
            'tax_mode' => $taxMode,
            'customer' => $customer,
            'items' => $items,
            'lines' => $lines,
            'totals' => $totals,
        ]);

        return DB::transaction(function () use (
            $company,
            $operationId,
            $revision,
            $idempotencyKey,
            $requestHash,
            $environment,
            $documentType,
            $issueDate,
            $taxMode,
            $payload,
            $lines,
            $totals,
            $previewDigest,
        ): array {
            $lockedCompany = Company::query()
                ->whereKey($company->id)
                ->lockForUpdate()
                ->first();

            if (
                $lockedCompany === null
                || ! $lockedCompany->active
                || (string) $lockedCompany->sunat_environment !== $environment
            ) {
                $this->fail(409, 'preview_digest_mismatch');
            }

            $now = now('UTC');

            $existing = FiscalPreview::query()
                ->where('company_id', $company->id)
                ->where('operation_id', $operationId)
                ->where('draft_revision', $revision)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $existing->consumed_at !== null) {
                $this->fail(409, 'preview_consumed');
            }

            if ($existing !== null && $existing->superseded_at !== null) {
                $this->fail(409, 'preview_digest_mismatch');
            }

            $newerRevisionExists = FiscalPreview::query()
                ->where('company_id', $company->id)
                ->where('operation_id', $operationId)
                ->where('draft_revision', '>', $revision)
                ->exists();

            if ($newerRevisionExists) {
                $this->fail(409, 'preview_digest_mismatch');
            }

            if ($existing === null) {
                $inserted = DB::table('fiscal_previews')->insertOrIgnore([
                    'id' => (string) Str::ulid(),
                    'company_id' => $company->id,
                    'operation_id' => $operationId,
                    'idempotency_key' => $idempotencyKey,
                    'draft_revision' => $revision,
                    'document_type' => $documentType,
                    'sunat_environment' => $environment,
                    'calculation_version' => IgvCalculator::CALCULATION_VERSION,
                    'request_hash' => $requestHash,
                    'preview_digest' => $previewDigest,
                    'issue_date' => $issueDate,
                    'currency' => 'PEN',
                    'tax_mode' => $taxMode,
                    'payload_json' => $this->encode($payload),
                    'lines_json' => $this->encode($lines),
                    'totals_json' => $this->encode($totals),
                    'valid_until' => $now->copy()->addSeconds(self::PREVIEW_TTL_SECONDS)->utc()->toDateTimeString(),
                    'consumed_at' => null,
                    'superseded_at' => null,
                    'created_at' => $now->utc()->toDateTimeString(),
                    'updated_at' => $now->utc()->toDateTimeString(),
                ]);

                if ($inserted === 1) {
                    FiscalPreview::query()
                        ->where('company_id', $company->id)
                        ->where('operation_id', $operationId)
                        ->where('draft_revision', '<', $revision)
                        ->whereNull('superseded_at')
                        ->update([
                            'superseded_at' => $now->utc()->toDateTimeString(),
                            'updated_at' => $now->utc()->toDateTimeString(),
                        ]);
                }

                $existing = FiscalPreview::query()
                    ->where('company_id', $company->id)
                    ->where('operation_id', $operationId)
                    ->where('draft_revision', $revision)
                    ->lockForUpdate()
                    ->first();

                if ($existing === null) {
                    $keyOwner = FiscalPreview::query()
                        ->where('company_id', $company->id)
                        ->where('idempotency_key', $idempotencyKey)
                        ->lockForUpdate()
                        ->first();

                    if ($keyOwner !== null) {
                        $this->fail(409, 'idempotency_conflict');
                    }

                    throw new RuntimeException('fiscal_preview_persist_failed');
                }
            }

            if (
                $existing->request_hash !== $requestHash
                || $existing->idempotency_key !== $idempotencyKey
            ) {
                $this->fail(409, 'idempotency_conflict');
            }

            if ($existing->valid_until->utc()->lessThanOrEqualTo($now)) {
                $this->fail(409, 'preview_expired');
            }

            return $this->responseData($existing);
        });
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{document_type: string, name: string, number: string|null}
     */
    private function normalizeCustomer(string $documentType, array $raw): array
    {
        $type = (string) ($raw['document_type'] ?? '');
        $name = (string) ($raw['name'] ?? '');
        $number = array_key_exists('number', $raw) && $raw['number'] !== null
            ? (string) $raw['number']
            : null;

        if (! in_array($type, ['0', '1', '6'], true)) {
            throw ValidationException::withMessages([
                'customer.document_type' => 'El tipo de documento del cliente no es válido.',
            ]);
        }

        if (trim($name) === '') {
            throw ValidationException::withMessages([
                'customer.name' => 'El nombre del cliente es obligatorio.',
            ]);
        }

        if ($documentType === '01' && $type !== '6') {
            throw ValidationException::withMessages([
                'customer.document_type' => 'La factura requiere RUC del cliente.',
            ]);
        }

        if ($type === '0' && $number !== null) {
            throw ValidationException::withMessages([
                'customer.number' => 'La boleta anónima no debe incluir documento.',
            ]);
        }

        if ($type === '1' && ($number === null || preg_match('/^\d{8}$/', $number) !== 1)) {
            throw ValidationException::withMessages([
                'customer.number' => 'El DNI debe tener 8 dígitos.',
            ]);
        }

        if ($type === '6' && ($number === null || preg_match('/^\d{11}$/', $number) !== 1)) {
            throw ValidationException::withMessages([
                'customer.number' => 'El RUC debe tener 11 dígitos.',
            ]);
        }

        return [
            'document_type' => $type,
            'name' => $name,
            'number' => $number,
        ];
    }

    /**
     * @param  array<int|string, mixed>  $rawItems
     * @return array<int, array{description: string, quantity: string, total_price: string}>
     */
    private function normalizeItems(array $rawItems): array
    {
        $quantityPattern = '/^\d{1,'.self::MAX_WHOLE_DIGITS.'}(\.\d{1,3})?$/';
        $amountPattern = '/^\d{1,'.self::MAX_WHOLE_DIGITS.'}(\.\d{1,2})?$/';
        $items = [];

        foreach (array_values($rawItems) as $index => $item) {
            if (! is_array($item)) {
                throw ValidationException::withMessages([
                    "items.$index" => 'La línea no tiene el formato esperado.',
                ]);
            }

            $description = (string) ($item['description'] ?? '');
            $quantityRaw = (string) ($item['quantity'] ?? '');
            $totalRaw = (string) ($item['total_price'] ?? '');

            if (preg_match($quantityPattern, $quantityRaw) !== 1) {
                throw ValidationException::withMessages([
                    "items.$index.quantity" => 'La cantidad debe ser una cadena decimal con hasta 3 decimales.',
                ]);
            }

            if (preg_match($amountPattern, $totalRaw) !== 1) {
                throw ValidationException::withMessages([
                    "items.$index.total_price" => 'El total de línea debe ser una cadena decimal con hasta 2 decimales.',
                ]);
            }

            $quantity = BigDecimal::of($quantityRaw);
            $lineTotal = BigDecimal::of($totalRaw);

            if ($quantity->isLessThanOrEqualTo(BigDecimal::zero())) {
                throw ValidationException::withMessages([
                    "items.$index.quantity" => 'La cantidad debe ser mayor que cero.',
                ]);
            }

            if ($lineTotal->isLessThanOrEqualTo(BigDecimal::zero())) {
                throw ValidationException::withMessages([
                    "items.$index.total_price" => 'El total de línea debe ser mayor que cero.',
                ]);
            }

            $items[] = [
                'description' => $description,
                'quantity' => $quantity->toScale(3, RoundingMode::HalfUp)->__toString(),
                'total_price' => $lineTotal->toScale(2, RoundingMode::HalfUp)->__toString(),
            ];
        }

        if ($items === []) {
            throw ValidationException::withMessages([
                'items' => 'La vista previa requiere al menos una línea.',
            ]);
        }

        return $items;
    }

    /**
     * Los montos que viajan al adaptador no pueden exceder 12 dígitos
     * enteros, igual que la validación de entrada. Una suma que se salga se
     * rechaza con 422 antes de persistir.
     *
     * @param  array{subtotal: string, igv: string, total: string, items: array<int, array<string, string>>}  $calculated
     */
    private function assertWireAmounts(array $calculated): void
    {
        foreach ($calculated['items'] as $index => $line) {
            foreach (['line_base', 'igv', 'line_total'] as $key) {
                $this->assertWireAmount($line[$key], "items.$index.$key");
            }
        }

        foreach (['subtotal', 'igv', 'total'] as $key) {
            $this->assertWireAmount($calculated[$key], $key);
        }
    }

    private function assertWireAmount(string $value, string $field): void
    {
        if (preg_match('/^\d{1,12}\.\d{2}$/', $value) !== 1) {
            throw ValidationException::withMessages([
                $field => 'El importe excede el límite de 12 dígitos enteros del contrato.',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function responseData(FiscalPreview $preview): array
    {
        return [
            'id' => $preview->id,
            'operation_id' => $preview->operation_id,
            'company_id' => $preview->company_id,
            'environment' => $preview->sunat_environment,
            'draft_revision' => (int) $preview->draft_revision,
            'document_type' => $preview->document_type,
            'issue_date' => $preview->issue_date->format('Y-m-d'),
            'currency' => $preview->currency,
            'tax_mode' => $preview->tax_mode,
            'items' => array_map(static function (array $line): array {
                return [
                    'description' => $line['description'],
                    'quantity' => $line['quantity'],
                    'line_base' => $line['line_base'],
                    'igv' => $line['igv'],
                    'line_total' => $line['line_total'],
                    'unit_value' => $line['unit_value'],
                    'unit_price_with_igv' => $line['unit_price_with_igv'],
                ];
            }, $preview->lines_json),
            'totals' => $preview->totals_json,
            'preview_digest' => $preview->preview_digest,
            'preview_valid_until' => $preview->valid_until->utc()->toIso8601String(),
            'previewed_at' => $preview->created_at->utc()->toIso8601String(),
            'calculation_version' => (int) $preview->calculation_version,
            'warnings' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function encode(array $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    private function fail(int $status, string $code): never
    {
        throw new HttpResponseException(
            response()->json(['code' => $code, 'message' => $code], $status),
        );
    }
}
