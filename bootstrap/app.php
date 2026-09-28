<?php

use App\Http\Middleware\EnsureStaffBelongsToStore;
use App\Http\Middleware\IdentifyStore;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'store' => IdentifyStore::class,
            'store.member' => EnsureStaffBelongsToStore::class,
        ]);

        // Signed-out visitors to a store's portal belong on that store's own
        // sign-in page, not the platform admin login.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->route('store')
            ? route('portal.login', ['store' => $request->route('store')])
            : route('login'));

        $middleware->validateCsrfTokens(except: [
            'stripe/webhook',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
