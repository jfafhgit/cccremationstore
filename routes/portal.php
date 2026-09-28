<?php

use App\Http\Controllers\Portal\LocationSwitchController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::prefix('portal')->name('portal.')->group(function (): void {
    Route::middleware('guest:store')->group(function (): void {
        Route::livewire('login', 'pages::portal.login')->name('login');
    });

    // Link from a staff invitation email. The page checks the token itself so
    // an expired or already-used link gets a friendly explanation, not a 404.
    Route::livewire('invitation/{storeUser}/{token}', 'pages::portal.accept-invitation')->name('invitation');

    // Arrival from another location's portal via the location switcher.
    Route::get('handoff/{token}', [LocationSwitchController::class, 'handoff'])->name('handoff');

    Route::middleware(['auth:store', 'store.member'])->group(function (): void {
        Route::redirect('/', '/portal/orders');
        Route::livewire('orders', 'pages::portal.orders')->name('orders');
        Route::livewire('orders/{order}', 'pages::portal.order-detail')->name('order-detail');

        // "Leads" here means orders that were started but never completed
        // payment for this store — not the platform's own prospective-
        // funeral-home leads (App\Models\Lead), which live under the admin
        // area only. Staff should never see other funeral homes' inquiries.
        Route::livewire('leads', 'pages::portal.leads')->name('leads');

        // Connecting the funeral home's own Stripe account (owners only).
        Route::livewire('payments', 'pages::portal.payments')->name('payments');

        // The funeral home's own subscription to the platform (owners only).
        Route::livewire('billing', 'pages::portal.billing')->name('billing');

        Route::post('switch-location/{location}', [LocationSwitchController::class, 'switch'])->name('switch-location');

        Route::post('logout', function (Request $request) {
            Auth::guard('store')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('portal.login');
        })->name('logout');
    });
});
