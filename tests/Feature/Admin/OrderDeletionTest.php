<?php

use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());

    $this->store = Store::factory()->create(['name' => 'Riverside Chapel']);
});

test('an admin can delete a single order', function () {
    $order = Order::factory()->for($this->store)->paid()->create();
    $otherOrder = Order::factory()->for($this->store)->create();

    Livewire::test('pages::admin.stores.order-detail', ['store' => $this->store, 'order' => $order->id])
        ->call('deleteOrder')
        ->assertRedirect(route('admin.stores.orders', $this->store));

    $this->assertModelMissing($order);
    $this->assertModelExists($otherOrder);
});

test('deleting all orders clears only this store\'s orders', function () {
    Order::factory()->for($this->store)->count(3)->create();
    $otherStoreOrder = Order::factory()->for(Store::factory())->create();

    Livewire::test('pages::admin.stores.orders', ['store' => $this->store])
        ->set('deleteAllConfirmation', 'Riverside Chapel')
        ->call('deleteAllOrders')
        ->assertHasNoErrors();

    expect($this->store->orders()->count())->toBe(0);
    $this->assertModelExists($otherStoreOrder);
});

test('all orders are only deleted when the store name is typed exactly', function () {
    Order::factory()->for($this->store)->create();

    Livewire::test('pages::admin.stores.orders', ['store' => $this->store])
        ->set('deleteAllConfirmation', 'wrong')
        ->call('deleteAllOrders')
        ->assertHasErrors('deleteAllConfirmation');

    expect($this->store->orders()->count())->toBe(1);
});
