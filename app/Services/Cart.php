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
        ];
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

        if ($slot) {
            $this->state[$slot] = null;
            $this->persist();
        }
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
        if ($quantity <= 0) {
            unset($this->state['lines'][$key]);
        } elseif (isset($this->state['lines'][$key])) {
            $this->state['lines'][$key]['quantity'] = $quantity;
        }

        $this->persist();
    }

    public function removeLine(string $key): void
    {
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
        if (in_array($key, self::SLOT_KEYS, true)) {
            $this->state[$key] = null;
            $this->persist();

            return;
        }

        $this->removeLine($key);
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
        return [
            'product_id' => $product->id,
            'variant_id' => $variant?->id,
            'category' => $product->category->value,
            'name' => $product->name,
            'variant_name' => $variant?->name,
            'image_path' => $product->image_path,
            'is_taxable' => $product->is_taxable,
            'unit_price_cents' => $product->price_cents + ($variant?->price_delta_cents ?? 0),
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

    public function itemCount(): int
    {
        return (int) $this->allLines()->sum('quantity');
    }

    public function subtotalCents(): int
    {
        return (int) $this->allLines()->sum(fn (array $line) => $line['unit_price_cents'] * $line['quantity']);
    }

    /**
     * The portion of the subtotal made up of taxable lines. Lines added
     * before this field existed have no 'is_taxable' key — treated as
     * taxable, the safer default for an in-flight session cart.
     */
    public function taxableSubtotalCents(): int
    {
        return (int) $this->allLines()
            ->filter(fn (array $line) => $line['is_taxable'] ?? true)
            ->sum(fn (array $line) => $line['unit_price_cents'] * $line['quantity']);
    }

    public function taxCents(): int
    {
        return (int) round($this->taxableSubtotalCents() * $this->store->tax_rate_bps / 10000);
    }

    public function totalCents(): int
    {
        return $this->subtotalCents() + $this->taxCents();
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
