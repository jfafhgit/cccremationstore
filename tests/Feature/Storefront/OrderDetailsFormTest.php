<?php

use App\Models\Order;
use App\Models\Store;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

test('a purchaser can submit the longer intake form after payment', function () {
    $store = Store::factory()->create();
    actingAsTenant($store);

    $order = Order::factory()->create(['store_id' => $store->id, 'paid_at' => now()]);

    $component = Livewire::test('pages::storefront.order-details', ['order' => $order->id]);

    $component->set('dateOfBirth', '1945-06-01');
    $component->set('dateOfDeath', '2026-09-01');
    $component->set('placeOfDeath', 'Springfield, IL');
    $component->set('veteranStatus', '1');
    $component->set('obituaryText', 'A life well lived.');
    $component->call('save');

    $component->assertHasNoErrors();
    expect($component->get('submitted'))->toBeTrue();

    $detail = $order->fresh()->detail;
    expect($detail)->not->toBeNull()
        ->and($detail->place_of_death)->toBe('Springfield, IL')
        ->and($detail->veteran_status)->toBeTrue()
        ->and($detail->submitted_at)->not->toBeNull();
});

test('an order belonging to a different store cannot be opened', function () {
    $storeA = Store::factory()->create();
    $storeB = Store::factory()->create();
    actingAsTenant($storeA);

    $orderFromOtherStore = Order::factory()->create(['store_id' => $storeB->id]);

    Livewire::test('pages::storefront.order-details', ['order' => $orderFromOtherStore->id]);
})->throws(ModelNotFoundException::class);
