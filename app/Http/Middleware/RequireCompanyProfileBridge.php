<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autenticación M2M del perfil empresarial interno.
 *
 * - Deshabilitado salvo `enabled === true` estricto (503).
 * - HTTPS obligatorio; excepción de desarrollo con peer real (REMOTE_ADDR) y
 *   host loopback a la vez, igual que el resto de servicios internos.
 * - Bearer dedicado `FISCAL_PROFILE_BRIDGE_TOKEN` (ASCII imprimible sin
 *   espacios, 1..512) comparado en tiempo constante.
 * - Sin logs ni cuerpos en respuestas: errores opacos.
 */
final class RequireCompanyProfileBridge
{
    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '::1', '[::1]'];

    private const MAX_TOKEN_LENGTH = 512;

    public function handle(Request $request, Closure $next): Response
    {
        if (config('company_profile.enabled') !== true) {
            return response()->json(['error' => 'company_profile_disabled'], 503);
        }

        if (! $this->isSecureTransport($request)) {
            return response()->json(['error' => 'https_required'], 403);
        }

        $expected = $this->configuredBridgeToken();

        if ($expected === null) {
            return response()->json(['error' => 'company_profile_bridge_not_configured'], 503);
        }

        $provided = $request->bearerToken();

        if (! is_string($provided) || $provided === '' || ! hash_equals($expected, $provided)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        return $next($request);
    }

    private function configuredBridgeToken(): ?string
    {
        $token = config('company_profile.bridge_token');

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

        if (config('company_profile.allow_local_http') !== true) {
            return false;
        }

        $peer = $request->server->get('REMOTE_ADDR');

        if (! is_string($peer) || $peer === '') {
            return false;
        }

        $isLoopbackPeer = $peer === '::1'
            || str_starts_with($peer, '127.')
            || str_starts_with($peer, '::ffff:127.');

        if (! $isLoopbackPeer) {
            return false;
        }

        return in_array($request->getHost(), self::LOOPBACK_HOSTS, true);
    }
}
