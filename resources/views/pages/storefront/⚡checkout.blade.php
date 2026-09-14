<?php

use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The normal, full-page storefront experience — what a customer sees when
 * they're redirected to their funeral home's own subdomain (as opposed to
 * the frameless `embed` variant used inside another site's page chrome).
 */
new #[Layout('layouts::storefront')] class extends Component
{
    //
}; ?>

<div>
    <livewire:storefront.checkout-wizard context="page" />
</div>
