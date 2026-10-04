<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\Notifications\RefundIssuedForStaffNotification;
use App\Notifications\RefundIssuedNotification;
use App\Services\RefundService;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Stripe\ApiRequestor;

beforeEach(function () {
    config(['services.stripe.secret' => 'sk_test_fake']);
    Notification::fake();
    $this->stripe = fakeStripe();

    $this->admin = User::factory()->create(['name' => 'Alex Admin']);
    $this->actingAs($this->admin);

    $this->store = Store::factory()->stripeConnected()->create();
    $this->order = Order::factory()->for($this->store)->paid()->create([
        'total_cents' => 150000,
        'processing_fee_cents' => 5000,
        'platform_fee_cents' => 7500,
        'stripe_account_id' => $this->store->stripe_account_id,
        'stripe_payment_intent_id' => 'pi_paid',
    ]);
    $this->stripe->paidWithFee('pi_paid', 'fee_paid', 7500);
});

afterEach(function () {
    ApiRequestor::setHttpClient(null);
});

function refundForm(Order $order): Testable
{
    return Livewire::test('pages::admin.stores.order-detail', ['store' => $order->store_id, 'order' => $order->id]);
}

test('a full refund returns the whole payment on the store\'s stripe account and marks the order refunded', function () {
    refundForm($this->order)
        ->set('refundOption', 'full')
        ->set('refundReason', 'Family chose another provider')
        ->call('issueRefund')
        ->assertHasNoErrors()
        ->assertSet('status', OrderStatus::Refunded->value);

    expect($this->stripe->refundRequests())->toHaveCount(1)
        ->and($this->stripe->refundRequests()[0]['params'])->toMatchArray(['payment_intent' => 'pi_paid', 'amount' => 150000])
        ->and($this->order->fresh())
        ->status->toBe(OrderStatus::Refunded)
        ->refundableCents()->toBe(0)
        ->and($this->order->refunds()->sole())
        ->amount_cents->toBe(150000)
        ->reason->toBe('Family chose another provider')
        ->refunded_by_user_id->toBe($this->admin->id);
});

test('the full amount minus the processing fee keeps the fee and leaves the order open', function () {
    refundForm($this->order)
        ->set('refundOption', 'minus_fee')
        ->call('issueRefund')
        ->assertHasNoErrors();

    expect($this->stripe->refundRequests()[0]['params']['amount'])->toBe(145000)
        ->and($this->order->fresh())
        ->status->toBe(OrderStatus::Paid)
        ->refundableCents()->toBe(5000);
});

test('full refunds, with or without the processing fee, return the whole platform fee', function (string $option) {
    refundForm($this->order)
        ->set('refundOption', $option)
        ->call('issueRefund')
        ->assertHasNoErrors();

    expect($this->stripe->applicationFees['fee_paid']['amount_refunded'])->toBe(7500)
        ->and($this->order->refunds()->sole()->application_fee_refunded_cents)->toBe(7500);
})->with(['full', 'minus_fee']);

test('a custom amount refunds just that much and keeps the platform fee', function () {
    refundForm($this->order)
        ->set('refundOption', 'custom')
        ->set('refundAmount', '250.50')
        ->call('issueRefund')
        ->assertHasNoErrors();

    expect($this->stripe->refundRequests()[0]['params']['amount'])->toBe(25050)
        ->and($this->order->fresh()->refundableCents())->toBe(124950)
        ->and($this->stripe->applicationFees['fee_paid']['amount_refunded'])->toBe(0)
        ->and($this->order->refunds()->sole()->application_fee_refunded_cents)->toBe(0);
});

test('only what is left of the platform fee is returned on a later full refund', function () {
    $this->stripe->applicationFees['fee_paid']['amount_refunded'] = 2500;
    $this->order->refunds()->create(['stripe_refund_id' => 're_earlier', 'amount_cents' => 50000, 'status' => 'succeeded', 'application_fee_refunded_cents' => 2500]);

    refundForm($this->order)
        ->set('refundOption', 'full')
        ->call('issueRefund')
        ->assertHasNoErrors();

    expect($this->stripe->applicationFees['fee_paid']['amount_refunded'])->toBe(7500)
        ->and($this->order->refunds()->latest('id')->first()->application_fee_refunded_cents)->toBe(5000);
});

test('the family and the funeral home are emailed about the refund', function () {
    refundForm($this->order)
        ->set('refundOption', 'custom')
        ->set('refundAmount', '100')
        ->call('issueRefund');

    Notification::assertSentTo($this->order, RefundIssuedNotification::class);
    Notification::assertSentOnDemand(
        RefundIssuedForStaffNotification::class,
        fn ($notification, $channels, $notifiable): bool => $notifiable->routes['mail'] === $this->store->general_email,
    );
});

test('a refund that fails outright emails no one', function () {
    $this->stripe->refund('re_dashboard', 'pi_paid', 50000, 'failed');

    app(RefundService::class)->syncFromStripe($this->order);

    Notification::assertNothingSent();
});

test('a custom amount must be more than zero and no more than what is left', function (string $amount) {
    $this->order->refunds()->create(['stripe_refund_id' => 're_earlier', 'amount_cents' => 100000, 'status' => 'succeeded']);

    refundForm($this->order)
        ->set('refundOption', 'custom')
        ->set('refundAmount', $amount)
        ->call('issueRefund')
        ->assertHasErrors('refundAmount');

    expect($this->stripe->refundRequests())->toBeEmpty();
})->with([
    'zero' => ['0'],
    'more than is left' => ['500.01'],
    'blank' => [''],
]);

test('the minus-fee option is refused when the order had no processing fee', function () {
    $this->order->update(['processing_fee_cents' => 0]);

    refundForm($this->order)
        ->assertDontSee('minus the processing fee')
        ->set('refundOption', 'minus_fee')
        ->call('issueRefund')
        ->assertHasErrors('refundAmount');

    expect($this->stripe->refundRequests())->toBeEmpty();
});

test('failed refunds no longer count toward what has been refunded', function () {
    $this->order->refunds()->create(['stripe_refund_id' => 're_failed', 'amount_cents' => 150000, 'status' => 'failed']);

    refundForm($this->order)
        ->set('refundOption', 'full')
        ->call('issueRefund')
        ->assertHasNoErrors();

    expect($this->stripe->refundRequests()[0]['params']['amount'])->toBe(150000);
});

test('an unpaid order cannot be refunded', function () {
    $this->order->update(['paid_at' => null, 'status' => OrderStatus::PendingPayment]);

    refundForm($this->order)
        ->assertDontSee('Issue refund')
        ->set('refundOption', 'full')
        ->call('issueRefund')
        ->assertHasErrors('refundAmount');

    expect($this->stripe->refundRequests())->toBeEmpty();
});

test('when stripe cannot be reached the admin is told and nothing is recorded', function () {
    config(['services.stripe.secret' => null]);

    refundForm($this->order)
        ->set('refundOption', 'full')
        ->call('issueRefund')
        ->assertHasErrors('refundAmount');

    expect($this->order->refunds()->count())->toBe(0)
        ->and($this->order->fresh()->status)->toBe(OrderStatus::Paid);
});

test('the refund emails show the amount, and only staff see the reason', function () {
    $refund = $this->order->refunds()->create([
        'stripe_refund_id' => 're_test',
        'amount_cents' => 25050,
        'status' => 'succeeded',
        'reason' => 'Urn returned',
        'refunded_by_user_id' => $this->admin->id,
    ]);

    $familyHtml = (string) (new RefundIssuedNotification($refund))->toMail($this->order)->render();
    $staffHtml = (string) (new RefundIssuedForStaffNotification($refund))->toMail($this->order)->render();

    expect($familyHtml)->toContain('$250.50')->not->toContain('Urn returned')
        ->and($staffHtml)->toContain('$250.50')->toContain('Urn returned');
});
