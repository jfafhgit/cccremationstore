<?php

use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\OrderItem;
use App\Models\Store;
use App\Models\StoreUser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;

beforeEach(function () {
    $this->store = Store::factory()->create();
    actingAsTenant($this->store);

    $this->staff = StoreUser::factory()->forStore($this->store)->create([
        'email' => 'owner@example.com',
        'password' => Hash::make('correct-password'),
    ]);
});

test('staff can log in with the right credentials', function () {
    $sessionIdBeforeLogin = Session::getId();

    $component = Livewire::test('pages::portal.login');
    $component->set('email', 'owner@example.com');
    $component->set('password', 'correct-password');
    $component->call('login');

    $component->assertHasNoErrors();
    expect(Auth::guard('store')->check())->toBeTrue();
    expect(Auth::guard('store')->id())->toBe($this->staff->id);
    expect(Session::getId())->not->toBe($sessionIdBeforeLogin);
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

test('staff see an order\'s refunds but cannot issue one', function () {
    Auth::guard('store')->login($this->staff);
    $order = Order::factory()->for($this->store)->paid()->create(['total_cents' => 150000]);
    $order->refunds()->create(['stripe_refund_id' => 're_test', 'amount_cents' => 25050, 'status' => 'succeeded', 'reason' => 'Urn returned']);

    Livewire::test('pages::portal.order-detail', ['order' => $order->id])
        ->assertSee('Refunded $250.50 of $1,500.00.')
        ->assertSee('Urn returned')
        ->assertDontSee('Issue refund');
});

test('staff see Vital Statistics with the Social Security number masked until they reveal it', function () {
    Auth::guard('store')->login($this->staff);
    $order = Order::factory()->create(['store_id' => $this->store->id, 'paid_at' => now()]);
    OrderDetail::factory()->for($order)->submitted()->create(['ssn' => '123-45-6789', 'has_pacemaker' => true]);

    Livewire::test('pages::portal.order-detail', ['order' => $order->id])
        ->assertSee('•••-••-6789')
        ->assertDontSee('123-45-6789')
        ->assertSee('Pacemaker and/or defibrillator reported')
        ->call('revealSsn')
        ->assertSee('123-45-6789');
});

test('staff can open the Vital Statistics form from a paid order, even after it was submitted', function () {
    Auth::guard('store')->login($this->staff);
    $order = Order::factory()->create(['store_id' => $this->store->id, 'paid_at' => now()]);
    OrderDetail::factory()->for($order)->submitted()->create();

    Livewire::test('pages::portal.order-detail', ['order' => $order->id])
        ->assertSee('Open Vital Statistics form')
        ->assertSee($order->detailsUrl(), escape: true);
});

test('the Vital Statistics form link is hidden for unpaid orders and stores using their own form', function () {
    Auth::guard('store')->login($this->staff);
    $unpaidOrder = Order::factory()->create(['store_id' => $this->store->id, 'paid_at' => null]);

    Livewire::test('pages::portal.order-detail', ['order' => $unpaidOrder->id])
        ->assertDontSee('Open Vital Statistics form');

    $this->store->update(['vital_statistics_url' => 'https://example.com/vital-statistics']);
    $paidOrder = Order::factory()->create(['store_id' => $this->store->id, 'paid_at' => now()]);

    Livewire::test('pages::portal.order-detail', ['order' => $paidOrder->id])
        ->assertDontSee('Open Vital Statistics form');
});

test('order items show their regular price with the package discount on its own line', function () {
    Auth::guard('store')->login($this->staff);
    $order = Order::factory()->create(['store_id' => $this->store->id, 'paid_at' => now()]);
    OrderItem::factory()->for($order)->create([
        'name_snapshot' => 'Walnut Urn',
        'unit_price_cents' => 35000,
        'allowance_cents' => 20000,
        'total_price_cents' => 15000,
    ]);
    OrderItem::factory()->for($order)->create([
        'name_snapshot' => 'Death certificates',
        'base_price_cents_snapshot' => 25000,
        'unit_price_cents' => 1500,
        'quantity' => 3,
        'included_quantity' => 2,
        'total_price_cents' => 1500,
    ]);

    Livewire::test('pages::portal.order-detail', ['order' => $order->id])
        ->assertSeeInOrder(['Walnut Urn', '$350.00', 'Package allowance', '−$200.00'])
        ->assertSeeInOrder(['Death certificates', '$295.00', '2 included with package', '−$280.00']);
});

test('an order from a different store cannot be viewed in the portal', function () {
    Auth::guard('store')->login($this->staff);

    $otherStore = Store::factory()->create();
    $orderFromOtherStore = Order::factory()->create([
        'store_id' => $otherStore->id,
        'deceased_first_name' => 'Rosalind',
        'purchaser_email' => 'other-family@example.com',
    ]);

    // Livewire's test harness renders ModelNotFoundException as a 404
    // response rather than rethrowing it, so assert on that response.
    Livewire::test('pages::portal.order-detail', ['order' => $orderFromOtherStore->id])
        ->assertNotFound()
        ->assertDontSee('Rosalind')
        ->assertDontSee('other-family@example.com');
});
