<?php

use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages::storefront.checkout')->name('storefront.start');

// A frameless variant of the same wizard, meant to be embedded on a funeral
// home's own website via the embed.js snippet (see resources/js/embed.js).
Route::livewire('embed', 'pages::storefront.embed')->name('storefront.embed');

Route::livewire('orders/{order}/details', 'pages::storefront.order-details')
    ->middleware('signed')
    ->name('storefront.order-details');
