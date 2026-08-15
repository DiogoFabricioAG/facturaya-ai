<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCompanyRequest;
use App\Http\Requests\UpdateCompanyRequest;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use App\Services\CompanyApiTokenService;
use App\Services\CompanyCertificateStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompanyController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return CompanyResource::collection(Company::query()->latest()->paginate(50));
    }

    public function store(
        StoreCompanyRequest $request,
        CompanyCertificateStore $certificates,
        CompanyApiTokenService $tokens,
    ): JsonResponse {
        [$company, $issued] = DB::transaction(function () use ($request, $certificates, $tokens): array {
            $company = Company::create($request->safe()->except([
                'certificate',
                'certificate_password',
                'token_name',
            ]));

            if ($request->hasFile('certificate')) {
                $company->update([
                    'certificate_path' => $certificates->store(
                        $company,
                        $request->file('certificate'),
                        $request->string('certificate_password')->value(),
                    ),
                ]);
            }

            $issued = $tokens->create($company, $request->string('token_name')->value() ?: 'Token inicial');

            return [$company, $issued];
        });

        return response()->json([
            'data' => (new CompanyResource($company->fresh()))->resolve(),
            'api_token' => $issued['plain_text'],
            'message' => 'Guarda el api_token ahora; no volverá a mostrarse.',
        ], 201);
    }

    public function show(Company $company): CompanyResource
    {
        return new CompanyResource($company);
    }

    public function update(
        UpdateCompanyRequest $request,
        Company $company,
        CompanyCertificateStore $certificates,
    ): CompanyResource {
        $validated = $request->safe()->except(['certificate', 'certificate_password']);
        $driver = $validated['sunat_driver'] ?? $company->sunat_driver;
        $hasCertificate = $request->hasFile('certificate') || filled($company->certificate_path);
        $hasUser = array_key_exists('sol_user', $validated) ? filled($validated['sol_user']) : filled($company->sol_user);
        $hasPassword = array_key_exists('sol_password', $validated) ? filled($validated['sol_password']) : filled($company->sol_password);

        if ($driver === 'greenter' && (! $hasCertificate || ! $hasUser || ! $hasPassword)) {
            throw ValidationException::withMessages([
                'sunat_driver' => 'Para activar Greenter se requieren certificado digital, usuario SOL y contraseña SOL.',
            ]);
        }

        if ($request->hasFile('certificate')) {
            $validated['certificate_path'] = $certificates->store(
                $company,
                $request->file('certificate'),
                $request->string('certificate_password')->value(),
            );
        }

        $company->update($validated);

        return new CompanyResource($company->fresh());
    }
}
