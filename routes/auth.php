<?php

use App\Services\AdminSignIn;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Js;
use Laravel\WorkOS\Http\Middleware\ValidateSessionWithWorkOS;
use Laravel\WorkOS\Http\Requests\AuthKitAuthenticationRequest;
use Laravel\WorkOS\Http\Requests\AuthKitLoginRequest;
use Laravel\WorkOS\Http\Requests\AuthKitLogoutRequest;

Route::middleware(['guest'])->group(function () {
    Route::get('login', function (AuthKitLoginRequest $request) {
        // wire:navigate loads pages with a background fetch, and browsers block
        // that fetch from following a redirect to WorkOS on another domain.
        // Livewire then silently gives up, so a link clicked after the session
        // expired would do nothing. Answer with a same-origin page that makes
        // the browser load this URL for real instead.
        if ($request->hasHeader('X-Livewire-Navigate')) {
            return response('<!DOCTYPE html><html><head><script>window.location.replace('.Js::from(route('login')).')</script></head><body></body></html>');
        }

        return $request->redirect();
    })->name('login');

    Route::get('authenticate', function (AuthKitAuthenticationRequest $request, AdminSignIn $adminSignIn) {
        $user = $request->authenticate(
            $adminSignIn->find(...),
            $adminSignIn->create(...),
            $adminSignIn->update(...),
        );

        return $user->isApproved()
            ? redirect()->intended(route('dashboard'))
            : redirect()->route('pending-approval');
    });
});

Route::livewire('pending-approval', 'pages::admin.pending-approval')
    ->middleware(['auth', ValidateSessionWithWorkOS::class])
    ->name('pending-approval');

Route::post('logout', fn (AuthKitLogoutRequest $request) => $request->logout())
    ->middleware(['auth'])->name('logout');
