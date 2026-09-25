<?php

use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Store;
use App\Services\Cart;
use App\Services\CheckoutService;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;

/**
 * The longer, in-depth intake form the purchaser is sent to once payment is
 * secured (reached via a signed link — see Order::detailsUrl() — so no
 * customer login is required). Deliberately separate from the checkout
 * wizard: nothing here blocks payment, and a grieving family can come back
 * to finish it later. The same link is also Stripe's payment return_url.
 */
new #[Layout('layouts::storefront')] class extends Component
{
    // Deliberately not named "$order" — Livewire matches public property
    // names against test/mount parameters, and the route segment is also
    // named {order}; keeping this distinct avoids that collision.
    public Order $currentOrder;

    public ?string $dateOfBirth = null;

    public ?string $dateOfDeath = null;

    public string $placeOfDeath = '';

    public ?bool $veteranStatus = null;

    public string $maritalStatus = '';

    public string $obituaryText = '';

    public string $servicePreferences = '';

    public string $additionalNotes = '';

    public bool $submitted = false;

    /** The PaymentIntent status as last confirmed with Stripe, if known. */
    public ?string $paymentStatus = null;

    /**
     * The {order} route parameter arrives as a raw id, not an Eloquent
     * model — we resolve it ourselves, scoped to the current store, rather
     * than relying on implicit route-model binding, which would happily
     * fetch an order belonging to a *different* funeral home if someone
     * guessed or shared the wrong id.
     */
    public function mount(int|string $order): void
    {
        $this->currentOrder = Store::current()->orders()->with('detail')->findOrFail($order);

        $this->confirmPayment(app(CheckoutService::class));

        if ($detail = $this->currentOrder->detail) {
            $this->dateOfBirth = $detail->date_of_birth?->toDateString();
            $this->dateOfDeath = $detail->date_of_death?->toDateString();
            $this->placeOfDeath = $detail->place_of_death ?? '';
            $this->veteranStatus = $detail->veteran_status;
            $this->maritalStatus = $detail->marital_status ?? '';
            $this->obituaryText = $detail->obituary_text ?? '';
            $this->servicePreferences = $detail->service_preferences ?? '';
            $this->additionalNotes = $detail->additional_notes ?? '';
            $this->submitted = $detail->submitted_at !== null;
        }
    }

    /**
     * Usually the first thing to learn a payment went through — confirm it
     * with Stripe directly rather than waiting on the webhook, and empty the
     * cart it was paid from.
     */
    private function confirmPayment(CheckoutService $checkout): void
    {
        try {
            $this->paymentStatus = $checkout->syncPaymentStatus($this->currentOrder);
        } catch (ApiErrorException|\RuntimeException $e) {
            Log::warning('Could not confirm Stripe payment status on return.', [
                'order_id' => $this->currentOrder->id,
                'message' => $e->getMessage(),
            ]);
        }

        if (in_array($this->paymentStatus, [PaymentIntent::STATUS_SUCCEEDED, PaymentIntent::STATUS_PROCESSING], true)) {
            $cart = new Cart($this->currentOrder->store);

            if ($cart->pendingOrderId() === $this->currentOrder->id) {
                $cart->clear();
            }
        }
    }

    public function isPaymentProcessing(): bool
    {
        return ! $this->currentOrder->paid_at && $this->paymentStatus === PaymentIntent::STATUS_PROCESSING;
    }

    public function isAwaitingPayment(): bool
    {
        return ! $this->currentOrder->paid_at && ! $this->isPaymentProcessing();
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function rules(): array
    {
        return [
            'dateOfBirth' => ['nullable', 'date', 'before_or_equal:today'],
            'dateOfDeath' => ['nullable', 'date', 'before_or_equal:today'],
            'placeOfDeath' => ['nullable', 'string', 'max:255'],
            'veteranStatus' => ['nullable', 'boolean'],
            'maritalStatus' => ['nullable', 'string', 'max:255'],
            'obituaryText' => ['nullable', 'string', 'max:10000'],
            'servicePreferences' => ['nullable', 'string', 'max:5000'],
            'additionalNotes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function save(): void
    {
        $validated = $this->validate();

        OrderDetail::updateOrCreate(
            ['order_id' => $this->currentOrder->id],
            [
                'date_of_birth' => $validated['dateOfBirth'] ?: null,
                'date_of_death' => $validated['dateOfDeath'] ?: null,
                'place_of_death' => $validated['placeOfDeath'] ?: null,
                'veteran_status' => $validated['veteranStatus'],
                'marital_status' => $validated['maritalStatus'] ?: null,
                'obituary_text' => $validated['obituaryText'] ?: null,
                'service_preferences' => $validated['servicePreferences'] ?: null,
                'additional_notes' => $validated['additionalNotes'] ?: null,
                'submitted_at' => now(),
            ],
        );

        $this->submitted = true;
    }
}; ?>

<div class="mx-auto max-w-3xl px-4 py-10 sm:px-6">
    <div class="rounded-2xl border border-brand-100 bg-white p-6 shadow-sm sm:p-8">
        @if ($this->isAwaitingPayment())
            <flux:heading size="xl" class="font-serif">{{ __('We haven\'t received your payment yet.') }}</flux:heading>
            <flux:subheading class="mt-1">
                {{ __('We couldn\'t confirm a payment for order :number. If you just paid, you\'ll receive a confirmation email shortly. Otherwise, you can return to checkout to complete your order — your selections have been saved.', ['number' => $currentOrder->order_number]) }}
            </flux:subheading>
            <flux:button :href="route('storefront.start')" variant="primary" class="mt-6 !bg-store hover:!bg-store-hover !text-store-foreground">{{ __('Return to checkout') }}</flux:button>
        @else
            <flux:heading size="xl" class="font-serif">{{ __('Thank you, :name.', ['name' => $currentOrder->purchaser_first_name ?: 'friend']) }}</flux:heading>
            <flux:subheading class="mt-1">
                @if ($this->isPaymentProcessing())
                    {{ __('Your payment for order :number is processing — we\'ll email you as soon as it clears. In the meantime, these additional details help us prepare everything with care — you can save your progress and come back anytime.', ['number' => $currentOrder->order_number]) }}
                @else
                    {{ __('Your payment for order :number is complete. Whenever you\'re ready, these additional details help us prepare everything with care — you can save your progress and come back anytime.', ['number' => $currentOrder->order_number]) }}
                @endif
            </flux:subheading>

            @if ($submitted)
                <div class="mt-8 rounded-xl border border-brand-200 bg-brand-50 p-6 text-center">
                    <flux:icon.check-circle class="mx-auto size-8 text-brand-700" />
                    <p class="mt-3 font-medium text-brand-900">{{ __('Thank you — we have everything we need for now.') }}</p>
                    <p class="mt-1 text-sm text-zinc-500">{{ __('A member of our staff will be in touch if anything further is needed.') }}</p>
                    <flux:button variant="ghost" class="mt-4" wire:click="$set('submitted', false)">{{ __('Make changes') }}</flux:button>
                </div>
            @else
                <form wire:submit="save" class="mt-8 space-y-8">
                    <div>
                        <flux:heading size="lg">{{ __('About your loved one') }}</flux:heading>
                        <div class="mt-4 grid gap-4 sm:grid-cols-2">
                            <flux:field>
                                <flux:label>{{ __('Date of birth') }}</flux:label>
                                <flux:input type="date" wire:model="dateOfBirth" />
                                <flux:error name="dateOfBirth" />
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('Date of passing') }}</flux:label>
                                <flux:input type="date" wire:model="dateOfDeath" />
                                <flux:error name="dateOfDeath" />
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('Place of passing') }}</flux:label>
                                <flux:input wire:model="placeOfDeath" placeholder="{{ __('City, state, or facility name') }}" />
                                <flux:error name="placeOfDeath" />
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('Marital status') }}</flux:label>
                                <flux:select wire:model="maritalStatus">
                                    <option value="">{{ __('Prefer not to say') }}</option>
                                    @foreach (['Single', 'Married', 'Widowed', 'Divorced', 'Separated'] as $status)
                                        <option value="{{ $status }}">{{ $status }}</option>
                                    @endforeach
                                </flux:select>
                                <flux:error name="maritalStatus" />
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('Did they serve in the military?') }}</flux:label>
                                <flux:select wire:model="veteranStatus">
                                    <option value="">{{ __('Prefer not to say') }}</option>
                                    <option value="1">{{ __('Yes') }}</option>
                                    <option value="0">{{ __('No') }}</option>
                                </flux:select>
                                <flux:error name="veteranStatus" />
                            </flux:field>
                        </div>
                    </div>

                    <div>
                        <flux:heading size="lg">{{ __('Obituary & service preferences') }}</flux:heading>
                        <flux:subheading class="mt-1">{{ __('Share as much or as little as you have right now — nothing here is final.') }}</flux:subheading>
                        <div class="mt-4 space-y-4">
                            <flux:field>
                                <flux:label>{{ __('Obituary (a draft is fine)') }}</flux:label>
                                <flux:textarea wire:model="obituaryText" rows="6" />
                                <flux:error name="obituaryText" />
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('Service preferences') }}</flux:label>
                                <flux:description>{{ __('Any thoughts on timing, location, readings, music, or who should be involved.') }}</flux:description>
                                <flux:textarea wire:model="servicePreferences" rows="4" />
                                <flux:error name="servicePreferences" />
                            </flux:field>
                        </div>
                    </div>

                    <div>
                        <flux:field>
                            <flux:label>{{ __('Anything else we should know?') }}</flux:label>
                            <flux:textarea wire:model="additionalNotes" rows="3" />
                            <flux:error name="additionalNotes" />
                        </flux:field>
                    </div>

                    <div class="flex items-center justify-end border-t border-zinc-100 pt-6">
                        <flux:button type="submit" variant="primary" class="!bg-store hover:!bg-store-hover !text-store-foreground">
                            {{ __('Save details') }}
                        </flux:button>
                    </div>
                </form>
            @endif
        @endif
    </div>
</div>
