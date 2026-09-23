<?php

namespace App\Services;

use App\Enums\OrderTiming;
use App\Enums\ProductCategory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use Illuminate\Support\Collection;

/**
 * A session-backed shopping cart for one store's storefront.
 *
 * The package, container, and urn are single-select "slots" (choosing a new
 * one replaces the old one), matching how cremation packages are actually
 * sold. Keepsakes and add-ons are repeatable line items with quantities.
 */
class Cart
{
    /** The fixed keys allLines() uses for the single-select slots. */
    private const SLOT_KEYS = ['package', 'container', 'urn'];

    /** @var array<string, mixed> */
    private array $state;

    public function __construct(private readonly Store $store)
    {
        $this->state = session()->get($this->sessionKey(), $this->emptyState());
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

            $product = Product::find($line['product_id']);

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
            'package' => null,
            'container' => null,
            'urn' => null,
            'lines' => [], // repeatable keepsakes / add-ons / services
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
        session()->put($this->sessionKey(), $this->state);
    }

    public function timing(): ?OrderTiming
    {
        return $this->state['timing'] ? OrderTiming::from($this->state['timing']) : null;
    }

    public function setTiming(OrderTiming $timing): void
    {
        $this->state['timing'] = $timing->value;
        $this->persist();
    }

    private function slotKeyFor(ProductCategory $category): ?string
    {
        return match ($category) {
            ProductCategory::Package => 'package',
            ProductCategory::Container => 'container',
            ProductCategory::Urn => 'urn',
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
        $this->persist();
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
            default => false,
        };
    }

    public function addLine(Product $product, ?ProductVariant $variant = null, int $quantity = 1): void
    {
        $key = $this->lineKey($product, $variant);

        if (isset($this->state['lines'][$key])) {
            $this->state['lines'][$key]['quantity'] += $quantity;
        } else {
            $this->state['lines'][$key] = $this->lineFor($product, $variant, $quantity);
        }

        $this->persist();
    }

    public function updateLineQuantity(string $key, int $quantity): void
    {
        if ($this->isRequiredLine($key)) {
            // A required item can't be removed, so its quantity never drops below 1.
            $quantity = max(1, $quantity);
        }

        if ($quantity <= 0) {
            unset($this->state['lines'][$key]);
        } elseif (isset($this->state['lines'][$key])) {
            $this->state['lines'][$key]['quantity'] = $quantity;
        }

        $this->persist();
    }

    public function removeLine(string $key): void
    {
        if ($this->isRequiredLine($key)) {
            return;
        }

        unset($this->state['lines'][$key]);
        $this->persist();
    }

    /**
     * Remove any line by its allLines() key, whether it's a single-select
     * slot ("package"/"container"/"urn") or a repeatable line
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
     * Add every active required product for the current timing that isn't
     * already in the cart, and refresh the flag on lines already present.
     * Called by the wizard so required items are pre-selected for the customer.
     */
    public function ensureRequiredLines(): void
    {
        $query = $this->store->products()->active()->where('is_required', true)
            ->whereNotIn('category', [
                ProductCategory::Package->value,
                ProductCategory::Container->value,
                ProductCategory::Urn->value,
            ]);

        if ($this->state['timing']) {
            $query->availableForTiming($this->state['timing']);
        }

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
        return $product->id.'-'.($variant?->id ?? '0');
    }

    /**
     * @return array<string, mixed>
     */
    private function lineFor(Product $product, ?ProductVariant $variant, int $quantity): array
    {
        $variantDeltaCents = $variant?->price_delta_cents ?? 0;

        if ($product->hasPerUnitPricing()) {
            $baseCents = $product->price_cents + $variantDeltaCents;
            $unitCents = $product->per_unit_price_cents;

            return [
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

        $unitPriceCents = $product->price_cents + $variantDeltaCents;

        return [
            'product_id' => $product->id,
            'variant_id' => $variant?->id,
            'category' => $product->category->value,
            'name' => $product->name,
            'variant_name' => $variant?->name,
            'image_path' => $product->image_path,
            'is_taxable' => $product->is_taxable,
            'is_required' => $product->is_required,
            'taxable_unit_cents' => $this->taxableUnitCentsForProduct($product, $variantDeltaCents),
            'unit_price_cents' => $unitPriceCents,
            'quantity' => $quantity,
        ];
    }

    /**
     * Every line in the cart (slots + repeatable lines), keyed for the UI.
     *
     * @return Collection<string, array<string, mixed>>
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

    public function itemCount(): int
    {
        return (int) $this->allLines()->sum('quantity');
    }

    public function subtotalCents(): int
    {
        return (int) $this->allLines()->sum(fn (array $line) => $this->lineTotalCents($line));
    }

    /**
     * A line's total: an optional one-time base fee plus unit price x quantity.
     *
     * @param  array<string, mixed>  $line
     */
    public function lineTotalCents(array $line): int
    {
        return ($line['base_price_cents'] ?? 0) + $line['unit_price_cents'] * $line['quantity'];
    }

    /**
     * The portion of the subtotal made up of taxable lines. Lines added
     * before 'taxable_unit_cents' existed fall back to the 'is_taxable' flag
     * (itself defaulting to taxable, the safer default for an in-flight
     * session cart).
     */
    public function taxableSubtotalCents(): int
    {
        return (int) $this->allLines()->sum(
            fn (array $line) => ($line['taxable_base_cents'] ?? 0) + $this->taxableUnitCentsFor($line) * $line['quantity']
        );
    }

    /**
     * A product with an explicit taxable amount only taxes that portion (plus
     * any variant upcharge); otherwise the is_taxable flag covers the full price.
     */
    private function taxableUnitCentsForProduct(Product $product, int $variantDeltaCents): int
    {
        $unitPriceCents = $product->price_cents + $variantDeltaCents;

        return max(0, match (true) {
            ! $product->is_taxable => 0,
            $product->taxable_amount_cents !== null => min($product->taxable_amount_cents + $variantDeltaCents, $unitPriceCents),
            default => $unitPriceCents,
        });
    }

    /**
     * @param  array<string, mixed>  $line
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
