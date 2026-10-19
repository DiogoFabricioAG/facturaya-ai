<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autenticación machine-to-machine del diagnóstico fiscal interno.
 *
 * - Deshabilitado salvo `enabled === true` estricto (503).
 * - HTTPS obligatorio; la única excepción de desarrollo exige
 *   `allow_local_http === true` y que peer real (REMOTE_ADDR, sin cabeceras de
 *   proxy) y host sean loopback a la vez. Nunca decide loopback con la IP
 *   reenviada por un proxy confiado.
 * - Token de servicio dedicado `FISCAL_DIAGNOSTICS_BRIDGE_TOKEN`: ASCII
 *   imprimible sin espacios (ord 33..126) de 1..512 caracteres, comparado en
 *   tiempo constante. Alineado con el transporte Python, que rechaza CR/LF/TAB
 *   y espacio. Nunca reutiliza PLATFORM_ADMIN_TOKEN.
 * - Sin logs ni cuerpo en respuestas: errores opacos únicamente.
 */
final class RequireFiscalDiagnosticsBridge
{
    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '::1', '[::1]'];

    private const MAX_TOKEN_LENGTH = 512;

    public function handle(Request $request, Closure $next): Response
    {
        if (config('fiscal_diagnostics.enabled') !== true) {
            return response()->json(['error' => 'fiscal_diagnostics_disabled'], 503);
        }

        if (! $this->isSecureTransport($request)) {
            return response()->json(['error' => 'https_required'], 403);
        }

        $expected = $this->configuredBridgeToken();

        if ($expected === null) {
            return response()->json(['error' => 'fiscal_diagnostics_bridge_not_configured'], 503);
        }

        $provided = $request->bearerToken();

        if (! is_string($provided) || $provided === '' || ! hash_equals($expected, $provided)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        return $next($request);
    }

    private function configuredBridgeToken(): ?string
    {
        $token = config('fiscal_diagnostics.bridge_token');

        if (! is_string($token) || $token === '' || strlen($token) > self::MAX_TOKEN_LENGTH) {
            return null;
        }

        if (preg_match('/[^\x21-\x7E]/', $token) === 1) {
            return null;
        }

        return $token;
    }

    private function isSecureTransport(Request $request): bool
    {
        if ($request->isSecure()) {
            return true;
        }

        if (config('fiscal_diagnostics.allow_local_http') !== true) {
            return false;
        }

        return $this->isLoopbackHost($request) && $this->isLoopbackPeer($request);
    }

    private function isLoopbackHost(Request $request): bool
    {
        return in_array($request->getHost(), self::LOOPBACK_HOSTS, true);
    }

    private function isLoopbackPeer(Request $request): bool
    {
        $peer = $request->server->get('REMOTE_ADDR');

        if (! is_string($peer) || $peer === '') {
            return false;
        }

        if ($peer === '::1' || str_starts_with($peer, '127.')) {
            return true;
        }

        return str_starts_with($peer, '::ffff:127.');
    }
}
