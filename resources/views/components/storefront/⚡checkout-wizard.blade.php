<?php

use App\Enums\OrderTiming;
use App\Enums\ProductCategory;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Enums\UsState;
use App\Models\Store;
use App\Models\StoreLocation;
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

    /** The state picked in the location dropdown (location-priced stores only). */
    public ?string $locationState = null;

    /** The StoreLocation (city) picked in the location dropdown. */
    public ?int $locationId = null;

    public ?int $packageId = null;

    public ?int $containerId = null;

    public ?int $containerVariantId = null;

    public ?int $urnId = null;

    public ?int $urnVariantId = null;

    public ?int $urnVaultId = null;

    /** @var array<string, int> keyed by "{productId}-{variantId}" */
    public array $keepsakeQty = [];

    public string $purchaserFirstName = '';

    public string $purchaserLastName = '';

    public string $purchaserEmail = '';

    public string $purchaserPhone = '';

    public string $relationshipToDeceased = '';

    /** Pre-need only: whether the purchaser is planning for "self" or "someone_else". */
    public string $arrangementFor = '';

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

        // Pre-need stores never ask about timing, so nothing else would apply the base package.
        if ($this->storeModel()->isPreNeed() && $this->hasLocation()) {
            $this->ensureBasePackage();
            $this->syncFromCart();
        }
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
        $location = $cart->location();
        $this->locationState = $location?->state->value ?? $this->locationState;
        $this->locationId = $location?->id;
        $this->packageId = $cart->state()['package']['product_id'] ?? null;
        $this->containerId = $cart->state()['container']['product_id'] ?? null;
        $this->containerVariantId = $cart->state()['container']['variant_id'] ?? null;
        $this->urnId = $cart->state()['urn']['product_id'] ?? null;
        $this->urnVariantId = $cart->state()['urn']['variant_id'] ?? null;
        $this->urnVaultId = $cart->state()['urn_vault']['product_id'] ?? null;

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

    /**
     * Packages offered for the chosen city (all of them when the store
     * doesn't use location pricing).
     */
    public function packages(): Collection
    {
        $packages = $this->productsFor(ProductCategory::Package)->load('includedProducts');

        if (! $this->usesLocationPricing()) {
            return $packages;
        }

        $cart = $this->cart();

        return $packages->filter(fn (Product $package) => $cart->packagePriceCents($package) !== null)->values();
    }

    public function packagePriceLabel(Product $package): string
    {
        if (! $this->usesLocationPricing()) {
            return $package->priceLabel();
        }

        return '$'.number_format(($this->cart()->packagePriceCents($package) ?? $package->price_cents) / 100, 2);
    }

    public function usesLocationPricing(): bool
    {
        return $this->storeModel()->usesLocationPricing();
    }

    /**
     * Whether the family can move on to timing and packages: they've picked
     * a city, or the store doesn't price by location.
     */
    public function hasLocation(): bool
    {
        return ! $this->usesLocationPricing() || $this->locationId !== null;
    }

    /**
     * @return Collection<int, UsState>
     */
    public function locationStates(): Collection
    {
        return $this->storeModel()->locations->pluck('state')->unique()->sortBy(fn (UsState $state) => $state->label())->values();
    }

    /**
     * @return Collection<int, StoreLocation>
     */
    public function citiesInState(): Collection
    {
        return $this->storeModel()->locations->filter(fn (StoreLocation $location) => $location->state->value === $this->locationState)->values();
    }

    public function updatedLocationState(): void
    {
        $this->locationId = null;
    }

    public function updatedLocationId(?int $locationId): void
    {
        $location = $locationId ? $this->storeModel()->locations()->find($locationId) : null;

        if (! $location) {
            $this->locationId = null;

            return;
        }

        $this->cart()->setLocation($location);

        if ($this->timing) {
            $this->ensureBasePackage();
        }

        $this->syncFromCart();
        $this->dispatch('cart-updated');
    }

    public function containers(): Collection
    {
        return $this->slotOptions(ProductCategory::Container, 'container');
    }

    public function urns(): Collection
    {
        return $this->slotOptions(ProductCategory::Urn, 'urn');
    }

    /**
     * Urn vaults have no package allowance, so every active one is offered.
     */
    public function urnVaults(): Collection
    {
        return $this->productsFor(ProductCategory::UrnVault);
    }

    /**
     * The container step's title, naming the vault only when the store offers one.
     */
    public function containersStepLabel(): string
    {
        return $this->urnVaults()->isNotEmpty() ? __('Container, Urn & Vault') : __('Container & Urn');
    }

    /**
     * Container / urn options, without those priced below the package's
     * allowance when the package asks for that. If that would hide every
     * option, all of them are shown so a required selection is still possible.
     */
    private function slotOptions(ProductCategory $category, string $slot): Collection
    {
        $options = $this->productsFor($category);
        $allowanceCents = $this->cart()->packageAllowanceCents($slot);

        if (! $this->cart()->hidesOptionsBelowAllowance() || $allowanceCents === 0) {
            return $options;
        }

        $filtered = $options->filter(fn (Product $product) => $product->price_cents >= $allowanceCents)->values();

        return $filtered->isNotEmpty() ? $filtered : $options;
    }

    /**
     * The package allowance toward the "container" or "urn" slot.
     */
    public function allowanceCents(string $slot): int
    {
        return $this->cart()->packageAllowanceCents($slot);
    }

    /**
     * What a container / urn costs once the package allowance is applied,
     * e.g. "Included with your package" or "$150.00 after allowance".
     */
    public function allowanceNote(Product $product, string $slot): ?string
    {
        $allowanceCents = $this->allowanceCents($slot);

        if ($allowanceCents === 0 || $product->price_cents === 0) {
            return null;
        }

        if ($product->price_cents <= $allowanceCents) {
            return __('Included with your package');
        }

        return __(':price after allowance', ['price' => '$'.number_format(($product->price_cents - $allowanceCents) / 100, 2)]);
    }

    /**
     * Pricing for an item the package includes, e.g. "2 included, then
     * $15.00 per copy", or "Service fee included, then $15.00 each" when the
     * package covers only the base fee.
     */
    public function includedNote(Product $product, int $includedQuantity): string
    {
        if (! $this->canAddBeyondIncluded($product)) {
            return __('Included');
        }

        $unitCents = $product->hasPerUnitPricing() ? $product->per_unit_price_cents : $product->price_cents;
        $unit = $product->hasPerUnitPricing() && $product->per_unit_label
            ? __('per :unit', ['unit' => $product->per_unit_label])
            : __('each');

        if ($includedQuantity === 0) {
            return __('Service fee included, then :price :unit', [
                'price' => '$'.number_format($unitCents / 100, 2),
                'unit' => $unit,
            ]);
        }

        return __(':count included, then :price :unit', [
            'count' => $includedQuantity,
            'price' => '$'.number_format($unitCents / 100, 2),
            'unit' => $unit,
        ]);
    }

    /**
     * Whether the customer can buy more of an included item than the
     * package covers.
     */
    public function canAddBeyondIncluded(Product $product): bool
    {
        return $product->hasPerUnitPricing() || $product->allow_multiple_quantity;
    }

    /**
     * Whether a service / add-on card shows a quantity selector rather than
     * acting as a simple selected / not-selected choice.
     */
    public function showsQuantitySelector(Product $product): bool
    {
        return $product->allow_multiple_quantity;
    }

    /**
     * Whether the customer can deselect a service / add-on — required and
     * package-included items always stay on the order.
     */
    public function canToggleExtra(Product $product): bool
    {
        return ! $this->hasOptions($product) && ! $product->is_required && $this->includedQuantity($product->id) === 0;
    }

    /**
     * Whether a product is a choose-one item, where the customer picks one
     * of its options instead of selecting the item itself.
     */
    public function hasOptions(Product $product): bool
    {
        return $product->category === ProductCategory::Choice && $product->variants->isNotEmpty();
    }

    public function selectedOptionId(int $productId): ?int
    {
        return $this->cart()->selectedVariantId($productId);
    }

    public function optionPriceLabel(Product $product, ProductVariant $option): string
    {
        return '$'.number_format(($product->price_cents + $option->price_delta_cents) / 100, 2);
    }

    /**
     * Choose one of a "choose one" service / add-on's options, or clear the
     * choice (null) when the item is optional.
     */
    public function selectExtraOption(int $productId, ?int $optionId): void
    {
        $product = $this->extras()->firstWhere('id', $productId);

        if (! $product || ! $this->hasOptions($product)) {
            return;
        }

        $option = $optionId ? $product->variants->firstWhere('id', $optionId) : null;

        if ($option) {
            $this->cart()->selectOption($product, $option);
        } elseif ($optionId === null) {
            $this->cart()->clearOption($product);
        } else {
            return;
        }

        $this->resetErrorBag(["options.{$productId}", 'options']);
        $this->syncFromCart();
        $this->dispatch('cart-updated');
    }

    /**
     * Whether the customer answered an optional "choose one" item with "No thanks".
     */
    public function declinedOption(int $productId): bool
    {
        return $this->cart()->hasDeclinedOption($productId);
    }

    /**
     * What a selected service / add-on currently adds to the order at its
     * chosen quantity, after anything the package covers.
     */
    public function extraTotal(int $productId): string
    {
        $line = $this->cart()->allLines()->get($productId.'-0');
        $totalCents = $line ? $this->cart()->lineTotalCents($line) : 0;

        return '$'.number_format($totalCents / 100, 2);
    }

    /**
     * How many units of a product the selected package includes.
     */
    public function includedQuantity(int $productId): int
    {
        return $this->cart()->includedQuantity($productId.'-0');
    }

    /**
     * Whether the selected package covers only a per-unit product's base fee.
     */
    public function includesBaseFee(int $productId): bool
    {
        return $this->cart()->includesBaseFee($productId);
    }

    public function keepsakes(): Collection
    {
        return $this->productsFor(ProductCategory::Keepsake);
    }

    /**
     * Services and add-ons, including required ones, followed by the
     * choose-one items that have options to choose from. Anything the
     * selected package includes is already listed on the package, so it's
     * left out here unless the customer can change its quantity.
     */
    public function extras(): Collection
    {
        $addons = $this->productsFor(ProductCategory::Addon)
            ->filter(fn (Product $product) => $this->includedQuantity($product->id) === 0 || $this->showsQuantitySelector($product));
        $choices = $this->productsFor(ProductCategory::Choice)->filter(fn (Product $product) => $this->hasOptions($product));

        return $addons->concat($choices)->values();
    }

    /**
     * The services and add-ons grouped by section heading: items without a
     * heading first, then each section in the order its first item appears.
     *
     * @return Collection<int, array{heading: string|null, products: Collection<int, Product>}>
     */
    public function extraSections(): Collection
    {
        return $this->extras()
            ->groupBy(fn (Product $product) => $product->section_heading ?? '')
            ->sortBy(fn (Collection $products, string $heading) => $heading === '' ? 0 : 1)
            ->map(fn (Collection $products, string $heading) => [
                'heading' => $heading === '' ? null : $heading,
                'products' => $products->values(),
            ])
            ->values();
    }

    private function productsFor(ProductCategory $category): Collection
    {
        $store = $this->storeModel();

        return $store->products()
            ->active()
            ->ofCategory($category)
            ->with('variants')
            ->orderedFor($store->productSortMode($category))
            ->get();
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
        $choice = OrderTiming::tryFrom($timing);

        if (! in_array($choice, $this->storeModel()->sale_type->orderTimings(), true)) {
            return;
        }

        $this->timing = $timing;
        $this->cart()->setTiming($choice);
        $this->ensureBasePackage();
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
        $this->replaceUnavailableSlotSelections();
        $this->syncFromCart();
        $this->dispatch('cart-updated');
    }

    /**
     * A new package may hide the container or urn already chosen (when it
     * hides options below its allowance), so swap in the option it covers.
     */
    private function replaceUnavailableSlotSelections(): void
    {
        $cart = $this->cart();

        foreach (['container' => $this->containers(), 'urn' => $this->urns()] as $slot => $options) {
            $selectedProductId = $cart->state()[$slot]['product_id'] ?? null;

            if ($selectedProductId === null || $options->contains('id', $selectedProductId)) {
                continue;
            }

            $replacement = $options->firstWhere('price_cents', '<=', $cart->packageAllowanceCents($slot)) ?? $options->first();

            if ($replacement) {
                $cart->selectSlot($replacement);
            }
        }
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

    public function selectUrnVault(int $productId): void
    {
        $product = $this->urnVaults()->firstWhere('id', $productId);

        if (! $product) {
            return;
        }

        $this->urnVaultId = $productId;
        $this->cart()->selectSlot($product);
        $this->resetErrorBag('urn_vault');
        $this->dispatch('cart-updated');
    }

    public function clearUrnVault(): void
    {
        $this->urnVaultId = null;
        $this->cart()->clearSlot(ProductCategory::UrnVault);
        $this->dispatch('cart-updated');
    }

    public function setKeepsakeQty(int $productId, ?int $variantId, int $qty): void
    {
        $product = $this->keepsakes()->merge($this->extras())->firstWhere('id', $productId);

        if (! $product || $this->hasOptions($product)) {
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

    /**
     * Select or deselect a service / add-on card, like choosing a container
     * or urn.
     */
    public function toggleExtra(int $productId): void
    {
        $product = $this->extras()->firstWhere('id', $productId);

        if (! $product || ! $this->canToggleExtra($product)) {
            return;
        }

        $isSelected = ($this->keepsakeQty[$productId.'-0'] ?? 0) > 0;

        $this->setKeepsakeQty($productId, null, $isSelected ? 0 : 1);
    }

    public function goToContainers(): void
    {
        if (! $this->hasLocation()) {
            $this->addError('location', __('Please choose your city to continue.'));

            return;
        }

        $this->ensureBasePackage();
        $this->preselectFirstOptions();
        $this->syncFromCart();

        $this->step = 'containers';
    }

    /**
     * For each category the store has chosen to pre-select, pick its first
     * option (in the store's sort order) when nothing is chosen yet.
     */
    private function preselectFirstOptions(): void
    {
        $cart = $this->cart();
        $store = $this->storeModel();

        $preselectedSlots = array_filter([
            'container' => $store->preselect_container ? $this->containers() : null,
            'urn' => $store->preselect_urn ? $this->urns() : null,
            'urn_vault' => $store->preselect_urn_vault ? $this->urnVaults() : null,
        ]);

        foreach ($preselectedSlots as $slot => $options) {
            if ($cart->state()[$slot] || $options->isEmpty()) {
                continue;
            }

            $cart->selectSlot($options->first());
        }

        $this->dispatch('cart-updated');
    }

    public function goToAddons(): void
    {
        $this->ensureBasePackage();

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

        if ($this->storeModel()->requires_urn_vault && $this->urnVaults()->isNotEmpty() && ! $this->cart()->hasUrnVault()) {
            $this->addError('urn_vault', __('Please choose an urn vault to continue.'));

            return;
        }

        $this->cart()->ensureRequiredLines();
        $this->cart()->preselectDefaultOptions();
        $this->syncFromCart();
        $this->dispatch('cart-updated');

        $this->step = 'addons';
    }

    /**
     * Every "choose one" item needs an answer, an option or "No thanks",
     * before the customer moves on. Each unanswered item gets its own error.
     */
    public function goToKeepsakes(): void
    {
        $unanswered = $this->cart()->unansweredOptions();

        if ($unanswered->isNotEmpty()) {
            foreach ($unanswered as $product) {
                $this->addError("options.{$product->id}", $product->is_required
                    ? __('Please choose an option for :name.', ['name' => $product->name])
                    : __('Please choose an option for :name, or No thanks.', ['name' => $product->name]));
            }

            $this->addError('options', trans_choice('Please make a selection for the item marked above to continue.|Please make a selection for each item marked above to continue.', $unanswered->count()));

            return;
        }

        $this->step = 'keepsakes';
    }

    public function goToDetails(): void
    {
        $this->step = 'details';
    }

    /**
     * Return to an earlier step, from a Back button or the step bar. Never
     * moves forward, since later steps validate the ones before them.
     */
    public function backTo(string $step): void
    {
        $targetIndex = array_search($step, $this->steps, true);

        if ($targetIndex === false || $targetIndex >= $this->stepIndex()) {
            return;
        }

        $this->step = $step;
        $this->clientSecret = null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function detailsRules(): array
    {
        $rules = [
            'purchaserFirstName' => ['required', 'string', 'max:255'],
            'purchaserLastName' => ['required', 'string', 'max:255'],
            'purchaserEmail' => ['required', 'email', 'max:255'],
            'purchaserPhone' => ['required', 'string', 'max:30'],
        ];

        if ($this->storeModel()->isPreNeed()) {
            $rules['arrangementFor'] = ['required', 'in:self,someone_else'];
        }

        // Someone planning for themselves is the person the arrangement is for.
        if (! $this->isPlanningForSelf()) {
            $rules['deceasedFirstName'] = ['required', 'string', 'max:255'];
            $rules['deceasedLastName'] = ['required', 'string', 'max:255'];
            $rules['relationshipToDeceased'] = ['required', 'string', 'max:255'];
        }

        return $rules;
    }

    /**
     * Whether a pre-need purchaser is arranging their own cremation, in
     * which case they are also the person the arrangement is for.
     */
    public function isPlanningForSelf(): bool
    {
        return $this->storeModel()->isPreNeed() && $this->arrangementFor === 'self';
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

        if ($this->isPlanningForSelf()) {
            $this->deceasedFirstName = $this->purchaserFirstName;
            $this->deceasedMiddleName = '';
            $this->deceasedLastName = $this->purchaserLastName;
            $this->deceasedSuffix = '';
            $this->relationshipToDeceased = 'Self';
        }

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

{{-- A new step should start at its top: on our own page scroll back up to
     it, and inside an embed let the layout ask the host page to do so. --}}
<div
    class="mx-auto max-w-5xl px-4 py-10 sm:px-6"
    x-data
    x-init="$watch('$wire.step', () => {
        if (window.self !== window.top) {
            window.dispatchEvent(new CustomEvent('tm-cremation-store:step-changed'));
        } else if ($el.getBoundingClientRect().top < 0) {
            $el.scrollIntoView({ behavior: 'smooth' });
        }
    })"
>
    <nav class="mb-8 flex flex-wrap items-center justify-center gap-2 text-xs font-medium text-zinc-400" aria-label="{{ __('Checkout steps') }}">
        @foreach ([$this->storeModel()->isPreNeed() ? 'Package' : 'Timing & Package', $this->containersStepLabel(), 'Add-ons & Services', 'Keepsakes', 'Your Information', 'Payment'] as $index => $label)
            {{-- Completed steps link back; the current and later steps aren't clickable. --}}
            @php($isCompleted = $index < $this->stepIndex())
            <{{ $isCompleted ? 'button' : 'span' }}
                @if ($isCompleted)
                    type="button"
                    wire:click="backTo('{{ $steps[$index] }}')"
                    title="{{ __('Go back to :step', ['step' => __($label)]) }}"
                    class="group flex items-center gap-2 rounded-full hover:text-brand-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                @else
                    class="flex items-center gap-2"
                    @if ($index === $this->stepIndex()) aria-current="step" @endif
                @endif
            >
                <span @class([
                    'flex size-6 items-center justify-center rounded-full text-[11px]',
                    'bg-store text-store-foreground' => $index <= $this->stepIndex(),
                    'group-hover:ring-2 group-hover:ring-brand-300' => $isCompleted,
                    'bg-zinc-100 text-zinc-400' => $index > $this->stepIndex(),
                ])>{{ $index + 1 }}</span>
                <span @class([
                    'hidden sm:inline',
                    'text-brand-800' => $index === $this->stepIndex(),
                    'underline-offset-2 group-hover:underline' => $isCompleted,
                ])>{{ __($label) }}</span>
                @if ($isCompleted)
                    <span class="sr-only sm:hidden">{{ __('Go back to :step', ['step' => __($label)]) }}</span>
                @endif
            </{{ $isCompleted ? 'button' : 'span' }}>
            @if (! $loop->last)
                <span class="h-px w-4 bg-zinc-200"></span>
            @endif
        @endforeach
    </nav>

    {{-- Step 1: Timing & Package --}}
    @if ($step === 'timing')
        <div>
            <flux:heading size="xl" class="font-serif">{{ __('A few details to get started') }}</flux:heading>
            <flux:subheading class="mt-1">{{ __('This helps us show you appropriate options.') }}</flux:subheading>

            @if ($this->usesLocationPricing())
                <div class="mt-6 grid gap-3 sm:grid-cols-2">
                    <flux:field>
                        <flux:label>{{ __('State') }}</flux:label>
                        <flux:select wire:model.live="locationState">
                            <option value="">{{ __('Choose a state') }}</option>
                            @foreach ($this->locationStates() as $state)
                                <option value="{{ $state->value }}">{{ $state->label() }}</option>
                            @endforeach
                        </flux:select>
                    </flux:field>
                    @if ($locationState)
                        <flux:field>
                            <flux:label>{{ __('City') }}</flux:label>
                            <flux:select wire:model.live="locationId" wire:key="cities-{{ $locationState }}">
                                <option value="">{{ __('Choose a city') }}</option>
                                @foreach ($this->citiesInState() as $location)
                                    <option value="{{ $location->id }}">{{ $location->city }}</option>
                                @endforeach
                            </flux:select>
                        </flux:field>
                    @endif
                    <flux:error name="location" :deep="false" class="sm:col-span-2" />
                </div>
            @endif

            <div @class(['mt-6 grid gap-3 sm:grid-cols-2', 'hidden' => ! $this->hasLocation() || $this->storeModel()->isPreNeed()])>
                @foreach ($this->storeModel()->sale_type->orderTimings() as $option)
                    <label @class([
                        'flex cursor-pointer items-start gap-3 rounded-xl border p-4 transition',
                        'border-brand-600 bg-brand-50' => $timing === $option->value,
                        'border-zinc-200 bg-white hover:border-brand-300' => $timing !== $option->value,
                    ])>
                        <input type="radio" name="timing" value="{{ $option->value }}" wire:model="timing" wire:click="selectTiming('{{ $option->value }}')" class="mt-1 accent-[var(--color-brand-700)]" />
                        <span class="text-sm text-zinc-700">{{ $option->label() }}</span>
                    </label>
                @endforeach
                <flux:error name="timing" :deep="false" class="sm:col-span-2" />
            </div>

            @if ($timing && $this->hasLocation())
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
                                {{-- flex-col + justify-start: a <button> otherwise vertically
                                     centers its content when the grid stretches it to row height. --}}
                                @class([
                                    'relative flex flex-col justify-start overflow-hidden rounded-xl border text-left shadow-sm transition',
                                    'border-brand-600 bg-brand-50' => $packageId === $product->id,
                                    'border-zinc-200 bg-white hover:border-brand-300' => $packageId !== $product->id,
                                    'cursor-default' => $this->isALaCarte(),
                                ])
                            >
                                {{-- À la carte stores have only the one base package, so "Selected" would say nothing. --}}
                                @if (! $this->isALaCarte() && $packageId === $product->id)
                                    <span class="absolute left-2 top-2 flex items-center gap-1 rounded-full bg-store px-2 py-0.5 text-xs font-semibold text-store-foreground shadow">
                                        <flux:icon.check class="size-3.5" />{{ __('Selected') }}
                                    </span>
                                @endif
                                <div @class(['flex flex-1 flex-col p-5', 'sm:p-6' => $this->isALaCarte()])>
                                    <p @class(['font-medium text-zinc-800', 'font-serif text-xl' => $this->isALaCarte()])>{{ $product->name }}</p>
                                    @if ($product->description)
                                        <p class="mt-1 text-sm text-zinc-500">{{ $product->description }}</p>
                                    @endif
                                    @if ($includedItems = $product->includedItemsList())
                                        <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-zinc-600 marker:text-brand-600">
                                            @foreach ($includedItems as $item)
                                                <li>{{ $item }}</li>
                                            @endforeach
                                        </ul>
                                    @endif
                                    {{-- mt-auto keeps prices aligned along the bottom of each row. --}}
                                    <p @class(['mt-auto pt-4 font-semibold text-brand-700', 'text-xl' => $this->isALaCarte()])>{{ $this->packagePriceLabel($product) }}</p>
                                </div>
                            </button>
                        @empty
                            <p class="text-sm text-zinc-500">{{ __('No packages are available for this option yet. Please call us for assistance.') }}</p>
                        @endforelse
                    </div>
                    <flux:error name="package" :deep="false" class="mt-2" />
                </div>

                <div class="mt-8 flex justify-end">
                    <flux:button variant="primary" class="!bg-store hover:!bg-store-hover !text-store-foreground" wire:click="goToContainers" :disabled="! $packageId">
                        {{ __('Continue') }}
                    </flux:button>
                </div>
            @endif
        </div>
    @endif

    {{-- Step 2: Cremation container, urn & urn vault --}}
    @if ($step === 'containers')
        <div class="rounded-2xl border border-brand-100 bg-white p-6 shadow-sm sm:p-8" x-data="{ zoom: null }" x-on:keydown.escape.window="zoom = null">
            <flux:heading size="xl" class="font-serif">{{ $this->urnVaults()->isNotEmpty() ? __('Cremation container, urn & vault') : __('Cremation container & urn') }}</flux:heading>
            <flux:subheading class="mt-1">{{ __('Choose what fits — anything marked required must be selected before you continue.') }}</flux:subheading>

            @if ($this->containers()->isNotEmpty())
                <div class="mt-8">
                    <flux:heading size="lg">{{ __('Cremation container') }}</flux:heading>
                    <p class="mb-3 text-xs text-zinc-500">
                        {{ $this->storeModel()->requires_container ? __('Required by law for dignified care and handling.') : __('Optional — you can also decide later.') }}
                    </p>
                    @if ($this->allowanceCents('container') > 0)
                        <p class="mb-3 rounded-lg bg-brand-50 px-3 py-2 text-xs text-brand-800">{{ __('Your package includes a :amount allowance toward a cremation container.', ['amount' => '$'.number_format($this->allowanceCents('container') / 100, 2)]) }}</p>
                    @endif
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                        @foreach ($this->containers() as $product)
                            <div class="relative" wire:key="container-{{ $product->id }}">
                                <button type="button" wire:click="selectContainer({{ $product->id }})" @class([
                                    'block h-full w-full overflow-hidden rounded-xl border text-left transition',
                                    'border-2 border-brand-600 bg-brand-50 ring-2 ring-brand-600/30' => $containerId === $product->id,
                                    'border border-zinc-200 hover:border-brand-300' => $containerId !== $product->id,
                                ])>
                                    <x-product-image :src="$product->imageUrl()" :alt="$product->name" :category="$product->category->value" class="aspect-square w-full" />
                                    @if ($containerId === $product->id)
                                        <span class="absolute left-2 top-2 flex items-center gap-1 rounded-full bg-store px-2 py-0.5 text-xs font-semibold text-store-foreground shadow">
                                            <flux:icon.check class="size-3.5" />{{ __('Selected') }}
                                        </span>
                                    @endif
                                    <div class="p-3">
                                        <p class="text-sm font-medium text-zinc-800">{{ $product->name }}</p>
                                        @if ($product->description)
                                            <p class="mt-1 text-xs text-zinc-500">{{ $product->description }}</p>
                                        @endif
                                        <p class="mt-1 text-sm text-brand-700">{{ $product->priceLabel() }}</p>
                                        @if ($note = $this->allowanceNote($product, 'container'))
                                            <p class="text-xs font-medium text-brand-800">{{ $note }}</p>
                                        @endif
                                    </div>
                                </button>
                                @if ($product->imageUrl())
                                    <button type="button" class="absolute right-2 top-2 flex size-6 items-center justify-center rounded-full bg-white/90 text-zinc-700 shadow hover:bg-white" x-on:click="zoom = { src: @js($product->imageUrl()), name: @js($product->name) }" aria-label="{{ __('View full size image of :name', ['name' => $product->name]) }}">
                                        <flux:icon.magnifying-glass-plus class="size-4" />
                                    </button>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    @if ($containerId && ! $this->storeModel()->requires_container)
                        <button type="button" wire:click="clearContainer" class="mt-2 text-xs text-zinc-400 underline hover:text-red-600">{{ __('Clear selection') }}</button>
                    @endif
                    <flux:error name="container" :deep="false" class="mt-2" />
                </div>
            @endif

            @if ($this->urns()->isNotEmpty())
                <div class="mt-8">
                    <flux:heading size="lg">{{ __('Urn') }}</flux:heading>
                    @if ($this->storeModel()->requires_urn)
                        <p class="mb-3 text-xs text-zinc-500">{{ __('An urn selection is required to continue.') }}</p>
                    @endif
                    @if ($this->allowanceCents('urn') > 0)
                        <p class="mb-3 rounded-lg bg-brand-50 px-3 py-2 text-xs text-brand-800">{{ __('Your package includes a :amount allowance toward an urn.', ['amount' => '$'.number_format($this->allowanceCents('urn') / 100, 2)]) }}</p>
                    @endif
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                        @foreach ($this->urns() as $product)
                            <div class="relative" wire:key="urn-{{ $product->id }}">
                                <button type="button" wire:click="selectUrn({{ $product->id }})" @class([
                                    'block h-full w-full overflow-hidden rounded-xl border text-left transition',
                                    'border-2 border-brand-600 bg-brand-50 ring-2 ring-brand-600/30' => $urnId === $product->id,
                                    'border border-zinc-200 hover:border-brand-300' => $urnId !== $product->id,
                                ])>
                                    <x-product-image :src="$product->imageUrl()" :alt="$product->name" :category="$product->category->value" class="aspect-square w-full" />
                                    @if ($urnId === $product->id)
                                        <span class="absolute left-2 top-2 flex items-center gap-1 rounded-full bg-store px-2 py-0.5 text-xs font-semibold text-store-foreground shadow">
                                            <flux:icon.check class="size-3.5" />{{ __('Selected') }}
                                        </span>
                                    @endif
                                    <div class="p-3">
                                        <p class="text-sm font-medium text-zinc-800">{{ $product->name }}</p>
                                        @if ($product->description)
                                            <p class="mt-1 text-xs text-zinc-500">{{ $product->description }}</p>
                                        @endif
                                        <p class="mt-1 text-sm text-brand-700">{{ $product->priceLabel() }}</p>
                                        @if ($note = $this->allowanceNote($product, 'urn'))
                                            <p class="text-xs font-medium text-brand-800">{{ $note }}</p>
                                        @endif
                                    </div>
                                </button>
                                @if ($product->imageUrl())
                                    <button type="button" class="absolute right-2 top-2 flex size-6 items-center justify-center rounded-full bg-white/90 text-zinc-700 shadow hover:bg-white" x-on:click="zoom = { src: @js($product->imageUrl()), name: @js($product->name) }" aria-label="{{ __('View full size image of :name', ['name' => $product->name]) }}">
                                        <flux:icon.magnifying-glass-plus class="size-4" />
                                    </button>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    @if ($urnId && ! $this->storeModel()->requires_urn)
                        <button type="button" wire:click="clearUrn" class="mt-2 text-xs text-zinc-400 underline hover:text-red-600">{{ __('Clear selection') }}</button>
                    @endif
                    <flux:error name="urn" :deep="false" class="mt-2" />
                </div>
            @endif

            @if ($this->urnVaults()->isNotEmpty())
                <div class="mt-8">
                    <flux:heading size="lg">{{ __('Urn vault') }}</flux:heading>
                    <p class="mb-3 text-xs text-zinc-500">
                        {{ $this->storeModel()->requires_urn_vault ? __('An urn vault selection is required to continue.') : __('Most cemeteries require a vault when an urn is buried.') }}
                    </p>
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                        @foreach ($this->urnVaults() as $product)
                            <div class="relative" wire:key="urn-vault-{{ $product->id }}">
                                <button type="button" wire:click="selectUrnVault({{ $product->id }})" @class([
                                    'block h-full w-full overflow-hidden rounded-xl border text-left transition',
                                    'border-2 border-brand-600 bg-brand-50 ring-2 ring-brand-600/30' => $urnVaultId === $product->id,
                                    'border border-zinc-200 hover:border-brand-300' => $urnVaultId !== $product->id,
                                ])>
                                    <x-product-image :src="$product->imageUrl()" :alt="$product->name" :category="$product->category->value" class="aspect-square w-full" />
                                    @if ($urnVaultId === $product->id)
                                        <span class="absolute left-2 top-2 flex items-center gap-1 rounded-full bg-store px-2 py-0.5 text-xs font-semibold text-store-foreground shadow">
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
                                    <button type="button" class="absolute right-2 top-2 flex size-6 items-center justify-center rounded-full bg-white/90 text-zinc-700 shadow hover:bg-white" x-on:click="zoom = { src: @js($product->imageUrl()), name: @js($product->name) }" aria-label="{{ __('View full size image of :name', ['name' => $product->name]) }}">
                                        <flux:icon.magnifying-glass-plus class="size-4" />
                                    </button>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    @if ($urnVaultId && ! $this->storeModel()->requires_urn_vault)
                        <button type="button" wire:click="clearUrnVault" class="mt-2 text-xs text-zinc-400 underline hover:text-red-600">{{ __('Clear selection') }}</button>
                    @endif
                    <flux:error name="urn_vault" :deep="false" class="mt-2" />
                </div>
            @endif

            @if ($this->containers()->isEmpty() && $this->urns()->isEmpty() && $this->urnVaults()->isEmpty())
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
                <flux:button variant="primary" class="!bg-store hover:!bg-store-hover !text-store-foreground" wire:click="goToAddons">
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

            @if (($extraSections = $this->extraSections())->isNotEmpty())
                @foreach ($extraSections as $section)
                    <div @class(['space-y-3', 'mt-6' => $loop->first, 'mt-8' => ! $loop->first]) wire:key="extra-section-{{ $loop->index }}">
                        @if ($section['heading'])
                            <h3 class="font-serif text-lg text-zinc-800">{{ $section['heading'] }}</h3>
                        @endif
                        @foreach ($section['products'] as $product)
                            @php($qty = (int) ($keepsakeQty[$product->id.'-0'] ?? 0))
                            @php($includedQty = $this->includedQuantity($product->id))
                            @php($hasOptions = $this->hasOptions($product))
                            @php($selectedOptionId = $hasOptions ? $this->selectedOptionId($product->id) : null)
                            @php($isSelected = $hasOptions ? $selectedOptionId !== null : ($qty > 0 || $includedQty > 0 || $product->is_required))
                            @php($canToggle = $this->canToggleExtra($product))
                            @php($needsAnswer = $errors->has("options.{$product->id}"))
                            <div wire:key="extra-{{ $product->id }}" @class([
                                'relative overflow-hidden rounded-xl transition',
                                'border-2 border-brand-600 bg-brand-50 ring-2 ring-brand-600/30' => $isSelected,
                                'border-2 border-red-500' => ! $isSelected && $needsAnswer,
                                'border border-zinc-200 hover:border-brand-300' => ! $isSelected && ! $needsAnswer,
                            ])>
                                @if ($canToggle)
                                    <button type="button" wire:click="toggleExtra({{ $product->id }})" class="absolute inset-0 z-10 rounded-xl focus-visible:outline-2 focus-visible:outline-brand-600" aria-pressed="{{ $isSelected ? 'true' : 'false' }}" aria-label="{{ $isSelected ? __('Remove :name', ['name' => $product->name]) : __('Select :name', ['name' => $product->name]) }}"></button>
                                @endif
                                <div class="flex items-start gap-3 p-3">
                                    @if ($isSelected)
                                        <span class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-store text-store-foreground" title="{{ __('Selected') }}">
                                            <flux:icon.check class="size-3.5" />
                                            <span class="sr-only">{{ __('Selected') }}</span>
                                        </span>
                                    @else
                                        <span class="mt-0.5 size-5 shrink-0 rounded-full border-2 border-zinc-300" aria-hidden="true"></span>
                                    @endif
                                    <div class="flex min-w-0 flex-1 flex-col gap-1 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
                                        <div class="min-w-0">
                                            <p class="text-sm font-medium text-zinc-800">{{ $product->name }}</p>
                                            @if ($product->description)
                                                <p class="mt-1 text-xs text-zinc-500">{{ $product->description }}</p>
                                            @endif
                                        </div>
                                        <div class="shrink-0 sm:text-right">
                                            <p class="text-sm text-brand-700">{{ match (true) {
                                                $hasOptions => __('Choose one'),
                                                $includedQty > 0 || $this->includesBaseFee($product->id) => $this->includedNote($product, $includedQty),
                                                default => $product->priceLabel(),
                                            } }}</p>
                                            @if ($includedQty > 0 || $this->includesBaseFee($product->id))
                                                <span class="mt-1 inline-block rounded-full bg-brand-100 px-2 py-0.5 text-xs font-medium text-brand-800">{{ __('Included with your package') }}</span>
                                            @elseif ($product->is_required)
                                                <span class="mt-1 inline-block rounded-full bg-brand-100 px-2 py-0.5 text-xs font-medium text-brand-800">{{ __('Required') }}</span>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                                <div>
                                    @if ($hasOptions)
                                        <fieldset class="space-y-1 border-t border-brand-200 py-2 pr-3 pl-11">
                                            <legend class="sr-only">{{ __('Options for :name', ['name' => $product->name]) }}</legend>
                                            @foreach ($product->variants as $option)
                                                <div wire:key="extra-{{ $product->id }}-option-{{ $option->id }}" @if ($option->description) x-data="{ detail: false }" @endif>
                                                    <label class="flex cursor-pointer items-start gap-2 text-sm text-zinc-700">
                                                        <input type="radio" name="extra-option-{{ $product->id }}" value="{{ $option->id }}" @checked($selectedOptionId === $option->id) wire:click="selectExtraOption({{ $product->id }}, {{ $option->id }})" class="mt-1 accent-[var(--color-brand-700)]" />
                                                        <span class="flex-1">
                                                            {{ $option->name }}
                                                            @if ($option->description)
                                                                <button type="button" class="ml-1 text-xs text-brand-700 underline hover:text-brand-900" x-on:click.prevent="detail = ! detail" x-bind:aria-expanded="detail" aria-controls="option-detail-{{ $option->id }}">
                                                                    <span x-text="detail ? @js(__('Hide detail')) : @js(__('Show detail'))">{{ __('Show detail') }}</span>
                                                                </button>
                                                            @endif
                                                        </span>
                                                        <span class="font-medium text-brand-700">{{ $this->optionPriceLabel($product, $option) }}</span>
                                                    </label>
                                                    @if ($option->description)
                                                        <p id="option-detail-{{ $option->id }}" x-show="detail" x-transition.opacity style="display: none" class="ml-6 mt-1 whitespace-pre-line rounded-lg bg-white/70 px-3 py-2 text-xs text-zinc-600">{{ $option->description }}</p>
                                                    @endif
                                                </div>
                                            @endforeach
                                            @unless ($product->is_required)
                                                <label class="flex cursor-pointer items-start gap-2 text-sm text-zinc-500">
                                                    <input type="radio" name="extra-option-{{ $product->id }}" value="" @checked($selectedOptionId === null && $this->declinedOption($product->id)) wire:click="selectExtraOption({{ $product->id }}, null)" class="mt-1 accent-[var(--color-brand-700)]" />
                                                    <span>{{ __('No thanks') }}</span>
                                                </label>
                                            @endunless
                                            <flux:error :name="'options.'.$product->id" class="pt-1" />
                                        </fieldset>
                                    @elseif ($this->showsQuantitySelector($product) && $isSelected)
                                        @php($minimumQty = max($includedQty, $product->is_required ? 1 : 0))
                                        <div class="relative z-20 flex flex-wrap items-center justify-between gap-2 border-t border-brand-200 py-2 pr-3 pl-11">
                                            <div class="flex items-center gap-2">
                                                <button type="button" class="flex size-7 items-center justify-center rounded-full border border-zinc-300 bg-white text-sm disabled:opacity-40" wire:click="setKeepsakeQty({{ $product->id }}, null, {{ $qty - 1 }})" @disabled($qty <= $minimumQty) aria-label="{{ __('Decrease quantity of :name', ['name' => $product->name]) }}">&minus;</button>
                                                <span class="w-6 text-center text-sm">{{ $qty }}</span>
                                                <button type="button" class="flex size-7 items-center justify-center rounded-full border border-zinc-300 bg-white text-sm" wire:click="setKeepsakeQty({{ $product->id }}, null, {{ $qty + 1 }})" aria-label="{{ __('Increase quantity of :name', ['name' => $product->name]) }}">+</button>
                                            </div>
                                            <p class="text-sm font-semibold text-zinc-800">{{ __('Total') }}: {{ $this->extraTotal($product->id) }}</p>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endforeach
            @else
                <p class="mt-6 text-sm text-zinc-500">{{ __('No services or add-ons are available for this option — you can continue to the next step.') }}</p>
            @endif
            <flux:error name="options" :deep="false" class="mt-2" />

            <div class="mt-8 flex items-center justify-between">
                <flux:button variant="ghost" wire:click="backTo('containers')">{{ __('Back') }}</flux:button>
                <flux:button variant="primary" class="!bg-store hover:!bg-store-hover !text-store-foreground" wire:click="goToKeepsakes">
                    {{ __('Continue') }}
                </flux:button>
            </div>
        </div>
    @endif

    {{-- Step 4: Keepsakes --}}
    @if ($step === 'keepsakes')
        <div class="rounded-2xl border border-brand-100 bg-white p-6 shadow-sm sm:p-8" x-data="{ zoom: null }" x-on:keydown.escape.window="zoom = null">
            <flux:heading size="xl" class="font-serif">{{ __('Keepsakes') }}</flux:heading>
            <flux:subheading class="mt-1">{{ __('Add as many as you like.') }}</flux:subheading>

            @php($cart = $this->cart())
            @if (($allowanceCents = $cart->keepsakeAllowanceCents()) > 0)
                @php($remainingCents = $cart->keepsakeAllowanceRemainingCents())
                {{-- Sticky so the balance stays in view while scrolling the keepsakes.
                     In the embed, the parent page scrolls rather than this frame, so
                     embed.js reports how far the frame is scrolled out of view
                     as --tm-viewport-top. --}}
                <div class="sticky top-[calc(var(--tm-viewport-top,0px)+0.75rem)] z-30 mt-6 rounded-xl border border-brand-200 bg-brand-50 p-3 shadow-md sm:p-4">
                    <div class="flex items-baseline justify-between gap-3">
                        <p class="min-w-0 text-sm font-medium text-zinc-800">{{ $cart->keepsakeAllowanceLabel() }}</p>
                        <p class="shrink-0 text-right text-zinc-600">
                            <span class="text-lg font-semibold text-brand-700">{{ __(':amount left', ['amount' => '$'.number_format($remainingCents / 100, 2)]) }}</span>
                            <span class="text-xs">{{ __('of :amount', ['amount' => '$'.number_format($allowanceCents / 100, 2)]) }}</span>
                        </p>
                    </div>
                    <p class="mt-1 text-xs text-zinc-600">
                        {{ __('Applied automatically to the keepsakes you choose.') }}
                        <span class="text-zinc-500">{{ __("Any amount you don't use here isn't taken off your total. It stays available after purchase, including for keepsakes not shown on our website.") }}</span>
                    </p>
                </div>
            @endif

            <div class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3">
                @forelse ($this->keepsakes() as $product)
                    @php($key = $product->id.'-0')
                    <div class="overflow-hidden rounded-xl border border-zinc-200">
                        @if ($product->imageUrl())
                            <button type="button" class="group relative block w-full" x-on:click="zoom = { src: @js($product->imageUrl()), name: @js($product->name) }" aria-label="{{ __('View full size image of :name', ['name' => $product->name]) }}">
                                <x-product-image :src="$product->imageUrl()" :alt="$product->name" :category="$product->category->value" class="aspect-square w-full" />
                                <span class="absolute bottom-1 right-1 flex size-6 items-center justify-center rounded-full bg-white/90 text-zinc-700 shadow group-hover:bg-white">
                                    <flux:icon.magnifying-glass-plus class="size-4" />
                                </span>
                            </button>
                        @else
                            <x-product-image :category="$product->category->value" class="aspect-square w-full" />
                        @endif
                        <div class="p-3">
                            <p class="text-sm font-medium text-zinc-800">{{ $product->name }}</p>
                            @if ($product->description)
                                <p class="mt-1 text-xs text-zinc-500">{{ $product->description }}</p>
                            @endif
                            @php($includedQty = $this->includedQuantity($product->id))
                            <p class="mt-1 text-sm text-brand-700">{{ $includedQty > 0 || $this->includesBaseFee($product->id) ? $this->includedNote($product, $includedQty) : $product->priceLabel() }}</p>
                            @if ($includedQty > 0 || $this->includesBaseFee($product->id))
                                <span class="mt-2 inline-block rounded-full bg-brand-50 px-2 py-0.5 text-xs font-medium text-brand-800">{{ __('Included with your package') }}</span>
                            @endif
                            @if ($includedQty === 0 || $this->canAddBeyondIncluded($product))
                                <div class="mt-2 flex items-center gap-2">
                                    <button type="button" class="flex size-7 items-center justify-center rounded-full border border-zinc-200 text-sm disabled:opacity-40" wire:click="setKeepsakeQty({{ $product->id }}, null, {{ (int) ($keepsakeQty[$key] ?? 0) - 1 }})" @disabled($includedQty > 0 && (int) ($keepsakeQty[$key] ?? 0) <= $includedQty)>&minus;</button>
                                    <span class="w-4 text-center text-sm">{{ $keepsakeQty[$key] ?? 0 }}</span>
                                    <button type="button" class="flex size-7 items-center justify-center rounded-full border border-zinc-200 text-sm" wire:click="setKeepsakeQty({{ $product->id }}, null, {{ (int) ($keepsakeQty[$key] ?? 0) + 1 }})">+</button>
                                </div>
                            @endif
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
                <flux:button variant="primary" class="!bg-store hover:!bg-store-hover !text-store-foreground" wire:click="goToDetails">
                    {{ __('Continue') }}
                </flux:button>
            </div>
        </div>
    @endif

    {{-- Step 5: Minimal purchaser + deceased details --}}
    @if ($step === 'details')
        <div class="mx-auto max-w-3xl rounded-2xl border border-brand-100 bg-white p-6 shadow-sm sm:p-8">
            <flux:heading size="xl" class="font-serif">{{ __('Your information') }}</flux:heading>
            <flux:subheading class="mt-1">{{ __("Just the essentials for now — we'll ask for more after your payment is secured.") }}</flux:subheading>

            <form wire:submit="submitDetails" class="mt-6 space-y-6">
                @if ($this->storeModel()->isPreNeed())
                    <flux:field>
                        <flux:label>{{ __('Who is this arrangement for?') }}</flux:label>
                        <flux:radio.group wire:model.live="arrangementFor" variant="cards" class="max-sm:flex-col">
                            <flux:radio value="self" :label="__('Myself')" :description="__('I\'m planning my own arrangements.')" />
                            <flux:radio value="someone_else" :label="__('Someone else')" :description="__('I\'m planning for a family member or someone in my care.')" />
                        </flux:radio.group>
                        <flux:error name="arrangementFor" />
                    </flux:field>
                @endif

                <div @class(['hidden' => $this->storeModel()->isPreNeed() && $arrangementFor !== 'someone_else'])>
                    <flux:heading size="sm" class="mb-3 text-zinc-500">{{ $this->storeModel()->isPreNeed() ? __('The person this arrangement is for') : __('Your loved one') }}</flux:heading>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:field>
                            <flux:label>{{ __('First name') }}</flux:label>
                            <flux:input wire:model="deceasedFirstName" />
                            <flux:error name="deceasedFirstName" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('Last name') }}</flux:label>
                            <flux:input wire:model="deceasedLastName" />
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
                        <flux:field @class(['sm:col-span-2', 'hidden' => $this->isPlanningForSelf()])>
                            <flux:label>{{ $this->storeModel()->isPreNeed() ? __('Your relationship to them') : __('Your relationship to the deceased') }}</flux:label>
                            <flux:select wire:model="relationshipToDeceased">
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
                    <flux:button type="submit" variant="primary" class="!bg-store hover:!bg-store-hover !text-store-foreground">
                        {{ __('Continue to payment') }}
                    </flux:button>
                </div>
            </form>
        </div>
    @endif

    {{-- Step 6: Payment --}}
    @if ($step === 'payment')
        @php($cart = $this->cart())
        <div class="grid items-start gap-6 lg:grid-cols-5">
            <aside class="rounded-2xl border border-brand-100 bg-white p-6 shadow-sm lg:sticky lg:top-6 lg:order-last lg:col-span-2" aria-label="{{ __('Order summary') }}">
                <flux:heading size="lg" class="font-serif">{{ __('Your order') }}</flux:heading>
    
                <ul class="mt-4 divide-y divide-zinc-100 text-sm">
                    @foreach ($cart->allLines() as $key => $line)
                        @php($discountCents = $cart->lineDiscountCents($line))
                        <li class="flex items-start justify-between gap-3 py-3 first:pt-0" wire:key="summary-line-{{ $key }}">
                            <div class="min-w-0">
                                <p class="font-medium text-zinc-800">{{ $line['name'] }}</p>
                                @if ($line['variant_name'] ?? null)
                                    <p class="text-xs text-zinc-500">{{ $line['variant_name'] }}</p>
                                @endif
                                @if ($line['quantity'] > 1 || ($line['unit_label'] ?? null))
                                    <p class="text-xs text-zinc-500">{{ __('Qty :count', ['count' => $line['quantity']]) }}{{ ($line['unit_label'] ?? null) ? ' '.str($line['unit_label'])->plural($line['quantity']) : '' }}</p>
                                @endif
                                @if ($discountCents > 0)
                                    <p class="text-xs font-medium text-brand-800">{{ __('Includes :amount package credit', ['amount' => '$'.number_format($discountCents / 100, 2)]) }}</p>
                                @endif
                            </div>
                            <span class="shrink-0 font-medium text-zinc-800">${{ number_format($cart->lineTotalCents($line) / 100, 2) }}</span>
                        </li>
                    @endforeach
                </ul>
    
                <div class="mt-2 space-y-1 border-t border-zinc-200 pt-3 text-sm">
                    <div class="flex justify-between text-zinc-600">
                        <span>{{ __('Subtotal') }}</span>
                        <span>${{ number_format($cart->subtotalCents() / 100, 2) }}</span>
                    </div>
                    @if ($cart->taxCents() > 0)
                        <div class="flex justify-between text-zinc-600">
                            <span>{{ __('Tax') }}</span>
                            <span>${{ number_format($cart->taxCents() / 100, 2) }}</span>
                        </div>
                    @endif
                    @if ($cart->processingFeeCents() > 0)
                        <div class="flex justify-between text-zinc-600">
                            <span>{{ __('Processing fee') }}</span>
                            <span>${{ number_format($cart->processingFeeCents() / 100, 2) }}</span>
                        </div>
                    @endif
                    <div class="flex justify-between border-t border-zinc-200 pt-2 text-base font-semibold text-zinc-800">
                        <span>{{ __('Total due today') }}</span>
                        <span>${{ number_format($cart->totalCents() / 100, 2) }}</span>
                    </div>
                </div>
            </aside>
    
            <div class="rounded-2xl border border-brand-100 bg-white p-6 shadow-sm sm:p-8 lg:col-span-3">
                <flux:heading size="xl" class="font-serif">{{ __('Secure payment') }}</flux:heading>
                <flux:subheading class="mt-1">{{ __('Your card details are handled directly and securely by Stripe.') }}</flux:subheading>
    
                <div class="mt-6" wire:ignore>
                    <form id="payment-form">
                        <div id="payment-element"></div>
                        <p id="payment-errors" class="mt-3 text-sm text-red-600"></p>
                        <button id="pay-button" type="submit" class="mt-4 w-full rounded-lg bg-store px-4 py-3 text-sm font-semibold text-store-foreground transition hover:bg-store-hover disabled:opacity-50">
                            {{ __('Pay now') }}
                        </button>
                    </form>
                </div>
    
                <button type="button" wire:click="backTo('details')" class="mt-4 text-xs text-zinc-400 underline">{{ __('Back') }}</button>
            </div>
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
                // No Link: "save my info for faster checkout" doesn't belong here, and it brings its own bank and Klarna options.
                const paymentElement = elements.create('payment', { wallets: { link: 'never' } });
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
