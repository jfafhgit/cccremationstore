<?php

use App\Services\AdminSignIn;
use Illuminate\Support\Facades\Route;
use Laravel\WorkOS\Http\Middleware\ValidateSessionWithWorkOS;
use Laravel\WorkOS\Http\Requests\AuthKitAuthenticationRequest;
use Laravel\WorkOS\Http\Requests\AuthKitLoginRequest;
use Laravel\WorkOS\Http\Requests\AuthKitLogoutRequest;

Route::middleware(['guest'])->group(function () {
    Route::get('login', fn (AuthKitLoginRequest $request) => $request->redirect())->name('login');

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
