<?php

namespace App\Services;

use App\Enums\OrderTiming;
use App\Enums\ProductCategory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StoreLocation;
use Illuminate\Support\Collection;

/**
 * A session-backed shopping cart for one store's storefront.
 *
 * The package, container, urn, and urn vault are single-select "slots"
 * (choosing a new one replaces the old one), matching how cremation packages
 * are actually sold. Keepsakes and add-ons are repeatable line items with
 * quantities.
 *
 * @phpstan-type CartLine array{
 *     product_id: int,
 *     variant_id: int|null,
 *     category: string,
 *     name: string,
 *     variant_name: string|null,
 *     image_path: string|null,
 *     is_taxable?: bool,
 *     is_required: bool,
 *     unit_price_cents: int,
 *     quantity: int,
 *     taxable_unit_cents?: int,
 *     base_price_cents?: int,
 *     taxable_base_cents?: int,
 *     unit_label?: string|null,
 *     included_quantity?: int,
 *     quantity_before_package?: int,
 *     allowance_cents?: int,
 *     allowance_label?: string|null,
 *     keepsake_allowance_cents?: int,
 *     container_allowance_cents?: int,
 *     urn_allowance_cents?: int,
 *     hide_options_below_allowance?: bool,
 * }
 */
class Cart
{
    /** The fixed keys allLines() uses for the single-select slots. */
    private const SLOT_KEYS = ['package', 'container', 'urn', 'urn_vault'];

    /** @var array<string, mixed> */
    private array $state;

    public function __construct(private readonly Store $store)
    {
        // Merged over the empty state so a session cart saved before a slot existed still has its key.
        $this->state = [...$this->emptyState(), ...session()->get($this->sessionKey(), [])];
        $this->backfillTaxableAmounts();
    }

    /**
     * Lines added before per-line taxable amounts existed carry only the
     * is_taxable flag, which would tax a package's whole price. Recompute
     * them from the product so an in-flight session cart is taxed correctly.
     */
    private function backfillTaxableAmounts(): void
    {
        $changed = false;

        foreach ([...self::SLOT_KEYS, ...array_keys($this->state['lines'])] as $key) {
            $line = in_array($key, self::SLOT_KEYS, true) ? $this->state[$key] : $this->state['lines'][$key];

            if ($line === null || array_key_exists('taxable_unit_cents', $line)) {
                continue;
            }

            $product = Product::whereKey($line['product_id'])->first();

            if (! $product) {
                continue;
            }

            $variantDeltaCents = $line['unit_price_cents'] - $product->price_cents;
            $line['taxable_unit_cents'] = $this->taxableUnitCentsForProduct($product, $variantDeltaCents);

            if (in_array($key, self::SLOT_KEYS, true)) {
                $this->state[$key] = $line;
            } else {
                $this->state['lines'][$key] = $line;
            }

            $changed = true;
        }

        if ($changed) {
            $this->persist();
        }
    }

    private function sessionKey(): string
    {
        return "cart.{$this->store->id}";
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyState(): array
    {
        return [
            'timing' => null,
            'location_id' => null, // the city chosen, for location-priced stores
            'package' => null,
            'container' => null,
            'urn' => null,
            'urn_vault' => null,
            'lines' => [], // repeatable keepsakes / add-ons / services
            'declined_options' => [], // ids of "choose one" products answered with "No thanks"
            'base_fee_included_ids' => [], // ids of per-unit products whose base fee the package covers
            'included_options' => [], // choose-one product id => the option the package includes and its price
            'pending_order_id' => null,
        ];
    }

    /**
     * The order this cart is currently being checked out as, so a page
     * refresh or a step back reuses (and updates) it rather than creating
     * a duplicate. Cleared along with the cart once payment goes through.
     */
    public function pendingOrderId(): ?int
    {
        return $this->state['pending_order_id'] ?? null;
    }

    public function setPendingOrderId(?int $orderId): void
    {
        $this->state['pending_order_id'] = $orderId;
        $this->persist();
    }

    private function persist(): void
    {
        $this->applyKeepsakeAllowance();

        session()->put($this->sessionKey(), $this->state);
    }

    /**
     * Spread the keepsake allowance (from products like a "Legacy Touch
     * Allowance") across the keepsakes in the cart, in the order they were
     * added, until it runs out.
     */
    private function applyKeepsakeAllowance(): void
    {
        $remainingCents = $this->keepsakeAllowanceCents();
        $label = $this->keepsakeAllowanceLabel();

        foreach ($this->state['lines'] as $key => $line) {
            if ($line['category'] !== ProductCategory::Keepsake->value) {
                continue;
            }

            unset($line['allowance_cents'], $line['allowance_label']);
            $creditCents = min($remainingCents, $this->lineTotalCents($line));

            if ($creditCents > 0) {
                $line['allowance_cents'] = $creditCents;
                $line['allowance_label'] = $label;
                $remainingCents -= $creditCents;
            }

            $this->state['lines'][$key] = $line;
        }
    }

    /**
     * The total allowance toward keepsakes from the allowance products in
     * the cart, whether the package includes them or the customer added them.
     */
    public function keepsakeAllowanceCents(): int
    {
        return (int) collect($this->lines())
            ->sum(fn (array $line) => ($line['keepsake_allowance_cents'] ?? 0) * $line['quantity']);
    }

    /**
     * How much of the keepsake allowance the keepsakes in the cart haven't used yet.
     */
    public function keepsakeAllowanceRemainingCents(): int
    {
        $usedCents = collect($this->lines())
            ->where('category', ProductCategory::Keepsake->value)
            ->sum(fn (array $line) => $line['allowance_cents'] ?? 0);

        return max(0, $this->keepsakeAllowanceCents() - $usedCents);
    }

    /**
     * The name of the allowance product, e.g. "Legacy Touch Allowance", or a
     * general name when more than one is in the cart.
     */
    public function keepsakeAllowanceLabel(): ?string
    {
        $names = collect($this->lines())
            ->filter(fn (array $line) => ($line['keepsake_allowance_cents'] ?? 0) > 0)
            ->pluck('name')
            ->unique();

        return match ($names->count()) {
            0 => null,
            1 => $names->first(),
            default => __('Keepsake allowance'),
        };
    }

    /**
     * The timing chosen, limited to those the store offers. A pre-need store
     * only has the one, so it applies without the family being asked.
     */
    public function timing(): ?OrderTiming
    {
        $offered = $this->store->sale_type->orderTimings();

        if (count($offered) === 1) {
            return $offered[0];
        }

        $timing = OrderTiming::tryFrom($this->state['timing'] ?? '');

        return in_array($timing, $offered, true) ? $timing : null;
    }

    public function setTiming(OrderTiming $timing): void
    {
        $this->state['timing'] = $timing->value;
        $this->persist();
    }

    public function location(): ?StoreLocation
    {
        $locationId = $this->state['location_id'] ?? null;

        return $locationId ? $this->store->locations()->whereKey($locationId)->first() : null;
    }

    /**
     * Choose the city the arrangement is for. A package already selected is
     * re-priced for the new city; if it isn't offered there, the cart starts
     * over (everything else only makes sense in the context of a package).
     */
    public function setLocation(StoreLocation $location): void
    {
        $this->state['location_id'] = $location->id;

        $packageLine = $this->state['package'];
        $package = $packageLine ? Product::whereKey($packageLine['product_id'])->first() : null;

        if ($package && $this->packagePriceCents($package) !== null) {
            $variant = $packageLine['variant_id'] ? ProductVariant::whereKey($packageLine['variant_id'])->first() : null;
            $this->state['package'] = $this->lineFor($package, $variant, 1);
        } elseif ($packageLine) {
            $this->state = [
                ...$this->emptyState(),
                'timing' => $this->state['timing'],
                'location_id' => $location->id,
            ];
        }

        $this->persist();
    }

    /**
     * A package's price for the chosen city, or null when the store uses
     * location pricing and the package isn't offered there (or no city has
     * been chosen yet). Stores without location pricing use the package's
     * own price.
     */
    public function packagePriceCents(Product $package): ?int
    {
        if (! $this->store->usesLocationPricing()) {
            return $package->price_cents;
        }

        $locationId = $this->state['location_id'] ?? null;

        if (! $locationId) {
            return null;
        }

        return $package->locationPrices()->whereKey($locationId)->first()?->pivot->price_cents;
    }

    private function slotKeyFor(ProductCategory $category): ?string
    {
        return match ($category) {
            ProductCategory::Package => 'package',
            ProductCategory::Container => 'container',
            ProductCategory::Urn => 'urn',
            ProductCategory::UrnVault => 'urn_vault',
            default => null,
        };
    }

    public function selectSlot(Product $product, ?ProductVariant $variant = null): void
    {
        $slot = $this->slotKeyFor($product->category);

        if (! $slot) {
            $this->addLine($product, $variant);

            return;
        }

        $this->state[$slot] = $this->lineFor($product, $variant, 1);

        if ($slot === 'package') {
            $this->syncPackageInclusions();
        }

        $this->applyPackageAllowances();
        $this->persist();
    }

    /**
     * Credit the package's container / urn allowance against whichever
     * container and urn are selected, so a package can cover "any urn up to
     * $X" (or a specific urn, by setting the allowance to its price).
     */
    private function applyPackageAllowances(): void
    {
        foreach (['container', 'urn'] as $slot) {
            if ($this->state[$slot] !== null) {
                $this->state[$slot]['allowance_cents'] = $this->packageAllowanceCents($slot);
            }
        }
    }

    /**
     * The selected package's allowance toward the "container" or "urn" slot.
     */
    public function packageAllowanceCents(string $slot): int
    {
        return (int) ($this->state['package']["{$slot}_allowance_cents"] ?? 0);
    }

    /**
     * Whether the selected package hides container / urn options priced
     * below its allowance for them.
     */
    public function hidesOptionsBelowAllowance(): bool
    {
        return (bool) ($this->state['package']['hide_options_below_allowance'] ?? false);
    }

    /**
     * Swap the previous package's included items for the newly selected
     * package's. Included items are cart lines whose first included_quantity
     * units are free; the customer can still add more at the regular price.
     * Each line remembers its quantity from before the package included it,
     * so switching packages back and forth doesn't lose or inflate what the
     * customer chose themselves. An item included with a quantity of 0 (a
     * per-unit item like death certificates) only has its base fee covered,
     * and is charged per unit if the customer adds it.
     */
    private function syncPackageInclusions(): void
    {
        $this->state['base_fee_included_ids'] = [];

        // An option the previous package chose for the customer goes with it;
        // an upgrade they picked themselves stays.
        foreach ($this->state['included_options'] ?? [] as $productId => $included) {
            if ($this->selectedVariantId($productId) === $included['variant_id']) {
                $this->forgetProductLines($productId);
            }
        }

        $this->state['included_options'] = [];

        foreach ($this->state['lines'] as $key => $line) {
            $previouslyIncludedQuantity = $line['included_quantity'] ?? 0;

            if ($previouslyIncludedQuantity === 0) {
                continue;
            }

            $quantity = max($line['quantity_before_package'] ?? 0, $line['quantity'] - $previouslyIncludedQuantity);
            unset($line['included_quantity'], $line['quantity_before_package']);

            if ($quantity === 0 && ! ($line['is_required'] ?? false)) {
                unset($this->state['lines'][$key]);

                continue;
            }

            $line['quantity'] = max(1, $quantity);
            $this->state['lines'][$key] = $line;
        }

        $package = $this->state['package']
            ? Product::with(['includedProducts' => fn ($query) => $query->active()->with('variants')])->whereKey($this->state['package']['product_id'])->first()
            : null;

        foreach ($package->includedProducts ?? [] as $product) {
            $key = $this->lineKey($product, null);
            if ($product->category === ProductCategory::Choice) {
                $this->includeOption($product);

                continue;
            }

            $includedQuantity = $product->pivot->included_quantity;

            if ($includedQuantity === 0) {
                $this->state['base_fee_included_ids'][] = $product->id;

                continue;
            }

            $line = $this->state['lines'][$key] ?? $this->lineFor($product, null, 0);

            // Pick up an allowance set on the product since the line was added.
            unset($line['keepsake_allowance_cents']);
            $line = [...$line, ...$this->keepsakeAllowanceFor($product), 'is_taxable' => $product->is_taxable];

            $line['quantity_before_package'] = $line['quantity'];
            $line['quantity'] = max($line['quantity'], $includedQuantity);
            $line['included_quantity'] = $includedQuantity;

            $this->state['lines'][$key] = $line;
        }
    }

    public function clearSlot(ProductCategory $category): void
    {
        $slot = $this->slotKeyFor($category);

        if (! $slot || $this->isRequiredSlot($slot)) {
            return;
        }

        $this->state[$slot] = null;
        $this->persist();
    }

    /**
     * Whether the store has configured this slot as required, meaning the
     * customer can swap their selection but can't clear it back to none.
     */
    private function isRequiredSlot(string $slot): bool
    {
        return match ($slot) {
            'container' => $this->store->requires_container,
            'urn' => $this->store->requires_urn,
            'urn_vault' => $this->store->requires_urn_vault,
            default => false,
        };
    }

    public function addLine(Product $product, ?ProductVariant $variant = null, int $quantity = 1): void
    {
        $key = $this->lineKey($product, $variant);

        if (isset($this->state['lines'][$key])) {
            $this->state['lines'][$key]['quantity'] += $quantity;
            unset($this->state['lines'][$key]['quantity_before_package']);
        } else {
            $this->state['lines'][$key] = $this->lineFor($product, $variant, $quantity);
        }

        $this->persist();
    }

    /**
     * Choose one of a "choose one" product's options (its variants),
     * replacing whichever option was chosen before. Required products can
     * switch options here even though their lines can't otherwise be removed.
     */
    public function selectOption(Product $product, ProductVariant $variant): void
    {
        $this->forgetProductLines($product->id);
        $this->state['lines'][$this->lineKey($product, $variant)] = $this->lineFor($product, $variant, 1);
        $this->state['declined_options'] = array_values(array_diff($this->declinedOptionIds(), [$product->id]));
        $this->persist();
    }

    /**
     * Answer an optional "choose one" product with "No thanks", clearing
     * any option chosen before.
     */
    public function clearOption(Product $product): void
    {
        if ($product->is_required || $this->includedOptionId($product->id) !== null) {
            return;
        }

        $this->forgetProductLines($product->id);
        $this->state['declined_options'] = array_values(array_unique([...$this->declinedOptionIds(), $product->id]));
        $this->persist();
    }

    /**
     * The variant chosen for a "choose one" product, if any.
     */
    public function selectedVariantId(int $productId): ?int
    {
        return collect($this->lines())->firstWhere('product_id', $productId)['variant_id'] ?? null;
    }

    /**
     * Whether the customer answered this "choose one" product with "No thanks".
     */
    public function hasDeclinedOption(int $productId): bool
    {
        return in_array($productId, $this->declinedOptionIds(), true);
    }

    /**
     * @return array<int, int>
     */
    private function declinedOptionIds(): array
    {
        return $this->state['declined_options'] ?? [];
    }

    /**
     * Required "choose one" products the customer hasn't picked an option
     * for yet.
     *
     * @return Collection<int, Product>
     */
    public function missingRequiredOptions(): Collection
    {
        return $this->unansweredOptions()->where('is_required', true)->values();
    }

    /**
     * "Choose one" products the customer hasn't answered yet: no option
     * picked and, for optional ones, no "No thanks".
     *
     * @return Collection<int, Product>
     */
    public function unansweredOptions(): Collection
    {
        return $this->store->products()->active()->ofCategory(ProductCategory::Choice)->has('variants')->with('variants')
            ->orderBy('sort_order')->get()
            ->reject(fn (Product $product) => $this->selectedVariantId($product->id) !== null
                || (! $product->is_required && $this->hasDeclinedOption($product->id)))
            ->values();
    }

    /**
     * Pick the store's default option for each "choose one" product the
     * customer hasn't answered yet. Once they choose something else, or
     * "No thanks", their answer stands.
     */
    public function preselectDefaultOptions(): void
    {
        foreach ($this->unansweredOptions() as $product) {
            if ($default = $product->variants->firstWhere('is_default', true)) {
                $this->selectOption($product, $default);
            }
        }
    }

    private function forgetProductLines(int $productId): void
    {
        $this->state['lines'] = array_filter(
            $this->state['lines'],
            fn (array $line) => $line['product_id'] !== $productId,
        );
    }

    public function updateLineQuantity(string $key, int $quantity): void
    {
        if ($this->isRequiredLine($key)) {
            // A required item can't be removed, so its quantity never drops below 1.
            $quantity = max(1, $quantity);
        }

        // Units the package includes can't be removed either.
        $quantity = max($this->includedQuantity($key), $quantity);

        // A choose-one item the package includes is always exactly one.
        if ($this->includedOptionId($this->state['lines'][$key]['product_id'] ?? 0) !== null) {
            $quantity = 1;
        }

        if ($quantity <= 0) {
            unset($this->state['lines'][$key]);
        } elseif (isset($this->state['lines'][$key])) {
            $this->state['lines'][$key]['quantity'] = $quantity;
            // The customer has now chosen this quantity themselves.
            unset($this->state['lines'][$key]['quantity_before_package']);
        }

        $this->persist();
    }

    public function removeLine(string $key): void
    {
        $line = $this->state['lines'][$key] ?? null;
        $includedOptionId = $line ? $this->includedOptionId($line['product_id']) : null;

        if ($includedOptionId !== null) {
            // Removing an upgrade goes back to the option the package includes.
            if ($line['variant_id'] !== $includedOptionId && ($option = ProductVariant::find($includedOptionId))) {
                $this->selectOption($option->product, $option);
            }

            return;
        }

        if ($this->isRequiredLine($key)) {
            return;
        }

        if ($this->includedQuantity($key) > 0) {
            // Removing an included item only drops the extras added on top.
            $this->updateLineQuantity($key, 0);

            return;
        }

        unset($this->state['lines'][$key]);
        $this->persist();
    }

    /**
     * How many units of this line the selected package covers.
     */
    /**
     * Record the option of a choose-one item the package includes, and select
     * it unless the customer already chose an option that costs more.
     */
    private function includeOption(Product $product): void
    {
        $option = $product->includedOption();

        if (! $option) {
            return;
        }

        $includedCents = $product->optionPriceCents($option);
        $this->state['included_options'][$product->id] = ['variant_id' => $option->id, 'credit_cents' => $includedCents];

        $selected = $product->variants->firstWhere('id', $this->selectedVariantId($product->id));

        if (! $selected || $product->optionPriceCents($selected) < $includedCents) {
            $this->selectOption($product, $option);
        }
    }

    /**
     * The option of a choose-one item the selected package includes, if any.
     */
    public function includedOptionId(int $productId): ?int
    {
        return $this->state['included_options'][$productId]['variant_id'] ?? null;
    }

    /**
     * What the package covers of a choose-one line: the included option's price.
     *
     * @param  CartLine  $line
     */
    private function includedOptionCreditCents(array $line): int
    {
        return ($line['variant_id'] ?? null) ? (int) ($this->state['included_options'][$line['product_id']]['credit_cents'] ?? 0) : 0;
    }

    /**
     * Whether the selected package covers this product's base fee, leaving
     * only its per-unit price to pay.
     */
    public function includesBaseFee(int $productId): bool
    {
        return in_array($productId, $this->state['base_fee_included_ids'] ?? [], true);
    }

    public function includedQuantity(string $key): int
    {
        return (int) ($this->state['lines'][$key]['included_quantity'] ?? 0);
    }

    /**
     * Remove any line by its allLines() key, whether it's a single-select
     * slot ("package"/"container"/"urn"/"urn_vault") or a repeatable line
     * ("{productId}-{variantId}") — the two live in different parts of
     * $state, so the UI (which just iterates allLines() and doesn't know
     * which kind a given key is) can call this without caring.
     */
    public function removeAny(string $key): void
    {
        if ($key === 'package') {
            // Every other line only makes sense in the context of a
            // package, so removing it invalidates the whole cart rather
            // than leaving orphaned container/urn/keepsake selections behind.
            $this->clear();

            return;
        }

        if (in_array($key, self::SLOT_KEYS, true)) {
            if ($this->isRequiredSlot($key)) {
                return;
            }

            $this->state[$key] = null;
            $this->persist();

            return;
        }

        $this->removeLine($key);
    }

    public function isRequiredLine(string $key): bool
    {
        return (bool) ($this->state['lines'][$key]['is_required'] ?? false);
    }

    /**
     * Add every active required product that isn't already in the cart, and refresh the flag on lines already present.
     * Called by the wizard so required items are pre-selected for the customer.
     * Required "choose one" products are left for the customer to pick from.
     */
    public function ensureRequiredLines(): void
    {
        $query = $this->store->products()->active()->where('is_required', true)
            ->whereNotIn('category', [
                ProductCategory::Package->value,
                ProductCategory::Container->value,
                ProductCategory::Urn->value,
                ProductCategory::UrnVault->value,
                ProductCategory::Choice->value,
            ]);

        foreach ($query->orderBy('sort_order')->get() as $product) {
            $key = $this->lineKey($product, null);

            if (isset($this->state['lines'][$key])) {
                $this->state['lines'][$key]['is_required'] = true;

                continue;
            }

            $this->state['lines'][$key] = $this->lineFor($product, null, 1);
        }

        $this->persist();
    }

    private function lineKey(Product $product, ?ProductVariant $variant): string
    {
        return $product->id.'-'.($variant->id ?? '0');
    }

    /**
     * @return CartLine
     */
    private function lineFor(Product $product, ?ProductVariant $variant, int $quantity): array
    {
        $variantDeltaCents = $variant->price_delta_cents ?? 0;

        if ($product->hasPerUnitPricing()) {
            $baseCents = $product->price_cents + $variantDeltaCents;
            $unitCents = $product->per_unit_price_cents;

            return [
                ...$this->keepsakeAllowanceFor($product),
                'product_id' => $product->id,
                'variant_id' => $variant?->id,
                'category' => $product->category->value,
                'name' => $product->name,
                'variant_name' => $variant?->name,
                'image_path' => $product->image_path,
                'is_taxable' => $product->is_taxable,
                'is_required' => $product->is_required,
                'base_price_cents' => $baseCents,
                'taxable_base_cents' => $product->is_taxable ? $baseCents : 0,
                'taxable_unit_cents' => $product->is_taxable ? $unitCents : 0,
                'unit_label' => $product->per_unit_label,
                'unit_price_cents' => $unitCents,
                'quantity' => $quantity,
            ];
        }

        $priceCents = $product->category === ProductCategory::Package
            ? ($this->packagePriceCents($product) ?? $product->price_cents)
            : $product->price_cents;
        $unitPriceCents = $priceCents + $variantDeltaCents;

        $packageAllowances = $product->category === ProductCategory::Package
            ? [
                'container_allowance_cents' => (int) $product->container_allowance_cents,
                'urn_allowance_cents' => (int) $product->urn_allowance_cents,
                'hide_options_below_allowance' => $product->hide_options_below_allowance,
            ]
            : [];

        return [
            ...$packageAllowances,
            ...$this->keepsakeAllowanceFor($product),
            'product_id' => $product->id,
            'variant_id' => $variant?->id,
            'category' => $product->category->value,
            'name' => $product->name,
            'variant_name' => $variant?->name,
            'image_path' => $product->image_path,
            'is_taxable' => $product->is_taxable,
            'is_required' => $product->is_required,
            'taxable_unit_cents' => $this->taxableUnitCentsForProduct($product, $variantDeltaCents, $priceCents),
            'unit_price_cents' => $unitPriceCents,
            'quantity' => $quantity,
        ];
    }

    /**
     * The allowance toward keepsakes each unit of an allowance product gives.
     *
     * @return array{keepsake_allowance_cents?: int}
     */
    private function keepsakeAllowanceFor(Product $product): array
    {
        return $product->keepsake_allowance_cents > 0
            ? ['keepsake_allowance_cents' => $product->keepsake_allowance_cents]
            : [];
    }

    /**
     * The repeatable lines (keepsakes, add-ons, services), keyed as in allLines().
     *
     * @return array<string, CartLine>
     */
    private function lines(): array
    {
        return $this->state['lines'];
    }

    /**
     * Every line in the cart (slots + repeatable lines), keyed for the UI.
     *
     * @return Collection<string, CartLine>
     */
    public function allLines(): Collection
    {
        $lines = collect();

        foreach (self::SLOT_KEYS as $slot) {
            if ($this->state[$slot] !== null) {
                $lines->put($slot, $this->state[$slot]);
            }
        }

        foreach ($this->state['lines'] as $key => $line) {
            $lines->put($key, $line);
        }

        return $lines;
    }

    public function isEmpty(): bool
    {
        return $this->allLines()->isEmpty();
    }

    public function hasPackage(): bool
    {
        return $this->state['package'] !== null;
    }

    public function hasContainer(): bool
    {
        return $this->state['container'] !== null;
    }

    public function hasUrn(): bool
    {
        return $this->state['urn'] !== null;
    }

    public function hasUrnVault(): bool
    {
        return $this->state['urn_vault'] !== null;
    }

    public function itemCount(): int
    {
        return (int) $this->allLines()->sum('quantity');
    }

    public function subtotalCents(): int
    {
        return (int) $this->allLines()->sum(fn (array $line) => $this->lineTotalCents($line));
    }

    /**
     * A line's total: an optional one-time base fee plus unit price x the
     * quantity beyond what the package includes, less any package allowance.
     * An item the package includes has its base fee covered too.
     *
     * @param  CartLine  $line
     */
    public function lineTotalCents(array $line): int
    {
        $includedQuantity = $line['included_quantity'] ?? 0;
        $baseCents = $includedQuantity > 0 ? 0 : $this->basePriceCents($line);

        return max(0, $baseCents + $line['unit_price_cents'] * $this->chargedQuantity($line) - ($line['allowance_cents'] ?? 0) - $this->includedOptionCreditCents($line));
    }

    /**
     * What a line would cost at its regular price, before the package covers
     * any of it.
     *
     * @param  CartLine  $line
     */
    public function lineRegularTotalCents(array $line): int
    {
        return $this->basePriceCents($line) + $line['unit_price_cents'] * $line['quantity'];
    }

    /**
     * A line's one-time base fee. When the package covers only the base fee,
     * the item is priced as if it had none, so no discount is shown for it.
     *
     * @param  CartLine  $line
     */
    public function basePriceCents(array $line): int
    {
        return $this->includesBaseFee($line['product_id']) ? 0 : ($line['base_price_cents'] ?? 0);
    }

    /**
     * How much of a line the package covers (included units and allowances).
     *
     * @param  CartLine  $line
     */
    public function lineDiscountCents(array $line): int
    {
        return $this->lineRegularTotalCents($line) - $this->lineTotalCents($line);
    }

    /**
     * @param  CartLine  $line
     */
    private function chargedQuantity(array $line): int
    {
        return max(0, $line['quantity'] - ($line['included_quantity'] ?? 0));
    }

    /**
     * The taxable part of a line at its regular price. Units the package
     * includes and allowances it credits are still taxed here, on the item
     * actually provided, so a package's own taxable amount never covers them.
     *
     * @param  CartLine  $line
     */
    private function taxableLineCents(array $line): int
    {
        return ($line['taxable_base_cents'] ?? 0) + $this->taxableUnitCentsFor($line) * $line['quantity'];
    }

    /**
     * The portion of the subtotal made up of taxable lines. Lines added
     * before 'taxable_unit_cents' existed fall back to the 'is_taxable' flag
     * (itself defaulting to taxable, the safer default for an in-flight
     * session cart). Includes any unused keepsake allowance that's taxable.
     */
    public function taxableSubtotalCents(): int
    {
        return (int) $this->allLines()->sum(fn (array $line) => $this->taxableLineCents($line))
            + $this->taxableUnusedKeepsakeAllowanceCents();
    }

    /**
     * Keepsake allowance the family doesn't use is merchandise still owed to
     * them, so when the allowance product is taxable, what's left is taxed.
     * (The keepsakes it does cover are taxed on their own lines.)
     */
    public function taxableUnusedKeepsakeAllowanceCents(): int
    {
        $taxableAllowanceCents = (int) collect($this->lines())
            ->filter(fn (array $line) => $line['is_taxable'] ?? false)
            ->sum(fn (array $line) => ($line['keepsake_allowance_cents'] ?? 0) * $line['quantity']);

        return min($this->keepsakeAllowanceRemainingCents(), $taxableAllowanceCents);
    }

    /**
     * A product with an explicit taxable amount only taxes that portion (plus
     * any variant upcharge); otherwise the is_taxable flag covers the full price.
     * $priceCents overrides the product's own price (a package's city price).
     * A keepsake allowance is never taxed on its own price; a taxable one is
     * taxed on the amount left unused instead.
     */
    private function taxableUnitCentsForProduct(Product $product, int $variantDeltaCents, ?int $priceCents = null): int
    {
        $unitPriceCents = ($priceCents ?? $product->price_cents) + $variantDeltaCents;

        return max(0, match (true) {
            ! $product->is_taxable, $product->category === ProductCategory::KeepsakeAllowance => 0,
            $product->taxable_amount_cents !== null => min($product->taxable_amount_cents + $variantDeltaCents, $unitPriceCents),
            default => $unitPriceCents,
        });
    }

    /**
     * @param  CartLine  $line
     */
    public function taxableUnitCentsFor(array $line): int
    {
        if (isset($line['taxable_unit_cents'])) {
            return $line['taxable_unit_cents'];
        }

        return ($line['is_taxable'] ?? true) ? $line['unit_price_cents'] : 0;
    }

    public function taxCents(): int
    {
        return (int) round($this->taxableSubtotalCents() * $this->store->tax_rate_bps / 10000);
    }

    /**
     * An optional surcharge to help the store cover its card processing
     * costs. Calculated on subtotal + tax (the amount that actually runs
     * through the card) and, unlike tax, is not itself taxable.
     */
    public function processingFeeCents(): int
    {
        if (! $this->store->processing_fee_enabled) {
            return 0;
        }

        return (int) round(($this->subtotalCents() + $this->taxCents()) * $this->store->processing_fee_bps / 10000);
    }

    public function totalCents(): int
    {
        return $this->subtotalCents() + $this->taxCents() + $this->processingFeeCents();
    }

    public function clear(): void
    {
        $this->state = $this->emptyState();
        $this->persist();
    }

    /**
     * @return array<string, mixed>
     */
    public function state(): array
    {
        return $this->state;
    }
}
