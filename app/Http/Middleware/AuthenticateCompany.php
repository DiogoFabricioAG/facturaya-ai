<?php

namespace App\Http\Middleware;

use App\Models\CompanyApiToken;
use App\Services\CompanyContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateCompany
{
    public function __construct(private readonly CompanyContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $plainText = $request->bearerToken();

        if (! $plainText) {
            return response()->json(['message' => 'Falta el token de la empresa.'], 401);
        }

        $token = CompanyApiToken::query()
            ->with('company')
            ->where('token_hash', hash('sha256', $plainText))
            ->whereNull('revoked_at')
            ->first();

        if (! $token || ! $token->company->active) {
            return response()->json(['message' => 'El token de empresa no es válido o fue revocado.'], 401);
        }

        $this->context->set($token->company);
        $request->attributes->set('company', $token->company);
        $token->forceFill(['last_used_at' => now()])->saveQuietly();

        return $next($request);
    }
}
