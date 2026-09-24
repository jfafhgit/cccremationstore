<?php

use App\Http\Controllers\Admin\StripeConnectController;
use Illuminate\Support\Facades\Route;
use Laravel\WorkOS\Http\Middleware\ValidateSessionWithWorkOS;

Route::middleware(['auth', ValidateSessionWithWorkOS::class])->group(function (): void {
    // Existing WorkOS post-login destination. Approved admins go straight into
    // the admin area; anyone still waiting on approval is told so.
    Route::get('dashboard', fn () => auth()->user()->isApproved()
        ? redirect('/admin/stores')
        : redirect()->route('pending-approval'))->name('dashboard');

    Route::middleware('can:access-admin')->prefix('admin')->name('admin.')->group(function (): void {
        Route::redirect('/', '/admin/stores');

        Route::livewire('stores', 'pages::admin.stores.index')->name('stores.index');
        Route::livewire('stores/{store}', 'pages::admin.stores.show')->name('stores.show');
        Route::livewire('stores/{store}/products', 'pages::admin.stores.products')->name('stores.products');
        Route::livewire('stores/{store}/orders', 'pages::admin.stores.orders')->name('stores.orders');
        Route::livewire('stores/{store}/orders/{order}', 'pages::admin.stores.order-detail')->name('stores.order-detail');
        Route::livewire('leads', 'pages::admin.leads')->name('leads');

        Route::livewire('users', 'pages::admin.users')->middleware('can:manage-admins')->name('users');

        Route::get('stores/{store}/stripe/connect', [StripeConnectController::class, 'connect'])->name('stores.stripe.connect');
        Route::get('stores/{store}/stripe/return', [StripeConnectController::class, 'return'])->name('stores.stripe.return');
        Route::get('stores/{store}/stripe/refresh', [StripeConnectController::class, 'refresh'])->name('stores.stripe.refresh');
    });
});
