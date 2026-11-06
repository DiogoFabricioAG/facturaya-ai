<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDespatchRequest;
use App\Models\Company;
use App\Models\Despatch;
use App\Models\InvoiceSequence;
use App\Services\CompanyContext;
use App\Services\DespatchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DespatchController extends Controller
{
    public function credentials(Request $request, Company $company)
    {
        $values = $request->validate(['gre_enabled' => ['required', 'boolean'],
            'gre_client_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'gre_client_secret' => ['sometimes', 'nullable', 'string', 'max:1024'],
            'next_number' => ['sometimes', 'integer', 'min:1', 'max:99999999']]);
        DB::transaction(function () use ($company, $values): void {
            $locked = Company::query()->lockForUpdate()->findOrFail($company->id);
            $locked->fill(array_diff_key($values, ['next_number' => true]));
            abort_if($locked->gre_enabled && (blank($locked->gre_client_id) || blank($locked->gre_client_secret)), 422, 'Completa ambas credenciales API GRE.');
            $sequence = InvoiceSequence::where('company_id', $locked->id)->where('sunat_environment', 'production')->where('series', 'T001')->lockForUpdate()->first();
            abort_if($locked->gre_enabled && ! $sequence && ! isset($values['next_number']), 422,
                'Confirma el próximo correlativo de la serie T001 antes de habilitar GRE.');
            if (isset($values['next_number'])) {
                abort_if($sequence && $values['next_number'] < $sequence->next_number, 422, 'El correlativo no puede retroceder.');
                InvoiceSequence::updateOrCreate(['company_id' => $locked->id, 'sunat_environment' => 'production', 'series' => 'T001'], ['next_number' => $values['next_number']]);
            }
            $locked->gre_revision = (int) $locked->gre_revision + 1;
            $locked->save();
        });

        return response()->json(['data' => ['gre_enabled' => $company->fresh()->gre_enabled,
            'gre_credentials_configured' => filled($company->fresh()->gre_client_id) && filled($company->fresh()->gre_client_secret)]]);
    }

    public function index(CompanyContext $context)
    {
        return response()->json(Despatch::where('company_id', $context->company()->id)->latest()->paginate(20));
    }

    public function store(StoreDespatchRequest $request, CompanyContext $context, DespatchService $service)
    {
        return $this->response($service->draft($context->company(), $request->validated()), 201);
    }

    public function show(Despatch $despatch, CompanyContext $context)
    {
        abort_unless($context->owns($despatch->company_id), 404);

        return $this->response($despatch);
    }

    public function issue(Request $request, Despatch $despatch, CompanyContext $context, DespatchService $service)
    {
        abort_unless($context->owns($despatch->company_id), 404);
        $data = $request->validate(['confirmed' => ['required', 'accepted'], 'confirmation_digest' => ['required', 'string', 'size:64']]);

        return $this->response($service->issue($context->company(), $despatch, $data['confirmation_digest']));
    }

    public function refresh(Despatch $despatch, CompanyContext $context, DespatchService $service)
    {
        abort_unless($context->owns($despatch->company_id), 404);

        return $this->response($service->refresh($context->company(), $despatch));
    }

    public function file(Despatch $despatch, string $type, CompanyContext $context)
    {
        abort_unless($context->owns($despatch->company_id), 404);
        $path = $type === 'xml' ? $despatch->xml_path : $despatch->cdr_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path);
    }

    private function response(Despatch $guide, int $status = 200)
    {
        return response()->json(['data' => [...$guide->toArray(), 'is_demo' => $guide->driver === 'fake',
            'document_type' => '09', 'reason_code' => '01', 'transport_mode' => '02']], $status);
    }
}
