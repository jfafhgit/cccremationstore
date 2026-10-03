<?php

use App\Models\Store;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * A frameless variant of the storefront, meant to be dropped into a funeral
 * home's own website via the embed.js snippet rather than visited directly.
 * It skips our own header/footer chrome so it sits naturally inside the
 * host page's design, so the cart button lives in the body instead.
 */
new #[Layout('layouts::storefront-embed')] class extends Component
{
    //
}; ?>

<div>
    <div class="mx-auto flex max-w-5xl justify-end px-4 pt-4 sm:px-6">
        <livewire:storefront.cart-drawer :store="Store::current()" :embedded="true" />
    </div>

    <livewire:storefront.checkout-wizard context="embed" />
</div>
