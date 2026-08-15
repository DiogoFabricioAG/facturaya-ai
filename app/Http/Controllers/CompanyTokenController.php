<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CompanyApiToken;
use App\Services\CompanyApiTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyTokenController extends Controller
{
    public function store(Request $request, Company $company, CompanyApiTokenService $tokens): JsonResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:100']]);
        $issued = $tokens->create($company, $validated['name']);

        return response()->json([
            'id' => $issued['token']->id,
            'name' => $issued['token']->name,
            'api_token' => $issued['plain_text'],
            'message' => 'Guarda el api_token ahora; no volverá a mostrarse.',
        ], 201);
    }

    public function destroy(Company $company, CompanyApiToken $companyApiToken): JsonResponse
    {
        abort_unless($companyApiToken->company_id === $company->id, 404);
        $companyApiToken->update(['revoked_at' => now()]);

        return response()->json(['message' => 'Token revocado.']);
    }
}
