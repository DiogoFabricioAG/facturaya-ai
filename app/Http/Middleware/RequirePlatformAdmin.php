<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $configured = (string) config('facturaya.platform.admin_token');
        $provided = (string) $request->bearerToken();

        if ($configured === '') {
            return response()->json(['message' => 'PLATFORM_ADMIN_TOKEN no está configurado.'], 503);
        }

        if ($provided === '' || ! hash_equals($configured, $provided)) {
            return response()->json(['message' => 'Token de administración inválido.'], 401);
        }

        return $next($request);
    }
}
