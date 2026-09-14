<?php

use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * A frameless variant of the storefront, meant to be dropped into a funeral
 * home's own website via the embed.js snippet rather than visited directly.
 * It skips our own header/footer chrome so it sits naturally inside the
 * host page's design.
 */
new #[Layout('layouts::storefront-embed')] class extends Component
{
    //
}; ?>

<div>
    <livewire:storefront.checkout-wizard context="embed" />
</div>
