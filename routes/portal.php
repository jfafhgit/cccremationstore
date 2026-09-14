<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::prefix('portal')->name('portal.')->group(function (): void {
    Route::middleware('guest:store')->group(function (): void {
        Route::livewire('login', 'pages::portal.login')->name('login');
    });

    Route::middleware('auth:store')->group(function (): void {
        Route::redirect('/', '/portal/orders');
        Route::livewire('orders', 'pages::portal.orders')->name('orders');
        Route::livewire('orders/{order}', 'pages::portal.order-detail')->name('order-detail');

        // "Leads" here means orders that were started but never completed
        // payment for this store — not the platform's own prospective-
        // funeral-home leads (App\Models\Lead), which live under the admin
        // area only. Staff should never see other funeral homes' inquiries.
        Route::livewire('leads', 'pages::portal.leads')->name('leads');

        Route::post('logout', function (Request $request) {
            Auth::guard('store')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('portal.login');
        })->name('logout');
    });
});
