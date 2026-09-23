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
    public array $steps = ['timing', 'containers', 'addons', 'keepsakes', 'details', 'payment'];

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

    /**
     * Services and add-ons, including required ones (which show as included).
     */
    public function extras(): Collection
    {
        return $this->productsFor(ProductCategory::Addon)
            ->concat($this->productsFor(ProductCategory::Service))
            ->sortBy('sort_order')
            ->values();
    }

    private function productsFor(ProductCategory $category): Collection
    {
        $store = $this->storeModel();

        $query = $store->products()
            ->active()
            ->ofCategory($category)
            ->with('variants')
            ->orderedFor($store->productSortMode($category));

        if ($this->timing) {
            $query->availableForTiming($this->timing);
        }

        return $query->get();
    }

    public function isALaCarte(): bool
    {
        return $this->storeModel()->isALaCarte();
    }

    /**
     * The single package an à la carte store starts every order with.
     */
    public function basePackage(): ?Product
    {
        return $this->packages()->first();
    }

    /**
     * À la carte stores don't offer a package choice, so the base package is
     * applied for the customer (and re-applied if it was removed from the cart).
     */
    private function ensureBasePackage(): void
    {
        if (! $this->isALaCarte() || $this->cart()->hasPackage()) {
            return;
        }

        $product = $this->basePackage();

        if ($product) {
            $this->packageId = $product->id;
            $this->cart()->selectSlot($product);
        }
    }

    public function selectTiming(string $timing): void
    {
        $this->timing = $timing;
        $this->cart()->setTiming(OrderTiming::from($timing));
        $this->ensureBasePackage();
        $this->cart()->ensureRequiredLines();
        $this->dispatch('cart-updated');
    }

    public function selectPackage(int $productId): void
    {
        if ($this->isALaCarte()) {
            return;
        }

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
        $product = $this->keepsakes()->merge($this->extras())->firstWhere('id', $productId);

        if (! $product) {
            return;
        }

        $variant = $variantId ? $product->variants->firstWhere('id', $variantId) : null;
        $key = $productId.'-'.($variantId ?? '0');

        $previousQty = (int) ($this->keepsakeQty[$key] ?? 0);
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

        // On the keepsakes step, every selection jumps the customer back to
        // the top of the keepsakes so they scroll past the full lineup again
        // to reach "Continue" — deliberate friction so nothing gets missed.
        if ($product->category === ProductCategory::Keepsake && $qty > $previousQty) {
            $this->dispatch('keepsake-selected');
        }
    }

    public function goToContainers(): void
    {
        $this->ensureBasePackage();
        $this->cart()->ensureRequiredLines();
        $this->preselectIncludedOptions();
        $this->syncFromCart();

        $this->step = 'containers';
    }

    /**
     * Container and urn options priced at $0.00 are the ones included in the
     * package, so pre-select the first of each when nothing is chosen yet.
     */
    private function preselectIncludedOptions(): void
    {
        $cart = $this->cart();

        if (! $cart->state()['container']) {
            $container = $this->containers()->firstWhere('price_cents', 0);

            if ($container) {
                $cart->selectSlot($container);
            }
        }

        if (! $cart->state()['urn']) {
            $urn = $this->urns()->firstWhere('price_cents', 0);

            if ($urn) {
                $cart->selectSlot($urn);
            }
        }

        $this->dispatch('cart-updated');
    }

    public function goToAddons(): void
    {
        $this->ensureBasePackage();
        $this->cart()->ensureRequiredLines();

        if (! $this->cart()->hasPackage()) {
            $this->addError('package', __('Please choose a package to continue.'));

            return;
        }

        if ($this->storeModel()->requires_container && $this->containers()->isNotEmpty() && ! $this->cart()->hasContainer()) {
            $this->addError('container', __('Please choose a cremation container to continue.'));

            return;
        }

        if ($this->storeModel()->requires_urn && $this->urns()->isNotEmpty() && ! $this->cart()->hasUrn()) {
            $this->addError('urn', __('Please choose an urn to continue.'));

            return;
        }

        $this->step = 'addons';
    }

    public function goToKeepsakes(): void
    {
        $this->step = 'keepsakes';
    }

    public function goToDetails(): void
    {
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
            $this->step = 'timing';

            return;
        }

        $store = $this->storeModel();

        if (! $store->isStripeReady()) {
            $this->paymentError = __('Online payment is not yet available for this store. Please call us to complete your order.');

            return;
        }

        $this->paymentError = null;

        $cart = $this->cart();

        $details = [
            'purchaser_first_name' => $this->purchaserFirstName,
            'purchaser_last_name' => $this->purchaserLastName,
            'purchaser_email' => $this->purchaserEmail,
            'purchaser_phone' => $this->purchaserPhone,
            'relationship_to_deceased' => $this->relationshipToDeceased,
            'deceased_first_name' => $this->deceasedFirstName,
            'deceased_middle_name' => $this->deceasedMiddleName ?: null,
            'deceased_last_name' => $this->deceasedLastName,
            'deceased_suffix' => $this->deceasedSuffix ?: null,
        ];

        try {
            // Reuse the order from an earlier attempt with this cart (a failed
            // payment-intent call, a step back to change selections, a page
            // refresh) instead of creating a duplicate every time — but bring
            // it up to date first, so the charge always matches the cart.
            $order = $cart->pendingOrderId()
                ? $store->orders()->find($cart->pendingOrderId())
                : null;

            if ($order?->isAwaitingPayment()) {
                $order = $checkout->updatePendingOrder($order, $cart, $details);
            } else {
                $order = $checkout->createOrder($store, $cart, $details);
                $cart->setPendingOrderId($order->id);
            }

            $this->orderId = $order->id;

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
    <nav class="mb-8 flex flex-wrap items-center justify-center gap-2 text-xs font-medium text-zinc-400" aria-label="{{ __('Checkout steps') }}">
        @foreach (['Timing & Package', 'Container & Urn', 'Add-ons & Services', 'Keepsakes', 'Your Information', 'Payment'] as $index => $label)
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
                    <flux:heading size="lg">{{ $this->isALaCarte() ? __('Your base package') : __('Choose a package') }}</flux:heading>
                    @if ($this->isALaCarte())
                        <p class="mt-1 text-sm text-zinc-500">{{ __('Every arrangement starts here. You can add anything else you need in the following steps.') }}</p>
                    @endif
                    <div @class(['mt-4 grid gap-4', 'sm:grid-cols-2 md:grid-cols-3' => ! $this->isALaCarte()])>
                        @forelse ($this->isALaCarte() ? collect([$this->basePackage()])->filter() : $this->packages() as $product)
                            <button
                                type="button"
                                @if (! $this->isALaCarte()) wire:click="selectPackage({{ $product->id }})" @endif
                                @class([
                                    'overflow-hidden rounded-xl border text-left transition',
                                    'border-brand-600 bg-brand-50' => $packageId === $product->id,
                                    'border-zinc-200 hover:border-brand-300' => $packageId !== $product->id,
                                    'cursor-default sm:flex' => $this->isALaCarte(),
                                ])
                            >
                                <x-product-image :src="$product->imageUrl()" :category="$product->category->value" @class(['w-full', 'h-32' => ! $this->isALaCarte(), 'h-48 sm:h-auto sm:w-2/5' => $this->isALaCarte()]) />
                                <div @class(['p-4', 'flex-1 sm:p-6' => $this->isALaCarte()])>
                                    <p @class(['font-medium text-zinc-800', 'font-serif text-xl' => $this->isALaCarte()])>{{ $product->name }}</p>
                                    @if ($product->description)
                                        <p class="mt-1 text-sm text-zinc-500">{{ $product->description }}</p>
                                    @endif
                                    @if ($product->included_items)
                                        <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-zinc-600 marker:text-brand-600">
                                            @foreach ($product->included_items as $item)
                                                <li>{{ $item }}</li>
                                            @endforeach
                                        </ul>
                                    @endif
                                    <p @class(['mt-2 font-semibold text-brand-700', 'text-xl' => $this->isALaCarte()])>{{ $product->priceLabel() }}</p>
                                </div>
                            </button>
                        @empty
                            <p class="text-sm text-zinc-500">{{ __('No packages are available for this option yet. Please call us for assistance.') }}</p>
                        @endforelse
                    </div>
                    @error('package') <flux:error class="mt-2">{{ $message }}</flux:error> @enderror
                </div>

                <div class="mt-8 flex justify-end">
                    <flux:button variant="primary" class="!bg-brand-700 hover:!bg-brand-800" wire:click="goToContainers" :disabled="! $packageId">
                        {{ __('Continue') }}
                    </flux:button>
                </div>
            @endif
        </div>
    @endif

    {{-- Step 2: Cremation container & urn --}}
    @if ($step === 'containers')
        <div class="rounded-2xl border border-brand-100 bg-white p-6 shadow-sm sm:p-8" x-data="{ zoom: null }" x-on:keydown.escape.window="zoom = null">
            <flux:heading size="xl" class="font-serif">{{ __('Cremation container & urn') }}</flux:heading>
            <flux:subheading class="mt-1">{{ __('Choose what fits — anything marked required must be selected before you continue.') }}</flux:subheading>

            @if ($this->containers()->isNotEmpty())
                <div class="mt-8">
                    <flux:heading size="lg">{{ __('Cremation container') }}</flux:heading>
                    <p class="mb-3 text-xs text-zinc-500">
                        {{ $this->storeModel()->requires_container ? __('Required by law for dignified care and handling.') : __('Optional — you can also decide later.') }}
                    </p>
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                        @foreach ($this->containers() as $product)
                            <div class="relative" wire:key="container-{{ $product->id }}">
                                <button type="button" wire:click="selectContainer({{ $product->id }})" @class([
                                    'block h-full w-full overflow-hidden rounded-xl border text-left transition',
                                    'border-2 border-brand-600 bg-brand-50 ring-2 ring-brand-600/30' => $containerId === $product->id,
                                    'border border-zinc-200 hover:border-brand-300' => $containerId !== $product->id,
                                ])>
                                    <x-product-image :src="$product->imageUrl()" :alt="$product->name" :category="$product->category->value" class="h-24 w-full" />
                                    @if ($containerId === $product->id)
                                        <span class="absolute left-2 top-2 flex items-center gap-1 rounded-full bg-brand-700 px-2 py-0.5 text-xs font-semibold text-white shadow">
                                            <flux:icon.check class="size-3.5" />{{ __('Selected') }}
                                        </span>
                                    @endif
                                    <div class="p-3">
                                        <p class="text-sm font-medium text-zinc-800">{{ $product->name }}</p>
                                        @if ($product->description)
                                            <p class="mt-1 text-xs text-zinc-500">{{ $product->description }}</p>
                                        @endif
                                        <p class="mt-1 text-sm text-brand-700">{{ $product->priceLabel() }}</p>
                                    </div>
                                </button>
                                @if ($product->imageUrl())
                                    <button type="button" class="absolute right-1 top-[4.25rem] flex size-6 items-center justify-center rounded-full bg-white/90 text-zinc-700 shadow hover:bg-white" x-on:click="zoom = { src: @js($product->imageUrl()), name: @js($product->name) }" aria-label="{{ __('View full size image of :name', ['name' => $product->name]) }}">
                                        <flux:icon.magnifying-glass-plus class="size-4" />
                                    </button>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    @if ($containerId && ! $this->storeModel()->requires_container)
                        <button type="button" wire:click="clearContainer" class="mt-2 text-xs text-zinc-400 underline hover:text-red-600">{{ __('Clear selection') }}</button>
                    @endif
                    @error('container') <flux:error class="mt-2">{{ $message }}</flux:error> @enderror
                </div>
            @endif

            @if ($this->urns()->isNotEmpty())
                <div class="mt-8">
                    <flux:heading size="lg">{{ __('Urn') }}</flux:heading>
                    @if ($this->storeModel()->requires_urn)
                        <p class="mb-3 text-xs text-zinc-500">{{ __('An urn selection is required to continue.') }}</p>
                    @endif
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                        @foreach ($this->urns() as $product)
                            <div class="relative" wire:key="urn-{{ $product->id }}">
                                <button type="button" wire:click="selectUrn({{ $product->id }})" @class([
                                    'block h-full w-full overflow-hidden rounded-xl border text-left transition',
                                    'border-2 border-brand-600 bg-brand-50 ring-2 ring-brand-600/30' => $urnId === $product->id,
                                    'border border-zinc-200 hover:border-brand-300' => $urnId !== $product->id,
                                ])>
                                    <x-product-image :src="$product->imageUrl()" :alt="$product->name" :category="$product->category->value" class="h-24 w-full" />
                                    @if ($urnId === $product->id)
                                        <span class="absolute left-2 top-2 flex items-center gap-1 rounded-full bg-brand-700 px-2 py-0.5 text-xs font-semibold text-white shadow">
                                            <flux:icon.check class="size-3.5" />{{ __('Selected') }}
                                        </span>
                                    @endif
                                    <div class="p-3">
                                        <p class="text-sm font-medium text-zinc-800">{{ $product->name }}</p>
                                        @if ($product->description)
                                            <p class="mt-1 text-xs text-zinc-500">{{ $product->description }}</p>
                                        @endif
                                        <p class="mt-1 text-sm text-brand-700">{{ $product->priceLabel() }}</p>
                                    </div>
                                </button>
                                @if ($product->imageUrl())
                                    <button type="button" class="absolute right-1 top-[4.25rem] flex size-6 items-center justify-center rounded-full bg-white/90 text-zinc-700 shadow hover:bg-white" x-on:click="zoom = { src: @js($product->imageUrl()), name: @js($product->name) }" aria-label="{{ __('View full size image of :name', ['name' => $product->name]) }}">
                                        <flux:icon.magnifying-glass-plus class="size-4" />
                                    </button>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    @if ($urnId && ! $this->storeModel()->requires_urn)
                        <button type="button" wire:click="clearUrn" class="mt-2 text-xs text-zinc-400 underline hover:text-red-600">{{ __('Clear selection') }}</button>
                    @endif
                    @error('urn') <flux:error class="mt-2">{{ $message }}</flux:error> @enderror
                </div>
            @endif

            @if ($this->containers()->isEmpty() && $this->urns()->isEmpty())
                <p class="mt-6 text-sm text-zinc-500">{{ __('No container or urn options are available for this arrangement — you can continue to the next step.') }}</p>
            @endif

            <div x-show="zoom" style="display: none" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center bg-zinc-900/80 p-4" x-on:click="zoom = null" role="dialog" aria-modal="true">
                <button type="button" class="absolute right-4 top-4 flex size-10 items-center justify-center rounded-full bg-white text-zinc-800" x-on:click="zoom = null" aria-label="{{ __('Close image') }}">
                    <flux:icon.x-mark class="size-5" />
                </button>
                <img x-bind:src="zoom?.src" x-bind:alt="zoom?.name" class="max-h-full max-w-full rounded-lg object-contain shadow-2xl" x-on:click.stop>
            </div>

            <div class="mt-8 flex items-center justify-between">
                <flux:button variant="ghost" wire:click="backTo('timing')">{{ __('Back') }}</flux:button>
                <flux:button variant="primary" class="!bg-brand-700 hover:!bg-brand-800" wire:click="goToAddons">
                    {{ __('Continue') }}
                </flux:button>
            </div>
        </div>
    @endif

    {{-- Step 3: Services & add-ons --}}
    @if ($step === 'addons')
        <div class="rounded-2xl border border-brand-100 bg-white p-6 shadow-sm sm:p-8">
            <flux:heading size="xl" class="font-serif">{{ __('Services & add-ons') }}</flux:heading>
            <flux:subheading class="mt-1">{{ __('Add anything else you need for the service.') }}</flux:subheading>

            <div class="mt-6 grid gap-3 sm:grid-cols-2">
                @forelse (($extras = $this->extras()) as $product)
                    @php($key = $product->id.'-0')
                    @php($qty = (int) ($keepsakeQty[$key] ?? 0))
                    <div class="flex gap-3 overflow-hidden rounded-xl border border-zinc-200 p-3" wire:key="extra-{{ $product->id }}">
                        <x-product-image :src="$product->imageUrl()" :category="$product->category->value" class="size-16 shrink-0 rounded-lg" />
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-zinc-800">{{ $product->name }}</p>
                            @if ($product->description)
                                <p class="text-xs text-zinc-500">{{ $product->description }}</p>
                            @endif
                            <p class="mt-1 text-sm text-brand-700">{{ $product->priceLabel() }}</p>

                            @if ($product->is_required && ! $product->hasPerUnitPricing())
                                <span class="mt-2 inline-block rounded-full bg-brand-50 px-2 py-0.5 text-xs font-medium text-brand-800">{{ __('Required') }}</span>
                            @else
                                @if ($product->is_required)
                                    <span class="mt-2 inline-block rounded-full bg-brand-50 px-2 py-0.5 text-xs font-medium text-brand-800">{{ __('Required') }}</span>
                                @endif
                                @if ($product->hasPerUnitPricing() || ! $product->is_required)
                                    <div class="mt-2 flex items-center gap-2">
                                        <button type="button" class="flex size-7 items-center justify-center rounded-full border border-zinc-200 text-sm" wire:click="setKeepsakeQty({{ $product->id }}, null, {{ $qty - 1 }})">&minus;</button>
                                        <span class="w-4 text-center text-sm">{{ $qty }}</span>
                                        <button type="button" class="flex size-7 items-center justify-center rounded-full border border-zinc-200 text-sm" wire:click="setKeepsakeQty({{ $product->id }}, null, {{ $qty + 1 }})">+</button>
                                        @if ($product->hasPerUnitPricing() && $product->per_unit_label)
                                            <span class="text-xs text-zinc-400">{{ $product->per_unit_label }}{{ $qty === 1 ? '' : 's' }}</span>
                                        @endif
                                    </div>
                                @endif
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-zinc-500">{{ __('No services or add-ons are available for this option — you can continue to the next step.') }}</p>
                @endforelse
            </div>

            <div class="mt-8 flex items-center justify-between">
                <flux:button variant="ghost" wire:click="backTo('containers')">{{ __('Back') }}</flux:button>
                <flux:button variant="primary" class="!bg-brand-700 hover:!bg-brand-800" wire:click="goToKeepsakes">
                    {{ __('Continue') }}
                </flux:button>
            </div>
        </div>
    @endif

    {{-- Step 4: Keepsakes --}}
    @if ($step === 'keepsakes')
        <div class="rounded-2xl border border-brand-100 bg-white p-6 shadow-sm sm:p-8" x-data="{ zoom: null }" x-on:keydown.escape.window="zoom = null">
            <flux:heading size="xl" class="font-serif" id="keepsakes-top">{{ __('Keepsakes') }}</flux:heading>
            <flux:subheading class="mt-1">{{ __('Add as many as you like.') }}</flux:subheading>

            <div class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3">
                @forelse ($this->keepsakes() as $product)
                    @php($key = $product->id.'-0')
                    <div class="overflow-hidden rounded-xl border border-zinc-200">
                        @if ($product->imageUrl())
                            <button type="button" class="group relative block w-full" x-on:click="zoom = { src: @js($product->imageUrl()), name: @js($product->name) }" aria-label="{{ __('View full size image of :name', ['name' => $product->name]) }}">
                                <x-product-image :src="$product->imageUrl()" :alt="$product->name" :category="$product->category->value" class="h-24 w-full" />
                                <span class="absolute bottom-1 right-1 flex size-6 items-center justify-center rounded-full bg-white/90 text-zinc-700 shadow group-hover:bg-white">
                                    <flux:icon.magnifying-glass-plus class="size-4" />
                                </span>
                            </button>
                        @else
                            <x-product-image :category="$product->category->value" class="h-24 w-full" />
                        @endif
                        <div class="p-3">
                            <p class="text-sm font-medium text-zinc-800">{{ $product->name }}</p>
                            @if ($product->description)
                                <p class="mt-1 text-xs text-zinc-500">{{ $product->description }}</p>
                            @endif
                            <p class="mt-1 text-sm text-brand-700">{{ $product->priceLabel() }}</p>
                            <div class="mt-2 flex items-center gap-2">
                                <button type="button" class="flex size-7 items-center justify-center rounded-full border border-zinc-200 text-sm" wire:click="setKeepsakeQty({{ $product->id }}, null, {{ (int) ($keepsakeQty[$key] ?? 0) - 1 }})">&minus;</button>
                                <span class="w-4 text-center text-sm">{{ $keepsakeQty[$key] ?? 0 }}</span>
                                <button type="button" class="flex size-7 items-center justify-center rounded-full border border-zinc-200 text-sm" wire:click="setKeepsakeQty({{ $product->id }}, null, {{ (int) ($keepsakeQty[$key] ?? 0) + 1 }})">+</button>
                            </div>
                        </div>
                    </div>
                @empty
                    <p class="col-span-full text-sm text-zinc-500">{{ __('No keepsakes are available for this option — you can continue to the next step.') }}</p>
                @endforelse
            </div>

            <div x-show="zoom" style="display: none" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center bg-zinc-900/80 p-4" x-on:click="zoom = null" role="dialog" aria-modal="true">
                <button type="button" class="absolute right-4 top-4 flex size-10 items-center justify-center rounded-full bg-white text-zinc-800" x-on:click="zoom = null" aria-label="{{ __('Close image') }}">
                    <flux:icon.x-mark class="size-5" />
                </button>
                <img x-bind:src="zoom?.src" x-bind:alt="zoom?.name" class="max-h-full max-w-full rounded-lg object-contain shadow-2xl" x-on:click.stop>
            </div>

            <div class="mt-8 flex items-center justify-between">
                <flux:button variant="ghost" wire:click="backTo('addons')">{{ __('Back') }}</flux:button>
                <flux:button variant="primary" class="!bg-brand-700 hover:!bg-brand-800" wire:click="goToDetails">
                    {{ __('Continue') }}
                </flux:button>
            </div>
        </div>

        @script
            <script>
                $wire.on('keepsake-selected', () => {
                    document.getElementById('keepsakes-top')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                });
            </script>
        @endscript
    @endif

    {{-- Step 5: Minimal purchaser + deceased details --}}
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
                    <flux:button variant="ghost" wire:click="backTo('keepsakes')">{{ __('Back') }}</flux:button>
                    <flux:button type="submit" variant="primary" class="!bg-brand-700 hover:!bg-brand-800">
                        {{ __('Continue to payment') }}
                    </flux:button>
                </div>
            </form>
        </div>
    @endif

    {{-- Step 6: Payment --}}
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
                @if ($this->cart()->processingFeeCents() > 0)
                    <div class="mt-1 flex justify-between text-zinc-600">
                        <span>{{ __('Processing fee') }}</span>
                        <span>${{ number_format($this->cart()->processingFeeCents() / 100, 2) }}</span>
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
    @endif

    {{-- Registered once for the component rather than inside the payment step,
         so going back and continuing again can't stack duplicate listeners. --}}
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

                form.onsubmit = async (event) => {
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
                };
            });
        </script>
    @endscript
</div>
