<?php

use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages::storefront.checkout')->name('storefront.start');

// A frameless variant of the same wizard, meant to be embedded on a funeral
// home's own website via the embed.js snippet (see resources/js/embed.js).
Route::livewire('embed', 'pages::storefront.embed')->name('storefront.embed');

// This signed link is also Stripe's payment return_url, and Stripe appends its
// own query parameters when it redirects back after e.g. a bank authorization.
Route::livewire('orders/{order}/details', 'pages::storefront.order-details')
    ->middleware('signed:payment_intent,payment_intent_client_secret,redirect_status')
    ->name('storefront.order-details');
