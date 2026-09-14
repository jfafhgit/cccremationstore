<?php

use App\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Route;

// Stripe sends Connect platform webhooks to a single, domain-agnostic URL
// (configured in the Stripe Dashboard), not to any funeral home's subdomain.
Route::post('/stripe/webhook', StripeWebhookController::class)->name('stripe.webhook');

Route::domain(config('app.root_domain'))->group(function (): void {
    require __DIR__.'/marketing.php';
    require __DIR__.'/admin.php';
    require __DIR__.'/auth.php';
    require __DIR__.'/settings.php';
});

Route::domain('{store}.'.config('app.root_domain'))
    ->middleware('store')
    ->group(function (): void {
        require __DIR__.'/storefront.php';
        require __DIR__.'/portal.php';
    });
