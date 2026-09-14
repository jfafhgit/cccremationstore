<?php

use App\Enums\OrderTiming;
use App\Enums\ProductCategory;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Services\Cart;
use App\Services\CheckoutService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;
use Livewire\Component;
use Stripe\Exception\ApiErrorException;

new class extends Component
{
    /** Which "chrome" this wizard is rendered inside: full page or embed. */
    public string $context = 'page';

    public string $step = 'timing';

    public ?string $timing = null;

    public ?int $packageId = null;

    public ?int $containerId = null;

    public ?int $containerVariantId = null;

    public ?int $urnId = null;

    public ?int $urnVariantId = null;

    /** @var array<string, int> keyed by "{productId}-{variantId}" */
    public array $keepsakeQty = [];

    public string $purchaserFirstName = '';

    public string $purchaserLastName = '';

    public string $purchaserEmail = '';

    public string $purchaserPhone = '';

    public string $relationshipToDeceased = '';

    public string $deceasedFirstName = '';

    public string $deceasedMiddleName = '';

    public string $deceasedLastName = '';

    public string $deceasedSuffix = '';

    public ?string $clientSecret = null;

    public ?int $orderId = null;

    public ?string $redirectAfterPayment = null;

    public ?string $paymentError = null;

    /** @var array<int, string> */
    public array $steps = ['timing', 'personalize', 'details', 'payment'];

    public function mount(): void
    {
        $this->syncFromCart();
    }

    /**
     * Re-read this component's bound selections from the session cart.
     * Needed on mount, and again whenever something else — namely the
     * cart slide-out, which can now remove or adjust the quantity of any
     * line on its own — changes the cart out from under an already-mounted
     * wizard, so its step-1/step-2 selection state doesn't go stale.
     */
    #[On('cart-updated')]
    public function syncFromCart(): void
    {
        $cart = $this->cart();

        $this->timing = $cart->timing()?->value;
        $this->packageId = $cart->state()['package']['product_id'] ?? null;
        $this->containerId = $cart->state()['container']['product_id'] ?? null;
        $this->containerVariantId = $cart->state()['container']['variant_id'] ?? null;
        $this->urnId = $cart->state()['urn']['product_id'] ?? null;
        $this->urnVariantId = $cart->state()['urn']['variant_id'] ?? null;

        $this->keepsakeQty = [];

        foreach ($cart->state()['lines'] as $key => $line) {
            $this->keepsakeQty[$key] = $line['quantity'];
        }
    }

    public function storeModel(): Store
    {
        return Store::current();
    }

    public function cart(): Cart
    {
        return new Cart($this->storeModel());
    }

    public function stepIndex(): int
    {
        return array_search($this->step, $this->steps, true) ?: 0;
    }

    public function packages(): Collection
    {
        return $this->productsFor(ProductCategory::Package);
    }

    public function containers(): Collection
    {
        return $this->productsFor(ProductCategory::Container);
    }

    public function urns(): Collection
    {
        return $this->productsFor(ProductCategory::Urn);
    }

    public function keepsakes(): Collection
    {
        return $this->productsFor(ProductCategory::Keepsake);
    }

    private function productsFor(ProductCategory $category): Collection
    {
        $query = $this->storeModel()->products()
            ->active()
            ->ofCategory($category)
            ->with('variants')
            ->orderBy('sort_order');

        if ($this->timing) {
            $query->availableForTiming($this->timing);
        }

        return $query->get();
    }

    public function selectTiming(string $timing): void
    {
        $this->timing = $timing;
        $this->cart()->setTiming(OrderTiming::from($timing));
        $this->dispatch('cart-updated');
    }

    public function selectPackage(int $productId): void
    {
        $product = $this->packages()->firstWhere('id', $productId);

        if (! $product) {
            return;
        }

        $this->packageId = $productId;
        $this->cart()->selectSlot($product);
        $this->dispatch('cart-updated');
    }

    public function selectContainer(int $productId, ?int $variantId = null): void
    {
        $product = $this->containers()->firstWhere('id', $productId);

        if (! $product) {
            return;
        }

        $variant = $variantId ? $product->variants->firstWhere('id', $variantId) : null;

        $this->containerId = $productId;
        $this->containerVariantId = $variantId;
        $this->cart()->selectSlot($product, $variant);
        $this->dispatch('cart-updated');
    }

    public function clearContainer(): void
    {
        $this->containerId = null;
        $this->containerVariantId = null;
        $this->cart()->clearSlot(ProductCategory::Container);
        $this->dispatch('cart-updated');
    }

    public function selectUrn(int $productId, ?int $variantId = null): void
    {
        $product = $this->urns()->firstWhere('id', $productId);

        if (! $product) {
            return;
        }

        $variant = $variantId ? $product->variants->firstWhere('id', $variantId) : null;

        $this->urnId = $productId;
        $this->urnVariantId = $variantId;
        $this->cart()->selectSlot($product, $variant);
        $this->dispatch('cart-updated');
    }

    public function clearUrn(): void
    {
        $this->urnId = null;
        $this->urnVariantId = null;
        $this->cart()->clearSlot(ProductCategory::Urn);
        $this->dispatch('cart-updated');
    }

    public function setKeepsakeQty(int $productId, ?int $variantId, int $qty): void
    {
        $product = $this->keepsakes()->firstWhere('id', $productId);

        if (! $product) {
            return;
        }

        $variant = $variantId ? $product->variants->firstWhere('id', $variantId) : null;
        $key = $productId.'-'.($variantId ?? '0');

        $qty = max(0, $qty);
        $this->keepsakeQty[$key] = $qty;

        $cart = $this->cart();
        $existingKey = $productId.'-'.($variantId ?? '0');

        if ($qty === 0) {
            $cart->removeLine($existingKey);
        } elseif ($cart->allLines()->has($existingKey)) {
            $cart->updateLineQuantity($existingKey, $qty);
        } else {
            $cart->addLine($product, $variant, $qty);
        }

        $this->dispatch('cart-updated');
    }

    public function goToPersonalize(): void
    {
        $this->step = 'personalize';
    }

    public function goToDetails(): void
    {
        if (! $this->cart()->hasPackage()) {
            $this->addError('package', __('Please choose a package to continue.'));

            return;
        }

        $this->step = 'details';
    }

    public function backTo(string $step): void
    {
        $this->step = $step;
        $this->clientSecret = null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function detailsRules(): array
    {
        return [
            'deceasedFirstName' => ['required', 'string', 'max:255'],
            'deceasedLastName' => ['required', 'string', 'max:255'],
            'relationshipToDeceased' => ['required', 'string', 'max:255'],
            'purchaserFirstName' => ['required', 'string', 'max:255'],
            'purchaserLastName' => ['required', 'string', 'max:255'],
            'purchaserEmail' => ['required', 'email', 'max:255'],
            'purchaserPhone' => ['required', 'string', 'max:30'],
        ];
    }

    public function submitDetails(CheckoutService $checkout): void
    {
        $this->validate($this->detailsRules());

        if ($this->cart()->isEmpty()) {
            $this->addError('package', __('Please choose a package to continue.'));
            $this->step = 'personalize';

            return;
        }

        $store = $this->storeModel();

        if (! $store->isStripeReady()) {
            $this->paymentError = __('Online payment is not yet available for this store. Please call us to complete your order.');

            return;
        }

        $this->paymentError = null;

        try {
            // Reuse the order from a previous attempt on this same visit
            // (e.g. the payment-intent call failed and they hit "Continue"
            // again) instead of creating a duplicate order every retry.
            $order = $this->orderId
                ? $store->orders()->find($this->orderId)
                : null;

            if (! $order) {
                $order = $checkout->createOrder($store, $this->cart(), [
                    'purchaser_first_name' => $this->purchaserFirstName,
                    'purchaser_last_name' => $this->purchaserLastName,
                    'purchaser_email' => $this->purchaserEmail,
                    'purchaser_phone' => $this->purchaserPhone,
                    'relationship_to_deceased' => $this->relationshipToDeceased,
                    'deceased_first_name' => $this->deceasedFirstName,
                    'deceased_middle_name' => $this->deceasedMiddleName ?: null,
                    'deceased_last_name' => $this->deceasedLastName,
                    'deceased_suffix' => $this->deceasedSuffix ?: null,
                ]);

                $this->orderId = $order->id;
            }

            $this->clientSecret = $checkout->createPaymentIntent($order);
        } catch (ApiErrorException|\RuntimeException $e) {
            Log::error('Stripe payment intent creation failed.', [
                'store_id' => $store->id,
                'order_id' => $this->orderId,
                'message' => $e->getMessage(),
            ]);

            $this->paymentError = __('We could not start your payment just now. Please try again in a moment, or call us for assistance.');

            return;
        }

        $this->redirectAfterPayment = $order->detailsUrl();
        $this->step = 'payment';

        $this->dispatch(
            'stripe-mount',
            clientSecret: $this->clientSecret,
            publishableKey: config('services.stripe.key'),
            connectedAccountId: $store->stripe_account_id,
            redirectUrl: $this->redirectAfterPayment,
        );
    }
}; ?>

<div class="mx-auto max-w-3xl px-4 py-10 sm:px-6">
    <nav class="mb-8 flex items-center justify-center gap-2 text-xs font-medium text-zinc-400" aria-label="{{ __('Checkout steps') }}">
        @foreach (['Timing & Package', 'Personalize', 'Your Information', 'Payment'] as $index => $label)
            <span class="flex items-center gap-2">
                <span @class([
                    'flex size-6 items-center justify-center rounded-full text-[11px]',
                    'bg-brand-700 text-white' => $index <= $this->stepIndex(),
                    'bg-zinc-100 text-zinc-400' => $index > $this->stepIndex(),
                ])>{{ $index + 1 }}</span>
                <span class="hidden sm:inline {{ $index === $this->stepIndex() ? 'text-brand-800' : '' }}">{{ __($label) }}</span>
            </span>
            @if (! $loop->last)
                <span class="h-px w-4 bg-zinc-200"></span>
            @endif
        @endforeach
    </nav>

    {{-- Step 1: Timing & Package --}}
    @if ($step === 'timing')
        <div class="rounded-2xl border border-brand-100 bg-white p-6 shadow-sm sm:p-8">
            <flux:heading size="xl" class="font-serif">{{ __('A few details to get started') }}</flux:heading>
            <flux:subheading class="mt-1">{{ __('This helps us show you appropriate options.') }}</flux:subheading>

            <div class="mt-6 space-y-3">
                @foreach (OrderTiming::cases() as $option)
                    <label @class([
                        'flex cursor-pointer items-start gap-3 rounded-xl border p-4 transition',
                        'border-brand-600 bg-brand-50' => $timing === $option->value,
                        'border-zinc-200 hover:border-brand-300' => $timing !== $option->value,
                    ])>
                        <input type="radio" name="timing" value="{{ $option->value }}" wire:model="timing" wire:click="selectTiming('{{ $option->value }}')" class="mt-1 accent-[var(--color-brand-700)]" />
                        <span class="text-sm text-zinc-700">{{ $option->label() }}</span>
                    </label>
                @endforeach
                @error('timing') <flux:error>{{ $message }}</flux:error> @enderror
            </div>

            @if ($timing)
                <div class="mt-8">
                    <flux:heading size="lg">{{ __('Choose a package') }}</flux:heading>
                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        @forelse ($this->packages() as $product)
                            <button
                                type="button"
                                wire:click="selectPackage({{ $product->id }})"
                                @class([
                                    'overflow-hidden rounded-xl border text-left transition',
                                    'border-brand-600 bg-brand-50' => $packageId === $product->id,
                                    'border-zinc-200 hover:border-brand-300' => $packageId !== $product->id,
                                ])
                            >
                                @if ($product->imageUrl())
                                    <img src="{{ $product->imageUrl() }}" alt="" class="h-32 w-full object-cover">
                                @endif
                                <div class="p-4">
                                    <p class="font-medium text-zinc-800">{{ $product->name }}</p>
                                    @if ($product->description)
                                        <p class="mt-1 text-sm text-zinc-500">{{ $product->description }}</p>
                                    @endif
                                    <p class="mt-2 font-semibold text-brand-700">${{ $product->priceInDollars() }}</p>
                                </div>
                            </button>
                        @empty
                            <p class="text-sm text-zinc-500">{{ __('No packages are available for this option yet. Please call us for assistance.') }}</p>
                        @endforelse
                    </div>
                    @error('package') <flux:error class="mt-2">{{ $message }}</flux:error> @enderror
                </div>

                <div class="mt-8 flex justify-end">
                    <flux:button variant="primary" class="!bg-brand-700 hover:!bg-brand-800" wire:click="goToPersonalize" :disabled="! $packageId">
                        {{ __('Continue') }}
                    </flux:button>
                </div>
            @endif
        </div>
    @endif

    {{-- Step 2: Personalize (container / urn / keepsakes) --}}
    @if ($step === 'personalize')
        <div class="rounded-2xl border border-brand-100 bg-white p-6 shadow-sm sm:p-8">
            <flux:heading size="xl" class="font-serif">{{ __('Personalize the service') }}</flux:heading>
            <flux:subheading class="mt-1">{{ __("These are optional — you can also decide later.") }}</flux:subheading>

            @if ($this->containers()->isNotEmpty())
                <div class="mt-8">
                    <flux:heading size="lg">{{ __('Cremation container') }}</flux:heading>
                    <p class="mb-3 text-xs text-zinc-500">{{ __('Required by law for dignified care and handling.') }}</p>
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                        @foreach ($this->containers() as $product)
                            <button type="button" wire:click="selectContainer({{ $product->id }})" @class([
                                'overflow-hidden rounded-xl border text-left transition',
                                'border-brand-600 bg-brand-50' => $containerId === $product->id,
                                'border-zinc-200 hover:border-brand-300' => $containerId !== $product->id,
                            ])>
                                @if ($product->imageUrl())
                                    <img src="{{ $product->imageUrl() }}" alt="" class="h-24 w-full object-cover">
                                @endif
                                <div class="p-3">
                                    <p class="text-sm font-medium text-zinc-800">{{ $product->name }}</p>
                                    <p class="mt-1 text-sm text-brand-700">${{ $product->priceInDollars() }}</p>
                                </div>
                            </button>
                        @endforeach
                    </div>
                    @if ($containerId)
                        <button type="button" wire:click="clearContainer" class="mt-2 text-xs text-zinc-400 underline hover:text-red-600">{{ __('Clear selection') }}</button>
                    @endif
                </div>
            @endif

            @if ($this->urns()->isNotEmpty())
                <div class="mt-8">
                    <flux:heading size="lg">{{ __('Urn') }}</flux:heading>
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                        @foreach ($this->urns() as $product)
                            <button type="button" wire:click="selectUrn({{ $product->id }})" @class([
                                'overflow-hidden rounded-xl border text-left transition',
                                'border-brand-600 bg-brand-50' => $urnId === $product->id,
                                'border-zinc-200 hover:border-brand-300' => $urnId !== $product->id,
                            ])>
                                @if ($product->imageUrl())
                                    <img src="{{ $product->imageUrl() }}" alt="" class="h-24 w-full object-cover">
                                @endif
                                <div class="p-3">
                                    <p class="text-sm font-medium text-zinc-800">{{ $product->name }}</p>
                                    <p class="mt-1 text-sm text-brand-700">${{ $product->priceInDollars() }}</p>
                                </div>
                            </button>
                        @endforeach
                    </div>
                    @if ($urnId)
                        <button type="button" wire:click="clearUrn" class="mt-2 text-xs text-zinc-400 underline hover:text-red-600">{{ __('Clear selection') }}</button>
                    @endif
                </div>
            @endif

            @if ($this->keepsakes()->isNotEmpty())
                <div class="mt-8">
                    <flux:heading size="lg">{{ __('Keepsakes') }}</flux:heading>
                    <p class="mb-3 text-xs text-zinc-500">{{ __('Add as many as you like.') }}</p>
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                        @foreach ($this->keepsakes() as $product)
                            @php($key = $product->id.'-0')
                            <div class="overflow-hidden rounded-xl border border-zinc-200">
                                @if ($product->imageUrl())
                                    <img src="{{ $product->imageUrl() }}" alt="" class="h-24 w-full object-cover">
                                @endif
                                <div class="p-3">
                                    <p class="text-sm font-medium text-zinc-800">{{ $product->name }}</p>
                                    <p class="mt-1 text-sm text-brand-700">${{ $product->priceInDollars() }}</p>
                                    <div class="mt-2 flex items-center gap-2">
                                        <button type="button" class="flex size-7 items-center justify-center rounded-full border border-zinc-200 text-sm" wire:click="setKeepsakeQty({{ $product->id }}, null, {{ (int) ($keepsakeQty[$key] ?? 0) - 1 }})">&minus;</button>
                                        <span class="w-4 text-center text-sm">{{ $keepsakeQty[$key] ?? 0 }}</span>
                                        <button type="button" class="flex size-7 items-center justify-center rounded-full border border-zinc-200 text-sm" wire:click="setKeepsakeQty({{ $product->id }}, null, {{ (int) ($keepsakeQty[$key] ?? 0) + 1 }})">+</button>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="mt-8 flex items-center justify-between">
                <flux:button variant="ghost" wire:click="backTo('timing')">{{ __('Back') }}</flux:button>
                <flux:button variant="primary" class="!bg-brand-700 hover:!bg-brand-800" wire:click="goToDetails">
                    {{ __('Continue') }}
                </flux:button>
            </div>
        </div>
    @endif

    {{-- Step 3: Minimal purchaser + deceased details --}}
    @if ($step === 'details')
        <div class="rounded-2xl border border-brand-100 bg-white p-6 shadow-sm sm:p-8">
            <flux:heading size="xl" class="font-serif">{{ __('Your information') }}</flux:heading>
            <flux:subheading class="mt-1">{{ __("Just the essentials for now — we'll ask for more after your payment is secured.") }}</flux:subheading>

            <form wire:submit="submitDetails" class="mt-6 space-y-6">
                <div>
                    <flux:heading size="sm" class="mb-3 text-zinc-500">{{ __('Your loved one') }}</flux:heading>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:field>
                            <flux:label>{{ __('First name') }}</flux:label>
                            <flux:input wire:model="deceasedFirstName" required />
                            <flux:error name="deceasedFirstName" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('Last name') }}</flux:label>
                            <flux:input wire:model="deceasedLastName" required />
                            <flux:error name="deceasedLastName" />
                        </flux:field>
                    </div>
                </div>

                <div>
                    <flux:heading size="sm" class="mb-3 text-zinc-500">{{ __('About you') }}</flux:heading>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:field>
                            <flux:label>{{ __('First name') }}</flux:label>
                            <flux:input wire:model="purchaserFirstName" required />
                            <flux:error name="purchaserFirstName" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('Last name') }}</flux:label>
                            <flux:input wire:model="purchaserLastName" required />
                            <flux:error name="purchaserLastName" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('Email') }}</flux:label>
                            <flux:input type="email" wire:model="purchaserEmail" required />
                            <flux:error name="purchaserEmail" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('Phone') }}</flux:label>
                            <flux:input type="tel" wire:model="purchaserPhone" required />
                            <flux:error name="purchaserPhone" />
                        </flux:field>
                        <flux:field class="sm:col-span-2">
                            <flux:label>{{ __('Your relationship to the deceased') }}</flux:label>
                            <flux:select wire:model="relationshipToDeceased" required>
                                <option value="">{{ __('Select') }}</option>
                                @foreach (['Spouse', 'Domestic partner', 'Adult child', 'Parent', 'Adult sibling', 'Other family member', 'Legal representative', 'Other'] as $relationship)
                                    <option value="{{ $relationship }}">{{ $relationship }}</option>
                                @endforeach
                            </flux:select>
                            <flux:error name="relationshipToDeceased" />
                        </flux:field>
                    </div>
                </div>

                @if ($paymentError)
                    <flux:callout variant="danger" icon="exclamation-triangle" :heading="$paymentError" />
                @endif

                <div class="flex items-center justify-between pt-2">
                    <flux:button variant="ghost" wire:click="backTo('personalize')">{{ __('Back') }}</flux:button>
                    <flux:button type="submit" variant="primary" class="!bg-brand-700 hover:!bg-brand-800">
                        {{ __('Continue to payment') }}
                    </flux:button>
                </div>
            </form>
        </div>
    @endif

    {{-- Step 4: Payment --}}
    @if ($step === 'payment')
        <div class="rounded-2xl border border-brand-100 bg-white p-6 shadow-sm sm:p-8">
            <flux:heading size="xl" class="font-serif">{{ __('Secure payment') }}</flux:heading>
            <flux:subheading class="mt-1">{{ __('Your card details are handled directly and securely by Stripe.') }}</flux:subheading>

            <div class="mt-6 rounded-xl bg-brand-50 p-4 text-sm">
                <div class="flex justify-between text-zinc-600">
                    <span>{{ __('Subtotal') }}</span>
                    <span>${{ number_format($this->cart()->subtotalCents() / 100, 2) }}</span>
                </div>
                @if ($this->cart()->taxCents() > 0)
                    <div class="mt-1 flex justify-between text-zinc-600">
                        <span>{{ __('Tax') }}</span>
                        <span>${{ number_format($this->cart()->taxCents() / 100, 2) }}</span>
                    </div>
                @endif
                <div class="mt-1 flex justify-between border-t border-brand-100 pt-1 font-semibold text-zinc-800">
                    <span>{{ __('Total due today') }}</span>
                    <span>${{ number_format($this->cart()->totalCents() / 100, 2) }}</span>
                </div>
            </div>

            <div class="mt-6" wire:ignore>
                <form id="payment-form">
                    <div id="payment-element"></div>
                    <p id="payment-errors" class="mt-3 text-sm text-red-600"></p>
                    <button id="pay-button" type="submit" class="mt-4 w-full rounded-lg bg-brand-700 px-4 py-3 text-sm font-semibold text-white transition hover:bg-brand-800 disabled:opacity-50">
                        {{ __('Pay now') }}
                    </button>
                </form>
            </div>

            <button type="button" wire:click="backTo('details')" class="mt-4 text-xs text-zinc-400 underline">{{ __('Back') }}</button>
        </div>

        @script
            <script>
                $wire.on('stripe-mount', async ({ clientSecret, publishableKey, connectedAccountId, redirectUrl }) => {
                    if (!window.Stripe || !publishableKey) {
                        document.getElementById('payment-errors').textContent = 'Payment is not configured for this store yet.';
                        return;
                    }

                    const stripe = connectedAccountId
                        ? window.Stripe(publishableKey, { stripeAccount: connectedAccountId })
                        : window.Stripe(publishableKey);

                    const elements = stripe.elements({ clientSecret });
                    const paymentElement = elements.create('payment');
                    paymentElement.mount('#payment-element');

                    const form = document.getElementById('payment-form');
                    const button = document.getElementById('pay-button');
                    const errors = document.getElementById('payment-errors');

                    form.addEventListener('submit', async (event) => {
                        event.preventDefault();
                        button.disabled = true;
                        errors.textContent = '';

                        const { error } = await stripe.confirmPayment({
                            elements,
                            confirmParams: { return_url: redirectUrl },
                            redirect: 'if_required',
                        });

                        if (error) {
                            errors.textContent = error.message;
                            button.disabled = false;
                        } else {
                            window.location.href = redirectUrl;
                        }
                    });
                });
            </script>
        @endscript
    @endif
</div>
