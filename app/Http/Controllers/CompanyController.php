<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCompanyRequest;
use App\Http\Requests\UpdateCompanyRequest;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use App\Services\CompanyApiTokenService;
use App\Services\CompanyCertificateStore;
use App\Services\CompanyProfileSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

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
        CompanyProfileSyncService $profiles,
    ): JsonResponse {
        [$company, $issued] = DB::transaction(function () use ($request, $certificates, $tokens, $profiles): array {
            $company = Company::create($request->safe()->except([
                'certificate',
                'certificate_password',
                'token_name',
            ]));

            $newCertificatePath = null;

            try {
                if ($request->hasFile('certificate')) {
                    $newCertificatePath = $certificates->store(
                        $company,
                        $request->file('certificate'),
                        $request->string('certificate_password')->value(),
                    );

                    $company->update(['certificate_path' => $newCertificatePath]);
                }

                if ($company->hasSunatCredentials()) {
                    $profiles->refresh($company);
                }

                $issued = $tokens->create($company, $request->string('token_name')->value() ?: 'Token inicial');
            } catch (Throwable $exception) {
                if (is_string($newCertificatePath)) {
                    $certificates->discard($newCertificatePath);
                }

                throw $exception;
            }

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
        CompanyProfileSyncService $profiles,
    ): CompanyResource {
        return DB::transaction(function () use ($request, $company, $certificates, $profiles): CompanyResource {
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

            // La recarga del certificado cuenta como cambio fiscal aunque el DER
            // público no varíe: la frase de paso puede haber rotado y la versión
            // de perfil debe invalidar la evidencia previa.
            $fiscalTouched = $request->hasFile('certificate')
                || (array_key_exists('ruc', $validated) && $validated['ruc'] !== $company->ruc)
                || (array_key_exists('sunat_environment', $validated) && $validated['sunat_environment'] !== $company->sunat_environment)
                || (array_key_exists('sol_user', $validated) && (string) $validated['sol_user'] !== (string) $company->sol_user)
                || (
                    array_key_exists('sol_password', $validated)
                    && filled($validated['sol_password'])
                    && $validated['sol_password'] !== $company->sol_password
                );

            $newCertificatePath = null;

            try {
                if ($request->hasFile('certificate')) {
                    $newCertificatePath = $certificates->store(
                        $company,
                        $request->file('certificate'),
                        $request->string('certificate_password')->value(),
                    );

                    $validated['certificate_path'] = $newCertificatePath;
                }

                $company->update($validated);

                if ($fiscalTouched) {
                    $company->forceFill(['fiscal_revision' => (int) $company->fiscal_revision + 1])->save();
                    $profiles->refresh($company);
                }
            } catch (Throwable $exception) {
                if (is_string($newCertificatePath)) {
                    $certificates->discard($newCertificatePath);
                }

                throw $exception;
            }

            return new CompanyResource($company->fresh());
        });
    }
}
