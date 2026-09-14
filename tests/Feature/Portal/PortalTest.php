<?php

use App\Models\Order;
use App\Models\Store;
use App\Models\StoreUser;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

beforeEach(function () {
    $this->store = Store::factory()->create();
    actingAsTenant($this->store);

    $this->staff = StoreUser::factory()->for($this->store)->create([
        'email' => 'owner@example.com',
        'password' => Hash::make('correct-password'),
    ]);
});

test('staff can log in with the right credentials', function () {
    $component = Livewire::test('pages::portal.login');
    $component->set('email', 'owner@example.com');
    $component->set('password', 'correct-password');
    $component->call('login');

    $component->assertHasNoErrors();
    expect(Auth::guard('store')->check())->toBeTrue();
    expect(Auth::guard('store')->id())->toBe($this->staff->id);
});

test('the same credentials do not work for a different store', function () {
    $otherStore = Store::factory()->create();
    actingAsTenant($otherStore);

    $component = Livewire::test('pages::portal.login');
    $component->set('email', 'owner@example.com');
    $component->set('password', 'correct-password');
    $component->call('login');

    $component->assertHasErrors('email');
    expect(Auth::guard('store')->check())->toBeFalse();
});

test('a wrong password is rejected', function () {
    $component = Livewire::test('pages::portal.login');
    $component->set('email', 'owner@example.com');
    $component->set('password', 'wrong-password');
    $component->call('login');

    $component->assertHasErrors('email');
    expect(Auth::guard('store')->check())->toBeFalse();
});

test('staff only see paid orders for their own store', function () {
    Auth::guard('store')->login($this->staff);

    $mine = Order::factory()->create(['store_id' => $this->store->id, 'paid_at' => now()]);
    Order::factory()->create(['store_id' => $this->store->id, 'paid_at' => null]); // unpaid, excluded
    $otherStore = Store::factory()->create();
    Order::factory()->create(['store_id' => $otherStore->id, 'paid_at' => now()]); // different store, excluded

    $component = Livewire::test('pages::portal.orders');
    $orders = $component->instance()->orders();

    expect($orders->total())->toBe(1)
        ->and($orders->first()->id)->toBe($mine->id);
});

test('staff can see their own store incomplete orders as leads, never another store\'s', function () {
    Auth::guard('store')->login($this->staff);

    $mine = Order::factory()->create([
        'store_id' => $this->store->id,
        'paid_at' => null,
        'purchaser_email' => 'lead@example.com',
    ]);

    $otherStore = Store::factory()->create();
    Order::factory()->create([
        'store_id' => $otherStore->id,
        'paid_at' => null,
        'purchaser_email' => 'other-lead@example.com',
    ]);

    $component = Livewire::test('pages::portal.leads');
    $leads = $component->instance()->incompleteOrders();

    expect($leads->total())->toBe(1)
        ->and($leads->first()->id)->toBe($mine->id);
});

test('an order from a different store cannot be viewed in the portal', function () {
    Auth::guard('store')->login($this->staff);

    $otherStore = Store::factory()->create();
    $orderFromOtherStore = Order::factory()->create(['store_id' => $otherStore->id]);

    Livewire::test('pages::portal.order-detail', ['order' => $orderFromOtherStore->id]);
})->throws(ModelNotFoundException::class);
