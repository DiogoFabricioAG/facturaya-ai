<?php

use App\Http\Middleware\AuthenticateCompany;
use App\Http\Middleware\RequirePlatformAdmin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // En producción solo Nginx/Caddy alcanzan PHP-FPM; confiar en el proxy
        // permite que Laravel detecte correctamente HTTPS y el host original.
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'company.auth' => AuthenticateCompany::class,
            'platform.admin' => RequirePlatformAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
