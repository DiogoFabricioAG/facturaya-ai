<?php

namespace App\Http\Controllers;

use App\Contracts\DocumentExtractor;
use App\Http\Requests\ImportInvoiceDraftRequest;
use App\Http\Requests\UpdateInvoiceDraftRequest;
use App\Http\Resources\InvoiceDraftResource;
use App\Models\InvoiceDraft;
use App\Services\CompanyContext;
use App\Services\InvoiceDraftCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class InvoiceDraftController extends Controller
{
    public function index(Request $request, CompanyContext $context): AnonymousResourceCollection
    {
        $perPage = min(max($request->integer('per_page', 10), 1), 100);
        $drafts = InvoiceDraft::query()
            ->where('company_id', $context->company()->id)
            ->with(['company', 'invoice.creditNotes'])
            ->latest()
            ->paginate($perPage);

        return InvoiceDraftResource::collection($drafts);
    }

    public function store(
        ImportInvoiceDraftRequest $request,
        DocumentExtractor $extractor,
        InvoiceDraftCalculator $calculator,
        CompanyContext $context,
    ): JsonResponse {
        $company = $context->company();
        $sourceText = trim($request->string('products_text')->value()) ?: null;
        $file = $request->file('file');
        $source = $this->storeSources($company->id, $sourceText, $file);

        $draft = InvoiceDraft::create([
            'company_id' => $company->id,
            ...$request->safe()->only(['customer_ruc', 'customer_name', 'issue_date', 'tax_mode']),
            'currency' => 'PEN',
            'status' => 'analyzing',
            'source_path' => $source['path'],
            'original_name' => $source['original_name'],
            'mime_type' => $source['mime_type'],
            'ai_driver' => (string) config('facturaya.ai.driver'),
        ]);

        try {
            $extraction = $extractor->extract($sourceText, $file);
            $items = $this->validatedExtractionItems($extraction);

            $draft->update([
                'currency' => Arr::get($extraction, 'currency', 'PEN'),
                'raw_extraction' => $extraction,
                'warnings' => Arr::get($extraction, 'warnings', []),
                'error_message' => null,
            ]);

            $calculator->replaceItems($draft, $items, $draft->tax_mode);
        } catch (Throwable $exception) {
            report($exception);
            $draft->update([
                'status' => 'failed',
                'error_message' => $exception instanceof ValidationException
                    ? 'El documento no contenía líneas de producto válidas.'
                    : $exception->getMessage(),
            ]);

            return (new InvoiceDraftResource($draft->fresh()->load(['company', 'items', 'invoice'])))
                ->response()
                ->setStatusCode(422);
        }

        return (new InvoiceDraftResource($draft->fresh()->load(['company', 'items', 'invoice'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * @return array{path: string, original_name: string, mime_type: string}
     */
    private function storeSources(string $companyId, ?string $text, ?UploadedFile $file): array
    {
        $baseDirectory = 'companies/'.$companyId.'/invoice-sources';

        if ($text !== null && $file === null) {
            $path = $baseDirectory.'/'.Str::ulid().'.txt';

            if (! Storage::disk('local')->put($path, $text)) {
                throw new \RuntimeException('No se pudo guardar el texto de origen.');
            }

            return [
                'path' => $path,
                'original_name' => 'productos-escritos.txt',
                'mime_type' => 'text/plain',
            ];
        }

        if ($text === null) {
            $path = $file->store($baseDirectory, 'local');

            return [
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType() ?: $file->getClientMimeType(),
            ];
        }

        $bundleDirectory = $baseDirectory.'/'.Str::ulid();
        $textPath = $bundleDirectory.'/productos-escritos.txt';
        $fileName = 'documento.'.($file->getClientOriginalExtension() ?: 'bin');
        $filePath = $file->storeAs($bundleDirectory, $fileName, 'local');
        $manifestPath = $bundleDirectory.'/fuentes.json';

        $manifest = [
            'type' => 'mixed',
            'text_path' => $textPath,
            'file_path' => $filePath,
            'file_original_name' => $file->getClientOriginalName(),
            'file_mime_type' => $file->getMimeType() ?: $file->getClientMimeType(),
        ];

        if ($filePath === false
            || ! Storage::disk('local')->put($textPath, $text)
            || ! Storage::disk('local')->put($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))) {
            Storage::disk('local')->deleteDirectory($bundleDirectory);
            throw new \RuntimeException('No se pudieron guardar todas las fuentes del borrador.');
        }

        return [
            'path' => $manifestPath,
            'original_name' => 'Texto + '.$file->getClientOriginalName(),
            'mime_type' => 'application/vnd.facturaya.source-bundle+json',
        ];
    }

    public function show(InvoiceDraft $invoiceDraft, CompanyContext $context): InvoiceDraftResource
    {
        abort_unless($context->owns($invoiceDraft->company_id), 404);

        return new InvoiceDraftResource($invoiceDraft->load(['company', 'items', 'invoice.creditNotes']));
    }

    public function update(
        UpdateInvoiceDraftRequest $request,
        InvoiceDraft $invoiceDraft,
        InvoiceDraftCalculator $calculator,
        CompanyContext $context,
    ): InvoiceDraftResource|JsonResponse {
        abort_unless($context->owns($invoiceDraft->company_id), 404);

        if ($invoiceDraft->invoice()->exists()) {
            return response()->json(['message' => 'Esta factura ya fue emitida y no puede modificarse.'], 409);
        }

        $invoiceDraft->update($request->safe()->only([
            'customer_ruc',
            'customer_name',
            'issue_date',
            'tax_mode',
            'currency',
        ]));

        $calculator->replaceItems($invoiceDraft, $request->validated('items'), $invoiceDraft->tax_mode);

        return new InvoiceDraftResource($invoiceDraft->fresh()->load(['company', 'items', 'invoice']));
    }

    private function validatedExtractionItems(array $extraction): array
    {
        return Validator::make($extraction, [
            'currency' => ['required', 'in:PEN,USD'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.description' => ['required', 'string', 'max:500'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['required', 'numeric', 'gte:0'],
            'items.*.confidence' => ['nullable', 'numeric', 'between:0,1'],
            'items.*.source_page' => ['nullable', 'integer', 'min:1'],
        ])->validate()['items'];
    }
}
