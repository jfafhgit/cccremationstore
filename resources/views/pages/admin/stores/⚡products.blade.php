<?php

use App\Enums\ProductCategory;
use App\Enums\ProductSortMode;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StoreLocation;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    public Store $currentStore;

    public ?int $editingProductId = null;

    #[Validate('required|string|max:255')]
    public string $formName = '';

    #[Validate('required|string')]
    public string $formCategory = '';

    #[Validate('nullable|string|max:2000')]
    public string $formDescription = '';

    /** One bullet point per line; stored as an array on the product. */
    #[Validate('nullable|string|max:2000')]
    public string $formIncludedItems = '';

    #[Validate('required|numeric|min:0')]
    public string $formPrice = '0.00';

    #[Validate('boolean')]
    public bool $formIsTaxable = true;

    /** Dollar portion of a package's price that is taxable; unused for other categories. */
    public string $formTaxableAmount = '';

    #[Validate('boolean')]
    public bool $formIsRequired = false;

    /**
     * A package's included add-ons / services / keepsakes, keyed by product ID.
     *
     * @var array<int, array{included: bool, quantity: int|string}>
     */
    public array $formIncludedProducts = [];

    /** A package's allowance toward the customer's container (blank = none). */
    public string $formContainerAllowance = '';

    /** A package's allowance toward the customer's urn (blank = none). */
    public string $formUrnAllowance = '';

    #[Validate('boolean')]
    public bool $formHideOptionsBelowAllowance = false;

    /** An add-on's allowance toward any keepsakes, e.g. a "Legacy Touch Allowance" (blank = none). */
    public string $formKeepsakeAllowance = '';

    /**
     * A package's price per city in dollars, keyed by StoreLocation ID
     * (blank = not offered there). Only used with location-based pricing.
     *
     * @var array<int, string>
     */
    public array $formLocationPrices = [];

    /** Additional price per unit (blank = normal pricing); the main price then becomes a one-time base fee. */
    #[Validate('nullable|numeric|min:0')]
    public string $formPerUnitPrice = '';

    #[Validate('nullable|string|max:50')]
    public string $formPerUnitLabel = '';

    #[Validate('boolean')]
    public bool $formIsActive = true;

    #[Validate('boolean')]
    public bool $formAllowMultipleQuantity = false;

    /** A newly-chosen upload waiting to replace the product's image. */
    #[Validate('nullable|image|max:5120')]
    public $formImage = null;

    /**
     * The product's image_path as it will be saved: starts equal to the
     * existing DB value when editing, set to null by removeExistingImage()
     * to mean "explicitly cleared" (as opposed to "untouched, still has
     * whatever's in the database").
     */
    public ?string $existingImagePath = null;

    public string $newVariantName = '';

    public string $newVariantPrice = '0.00';

    public string $newVariantDescription = '';

    /** Whether the option in the option form is pre-selected for customers. */
    public bool $newVariantIsDefault = false;

    /** The option being edited in the option form, or null when adding a new one. */
    public ?int $editingVariantId = null;

    /** @var array<string, string> the selected ProductSortMode value per category, for the "Sort by" selects. */
    public array $sortModeChoice = [];

    public function mount(Store $store): void
    {
        $this->currentStore = $store;
        $this->formCategory = ProductCategory::Package->value;

        foreach (ProductCategory::cases() as $category) {
            $this->sortModeChoice[$category->value] = $store->productSortMode($category)->value;
        }
    }

    /**
     * Fires when a "Sort by" select changes; $key is the category value
     * (the part of "sortModeChoice.<category>" after the property name).
     */
    public function updatedSortModeChoice(string $value, string $key): void
    {
        $this->setSortMode($key, $value);
    }

    /**
     * @return Collection<string, Collection<int, Product>>
     */
    public function products(): Collection
    {
        return collect(ProductCategory::cases())->mapWithKeys(function (ProductCategory $category) {
            return [
                $category->value => $this->currentStore->products()
                    ->with('variants')
                    ->ofCategory($category)
                    ->orderedFor($this->currentStore->productSortMode($category))
                    ->get(),
            ];
        });
    }

    /**
     * Switch how one category is ordered. Moving into "custom" snapshots
     * whatever order was just being shown into sort_order, so drag-and-drop
     * starts from a sensible arrangement instead of a stale leftover order.
     */
    public function setSortMode(string $categoryValue, string $mode): void
    {
        $category = ProductCategory::from($categoryValue);
        $newMode = ProductSortMode::from($mode);
        $previousMode = $this->currentStore->productSortMode($category);

        if ($newMode === ProductSortMode::Custom && $previousMode !== ProductSortMode::Custom) {
            $this->currentStore->products()
                ->ofCategory($category)
                ->orderedFor($previousMode)
                ->pluck('id')
                ->each(fn (int $id, int $index) => Product::whereKey($id)->update(['sort_order' => $index]));
        }

        $settings = $this->currentStore->settings ?? [];
        $settings['product_sort'][$category->value] = $newMode->value;
        $this->currentStore->update(['settings' => $settings]);
    }

    /**
     * Drag-and-drop reorder within one category (custom mode only): move
     * the dragged product to just before the one it was dropped on, then
     * renumber the whole category sequentially.
     */
    public function reorderProduct(string $categoryValue, int $draggedId, int $targetId): void
    {
        if ($draggedId === $targetId) {
            return;
        }

        $category = ProductCategory::from($categoryValue);

        $dragged = $this->currentStore->products()->ofCategory($category)->whereKey($draggedId)->exists();

        if (! $dragged) {
            return;
        }

        $ids = $this->currentStore->products()
            ->ofCategory($category)
            ->orderBy('sort_order')
            ->pluck('id')
            ->reject(fn (int $id) => $id === $draggedId)
            ->values();

        $targetIndex = $ids->search($targetId);

        if ($targetIndex === false) {
            return;
        }

        $ids->splice($targetIndex, 0, [$draggedId]);

        $ids->each(fn (int $id, int $index) => Product::whereKey($id)->update(['sort_order' => $index]));
    }

    public function newProduct(?string $category = null): void
    {
        $this->reset(['editingProductId', 'formName', 'formDescription', 'formIncludedItems', 'formPrice', 'formTaxableAmount', 'formPerUnitPrice', 'formPerUnitLabel', 'formImage', 'existingImagePath', 'newVariantName', 'newVariantPrice', 'newVariantDescription', 'newVariantIsDefault', 'editingVariantId', 'formIncludedProducts', 'formContainerAllowance', 'formUrnAllowance', 'formHideOptionsBelowAllowance', 'formKeepsakeAllowance', 'formLocationPrices']);
        $this->formCategory = $category ?? ProductCategory::Package->value;
        $this->formIsTaxable = true;
        $this->formIsRequired = false;
        $this->formIsActive = true;
        $this->formAllowMultipleQuantity = false;
        $this->resetValidation();

        Flux::modal('product-form')->show();
    }

    public function editProduct(int $productId): void
    {
        $product = $this->currentStore->products()->findOrFail($productId);

        $this->editingProductId = $product->id;
        $this->formName = $product->name;
        $this->formCategory = $product->category->value;
        $this->formDescription = $product->description ?? '';
        $this->formIncludedItems = implode("\n", $product->included_items ?? []);
        $this->formPrice = number_format($product->price_cents / 100, 2, '.', '');
        $this->formIsTaxable = $product->is_taxable;
        $this->formIsRequired = $product->is_required;
        $this->formPerUnitPrice = $product->per_unit_price_cents !== null ? number_format($product->per_unit_price_cents / 100, 2, '.', '') : '';
        $this->formPerUnitLabel = $product->per_unit_label ?? '';
        $this->formTaxableAmount = number_format(
            ($product->taxable_amount_cents ?? ($product->is_taxable ? $product->price_cents : 0)) / 100,
            2,
            '.',
            ''
        );
        $this->formIncludedProducts = $product->includedProducts
            ->mapWithKeys(fn (Product $included) => [$included->id => ['included' => true, 'quantity' => $included->pivot->included_quantity]])
            ->all();
        $this->formContainerAllowance = $product->container_allowance_cents !== null ? number_format($product->container_allowance_cents / 100, 2, '.', '') : '';
        $this->formUrnAllowance = $product->urn_allowance_cents !== null ? number_format($product->urn_allowance_cents / 100, 2, '.', '') : '';
        $this->formHideOptionsBelowAllowance = $product->hide_options_below_allowance;
        $this->formKeepsakeAllowance = $product->keepsake_allowance_cents !== null ? number_format($product->keepsake_allowance_cents / 100, 2, '.', '') : '';
        $this->formLocationPrices = $product->locationPrices
            ->mapWithKeys(fn (StoreLocation $location) => [$location->id => number_format($location->pivot->price_cents / 100, 2, '.', '')])
            ->all();
        $this->formIsActive = $product->is_active;
        $this->formAllowMultipleQuantity = $product->allow_multiple_quantity;
        $this->formImage = null;
        $this->existingImagePath = $product->image_path;
        $this->resetVariantForm();
        $this->resetValidation();

        Flux::modal('product-form')->show();
    }

    /**
     * Clear the currently-showing image preview so saveProduct() knows the
     * admin explicitly wants the image removed, not just left alone.
     */
    public function removeExistingImage(): void
    {
        $this->existingImagePath = null;
    }

    public function saveProduct(): void
    {
        $validated = $this->validate();

        $isPackage = $validated['formCategory'] === ProductCategory::Package->value;

        if ($isPackage) {
            $this->validate([
                'formTaxableAmount' => ['required', 'numeric', 'min:0', 'lte:formPrice'],
                'formContainerAllowance' => ['nullable', 'numeric', 'min:0'],
                'formUrnAllowance' => ['nullable', 'numeric', 'min:0'],
                'formIncludedProducts.*.quantity' => ['nullable', 'integer', 'min:1', 'max:999'],
                'formLocationPrices.*' => ['nullable', 'numeric', 'min:0'],
            ], attributes: [
                'formLocationPrices.*' => 'city price',
                'formTaxableAmount' => 'taxable amount',
                'formContainerAllowance' => 'container allowance',
                'formUrnAllowance' => 'urn allowance',
                'formIncludedProducts.*.quantity' => 'included quantity',
            ]);
        }

        $isAddon = $validated['formCategory'] === ProductCategory::Addon->value;

        if ($isAddon) {
            $this->validate(
                ['formKeepsakeAllowance' => ['nullable', 'numeric', 'min:0']],
                attributes: ['formKeepsakeAllowance' => 'keepsake allowance'],
            );
        }

        $isSlotCategory = in_array($validated['formCategory'], [
            ProductCategory::Package->value,
            ProductCategory::Container->value,
            ProductCategory::Urn->value,
            ProductCategory::UrnVault->value,
        ], true);
        $hasPerUnitPrice = ! $isSlotCategory && $validated['formCategory'] !== ProductCategory::Choice->value && trim($this->formPerUnitPrice) !== '';

        $taxableAmountCents = $isPackage ? (int) round(((float) $this->formTaxableAmount) * 100) : null;

        $product = $this->editingProductId
            ? $this->currentStore->products()->findOrFail($this->editingProductId)
            : null;

        $data = [
            'store_id' => $this->currentStore->id,
            'category' => ProductCategory::from($validated['formCategory']),
            'name' => $validated['formName'],
            'slug' => str($validated['formName'])->slug().'-'.str()->random(4),
            'description' => $validated['formDescription'] ?: null,
            'included_items' => collect(preg_split('/\R/', $validated['formIncludedItems'] ?? ''))
                ->map(fn (string $item) => trim($item))
                ->filter()
                ->values()
                ->all() ?: null,
            'price_cents' => (int) round(((float) $validated['formPrice']) * 100),
            'is_taxable' => $isPackage ? $taxableAmountCents > 0 : $this->formIsTaxable,
            'taxable_amount_cents' => $taxableAmountCents,
            'container_allowance_cents' => $isPackage ? $this->dollarsToCents($this->formContainerAllowance) : null,
            'urn_allowance_cents' => $isPackage ? $this->dollarsToCents($this->formUrnAllowance) : null,
            'hide_options_below_allowance' => $isPackage && $this->formHideOptionsBelowAllowance,
            'keepsake_allowance_cents' => $isAddon ? $this->dollarsToCents($this->formKeepsakeAllowance) : null,
            'is_required' => ! $isSlotCategory && $this->formIsRequired,
            'per_unit_price_cents' => $hasPerUnitPrice ? (int) round(((float) $this->formPerUnitPrice) * 100) : null,
            'per_unit_label' => $hasPerUnitPrice ? ($this->formPerUnitLabel ?: null) : null,
            'is_active' => $this->formIsActive,
            'allow_multiple_quantity' => $this->formAllowMultipleQuantity,
        ];

        if ($isPackage) {
            $this->formImage = null;
        } elseif ($this->formImage) {
            if ($product?->image_path) {
                Storage::disk('public')->delete($product->image_path);
            }
            $data['image_path'] = $this->formImage->store('products', 'public');
        } elseif ($product && $this->existingImagePath === null && $product->image_path) {
            Storage::disk('public')->delete($product->image_path);
            $data['image_path'] = null;
        }

        if ($product) {
            unset($data['slug']); // keep the original slug on edit
            $product->update($data);
        } else {
            $product = Product::create($data);
        }

        $product->includedProducts()->sync($isPackage ? $this->includedProductsToSync() : []);

        // Only sync city prices when the form showed them, so saving while
        // location pricing is off keeps them for when it's turned back on.
        if (! $isPackage) {
            $product->locationPrices()->sync([]);
        } elseif ($this->currentStore->location_pricing_enabled) {
            $product->locationPrices()->sync($this->locationPricesToSync());
        }

        Flux::modal('product-form')->close();
        Flux::toast(variant: 'success', text: __('Product saved.'));
    }

    /**
     * The products a package can include: this store's add-ons, services, and keepsakes.
     *
     * @return Collection<int, Product>
     */
    public function includableProducts(): Collection
    {
        return $this->currentStore->products()
            ->whereIn('category', [
                ProductCategory::Addon->value,
                ProductCategory::Keepsake->value,
            ])
            ->orderBy('category')
            ->orderBy('name')
            ->get();
    }

    /**
     * The checked included products as sync() input, limited to this
     * store's includable products.
     *
     * @return array<int, array{included_quantity: int}>
     */
    private function includedProductsToSync(): array
    {
        $includableIds = $this->includableProducts()->pluck('id');

        return collect($this->formIncludedProducts)
            ->filter(fn (array $selection, int $productId) => ($selection['included'] ?? false) && $includableIds->contains($productId))
            ->map(fn (array $selection) => ['included_quantity' => max(1, (int) ($selection['quantity'] ?? 1))])
            ->all();
    }

    /**
     * The filled-in city prices as sync() input, limited to this store's cities.
     *
     * @return array<int, array{price_cents: int}>
     */
    private function locationPricesToSync(): array
    {
        $locationIds = $this->currentStore->locations()->pluck('id');

        return collect($this->formLocationPrices)
            ->filter(fn (?string $price, int $locationId) => trim((string) $price) !== '' && $locationIds->contains($locationId))
            ->map(fn (string $price) => ['price_cents' => $this->dollarsToCents($price)])
            ->all();
    }

    private function dollarsToCents(string $dollars): ?int
    {
        return trim($dollars) === '' ? null : (int) round(((float) $dollars) * 100);
    }

    public function deleteProduct(int $productId): void
    {
        $product = $this->currentStore->products()->whereKey($productId)->first();

        if ($product?->image_path) {
            Storage::disk('public')->delete($product->image_path);
        }

        $product?->delete();

        Flux::toast(variant: 'success', text: __('Product removed.'));
    }

    public function toggleActive(int $productId): void
    {
        $product = $this->currentStore->products()->findOrFail($productId);
        $product->update(['is_active' => ! $product->is_active]);
    }

    public function addVariant(): void
    {
        if (! $this->editingProductId || trim($this->newVariantName) === '') {
            return;
        }

        $this->validate(
            ['newVariantDescription' => ['nullable', 'string', 'max:2000']],
            attributes: ['newVariantDescription' => 'option description'],
        );

        $attributes = [
            'name' => $this->newVariantName,
            'description' => trim($this->newVariantDescription) ?: null,
            'price_delta_cents' => (int) round(((float) $this->newVariantPrice) * 100),
        ];

        if ($this->newVariantIsDefault) {
            // Only one option can be pre-selected.
            ProductVariant::where('product_id', $this->editingProductId)->update(['is_default' => false]);
        }

        $attributes['is_default'] = $this->newVariantIsDefault;

        if ($this->editingVariantId) {
            ProductVariant::whereKey($this->editingVariantId)->where('product_id', $this->editingProductId)->update($attributes);
        } else {
            ProductVariant::create(['product_id' => $this->editingProductId, ...$attributes]);
        }

        $this->resetVariantForm();
    }

    /**
     * Load an existing option into the option form so it can be changed.
     */
    public function editVariant(int $variantId): void
    {
        $variant = ProductVariant::whereKey($variantId)->where('product_id', $this->editingProductId)->first();

        if (! $variant) {
            return;
        }

        $this->editingVariantId = $variant->id;
        $this->newVariantName = $variant->name;
        $this->newVariantDescription = $variant->description ?? '';
        $this->newVariantPrice = number_format($variant->price_delta_cents / 100, 2, '.', '');
        $this->newVariantIsDefault = $variant->is_default;
    }

    public function resetVariantForm(): void
    {
        $this->reset(['editingVariantId', 'newVariantName', 'newVariantPrice', 'newVariantDescription', 'newVariantIsDefault']);
        $this->resetValidation('newVariantDescription');
    }

    public function removeVariant(int $variantId): void
    {
        ProductVariant::whereKey($variantId)->where('product_id', $this->editingProductId)->delete();

        if ($this->editingVariantId === $variantId) {
            $this->resetVariantForm();
        }
    }

    public function editingVariants(): Collection
    {
        if (! $this->editingProductId) {
            return collect();
        }

        return ProductVariant::where('product_id', $this->editingProductId)->orderBy('sort_order')->get();
    }
}; ?>

<div>
    <flux:link :href="route('admin.stores.show', $currentStore)" wire:navigate class="text-sm text-zinc-500">&larr; {{ $currentStore->name }}</flux:link>

    <div class="mt-4 flex items-center justify-between">
        <flux:heading size="xl">{{ __('Products & packages') }}</flux:heading>
        <flux:button variant="primary" wire:click="newProduct">{{ __('New product') }}</flux:button>
    </div>

    @foreach (ProductCategory::cases() as $category)
        @php($categoryProducts = $this->products()->get($category->value, collect()))
        @php($isCustomSort = $currentStore->productSortMode($category) === ProductSortMode::Custom)
        <div class="mt-8">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <flux:heading size="lg">{{ $category->pluralLabel() }}</flux:heading>
                <div class="flex items-center gap-2">
                    <flux:select size="sm" wire:model.live="sortModeChoice.{{ $category->value }}" class="w-44">
                        @foreach (ProductSortMode::cases() as $mode)
                            <option value="{{ $mode->value }}">{{ __('Sort: :label', ['label' => $mode->label()]) }}</option>
                        @endforeach
                    </flux:select>
                    <flux:button size="sm" variant="ghost" wire:click="newProduct('{{ $category->value }}')">{{ __('Add') }}</flux:button>
                </div>
            </div>

            @if ($category === ProductCategory::Package)
                @php($activePackages = $categoryProducts->where('is_active', true)->count())
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    @if ($currentStore->isALaCarte())
                        {{ __('This store is à la carte: the first active package is applied to every order as the base package.') }}
                    @else
                        {{ __('Three packages is ideal, though there is no limit. This store currently shows :count.', ['count' => $activePackages]) }}
                    @endif
                </p>
            @endif

            @if ($isCustomSort)
                <p class="mt-1 text-xs text-zinc-400">{{ __('Drag a card to reorder — this is what customers see on the storefront.') }}</p>
            @endif

            @if ($categoryProducts->isEmpty())
                <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('Nothing here yet.') }}</p>
            @else
                <div class="mt-3 grid grid-cols-2 gap-2.5 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5" @if ($isCustomSort) x-data="{ dragId: null }" @endif>
                    @foreach ($categoryProducts as $product)
                        <div
                            wire:key="product-{{ $product->id }}"
                            @if ($isCustomSort)
                                draggable="true"
                                x-on:dragstart="dragId = {{ $product->id }}"
                                x-on:dragover.prevent
                                x-on:drop.prevent="dragId !== null && dragId !== {{ $product->id }} && $wire.reorderProduct('{{ $category->value }}', dragId, {{ $product->id }})"
                            @endif
                            @class([
                                'overflow-hidden rounded-lg border',
                                'cursor-move' => $isCustomSort,
                                'border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800' => $product->is_active,
                                'border-dashed border-zinc-200 bg-zinc-50 opacity-60 dark:border-zinc-700 dark:bg-zinc-800/40' => ! $product->is_active,
                            ])
                        >
                            @unless ($product->category === ProductCategory::Package)
                                <x-product-image :src="$product->imageUrl()" :category="$product->category->value" class="h-16 w-full" />
                            @endunless
                            <div class="p-2">
                                <div class="flex items-start justify-between gap-1">
                                    <p class="truncate text-xs font-medium text-zinc-800 dark:text-zinc-100">{{ $product->name }}</p>
                                    @if ($isCustomSort)
                                        <span class="shrink-0 text-zinc-300 dark:text-zinc-600" title="{{ __('Drag to reorder') }}">⠿</span>
                                    @endif
                                </div>
                                <div class="mt-0.5 flex flex-wrap items-center gap-1">
                                    <p class="text-xs font-semibold text-brand-700 dark:text-brand-300">{{ $product->priceLabel() }}</p>
                                    <flux:badge size="sm" :color="$product->is_active ? 'green' : 'zinc'">{{ $product->is_active ? __('Active') : __('Hidden') }}</flux:badge>
                                    @if ($product->is_required)
                                        <flux:badge size="sm" color="blue">{{ __('Pre-selected') }}</flux:badge>
                                    @endif
                                </div>
                                @if ($product->variants->isNotEmpty())
                                    <p class="mt-1 text-[11px] text-zinc-400">{{ $product->category === ProductCategory::Choice
                                        ? __(':count options (choose one)', ['count' => $product->variants->count()])
                                        : __(':count variants', ['count' => $product->variants->count()]) }}</p>
                                @endif
                                <div class="mt-2 flex flex-wrap gap-x-2 gap-y-1">
                                    <button type="button" class="text-xs text-zinc-500 underline hover:text-brand-700 dark:text-zinc-400" wire:click="editProduct({{ $product->id }})">{{ __('Edit') }}</button>
                                    <button type="button" class="text-xs text-zinc-500 underline hover:text-brand-700 dark:text-zinc-400" wire:click="toggleActive({{ $product->id }})">
                                        {{ $product->is_active ? __('Hide') : __('Unhide') }}
                                    </button>
                                    <button type="button" class="text-xs text-zinc-500 underline hover:text-red-600 dark:text-zinc-400" wire:click="deleteProduct({{ $product->id }})" wire:confirm="{{ __('Delete this product?') }}">
                                        {{ __('Delete') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endforeach

    <flux:modal name="product-form" class="w-full max-w-lg">
        <form wire:submit="saveProduct" class="space-y-4">
            <flux:heading size="lg">{{ $editingProductId ? __('Edit product') : __('New product') }}</flux:heading>

            <flux:field>
                <flux:label>{{ __('Name') }}</flux:label>
                <flux:input wire:model="formName" required />
                <flux:error name="formName" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Category') }}</flux:label>
                <flux:select wire:model="formCategory">
                    @foreach (ProductCategory::cases() as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </flux:select>
                <flux:error name="formCategory" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Description') }}</flux:label>
                <flux:textarea wire:model="formDescription" rows="3" />
                <flux:error name="formDescription" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('What\'s included') }}</flux:label>
                <flux:description>{{ __('One item per line. Shown as a bullet list on the storefront.') }}</flux:description>
                <flux:textarea wire:model="formIncludedItems" rows="5" placeholder="{{ __('Basic cremation container') }}" />
                <flux:error name="formIncludedItems" />
            </flux:field>

            <flux:field>
                <flux:label>{{ trim($formPerUnitPrice) !== '' && ! in_array($formCategory, ['package', 'container', 'urn', 'urn_vault', 'choice'], true) ? __('Base price (USD, charged once)') : __('Price (USD)') }}</flux:label>
                <flux:input type="number" step="0.01" min="0" wire:model.live.debounce.400ms="formPrice" />
                <flux:error name="formPrice" />
            </flux:field>

            @unless (in_array($formCategory, ['package', 'container', 'urn', 'urn_vault', 'choice'], true))
                <div class="grid grid-cols-2 gap-3">
                    <flux:field>
                        <flux:label>{{ __('Additional price per unit (USD)') }}</flux:label>
                        <flux:description>{{ __('Optional. E.g. base price 250.00 for the service, plus 15.00 for each copy.') }}</flux:description>
                        <flux:input type="number" step="0.01" min="0" wire:model.live.debounce.400ms="formPerUnitPrice" />
                        <flux:error name="formPerUnitPrice" />
                    </flux:field>
                    <flux:field>
                        <flux:label>{{ __('Unit name') }}</flux:label>
                        <flux:description>{{ __('E.g. copy, hour, page.') }}</flux:description>
                        <flux:input wire:model="formPerUnitLabel" placeholder="copy" />
                        <flux:error name="formPerUnitLabel" />
                    </flux:field>
                </div>
            @endunless

            @if ($formCategory === ProductCategory::Addon->value)
                <flux:field>
                    <flux:label>{{ __('Keepsake allowance (USD)') }}</flux:label>
                    <flux:description>{{ __('Makes this an allowance product, like a Legacy Touch Allowance: this amount is credited toward any keepsakes the family picks. It is not offered on the Add-ons step; include it in a package to give it with that package. Leave blank for a regular add-on.') }}</flux:description>
                    <flux:input type="number" step="0.01" min="0" wire:model="formKeepsakeAllowance" />
                    <flux:error name="formKeepsakeAllowance" />
                </flux:field>
            @endif

            @if ($formCategory === ProductCategory::Package->value)
                <flux:field>
                    <flux:label>{{ __('Taxable amount (USD)') }}</flux:label>
                    <flux:description>{{ __('The part of the package price that sales tax applies to, after any package discount. Enter 0 if none of it is taxable. Leave out container and urn allowances and included add-ons, services, and keepsakes; those are taxed on the item the family receives.') }}</flux:description>
                    <flux:input type="number" step="0.01" min="0" wire:model="formTaxableAmount" />
                    <flux:error name="formTaxableAmount" />
                </flux:field>

                @if ($currentStore->location_pricing_enabled)
                    <div class="space-y-3 border-t border-zinc-100 pt-4 dark:border-zinc-700">
                        <flux:heading size="sm" class="text-zinc-500">{{ __('Price by city') }}</flux:heading>
                        <p class="text-xs text-zinc-500">{{ __('Location-based pricing is on, so families pay the price for their city instead of the price above. Leave a city blank to not offer this package there.') }}</p>
                        @forelse ($currentStore->locations->groupBy(fn ($location) => $location->state->label())->sortKeys() as $stateName => $locations)
                            <div wire:key="price-state-{{ $stateName }}">
                                <p class="text-xs font-medium text-zinc-600 dark:text-zinc-300">{{ $stateName }}</p>
                                <div class="mt-1 grid grid-cols-2 gap-2">
                                    @foreach ($locations as $location)
                                        <flux:field wire:key="price-location-{{ $location->id }}">
                                            <flux:input size="sm" type="number" step="0.01" min="0" wire:model="formLocationPrices.{{ $location->id }}" :label="$location->city" placeholder="{{ __('Not offered') }}" />
                                            <flux:error name="formLocationPrices.{{ $location->id }}" />
                                        </flux:field>
                                    @endforeach
                                </div>
                            </div>
                        @empty
                            <p class="text-xs text-zinc-400">
                                {{ __('No cities yet.') }}
                                <flux:link :href="route('admin.stores.locations', $currentStore)" wire:navigate>{{ __('Add cities') }}</flux:link>
                            </p>
                        @endforelse
                    </div>
                @endif

                <div class="space-y-3 border-t border-zinc-100 pt-4 dark:border-zinc-700">
                    <flux:heading size="sm" class="text-zinc-500">{{ __('Container & urn allowances') }}</flux:heading>
                    <p class="text-xs text-zinc-500">{{ __('Credited toward the container or urn the family picks. To include a specific item, enter its price. Leave blank for no allowance.') }}</p>
                    <div class="grid grid-cols-2 gap-3">
                        <flux:field>
                            <flux:label>{{ __('Container allowance (USD)') }}</flux:label>
                            <flux:input type="number" step="0.01" min="0" wire:model="formContainerAllowance" />
                            <flux:error name="formContainerAllowance" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('Urn allowance (USD)') }}</flux:label>
                            <flux:input type="number" step="0.01" min="0" wire:model="formUrnAllowance" />
                            <flux:error name="formUrnAllowance" />
                        </flux:field>
                    </div>
                    <flux:checkbox wire:model="formHideOptionsBelowAllowance" :label="__('Hide containers and urns priced below the allowance')" />
                </div>

                <div class="space-y-2 border-t border-zinc-100 pt-4 dark:border-zinc-700">
                    <flux:heading size="sm" class="text-zinc-500">{{ __('Included add-ons, services & keepsakes') }}</flux:heading>
                    <p class="text-xs text-zinc-500">{{ __('Checked items are free with this package. The family can still add more at the regular price where quantity allows.') }}</p>
                    @forelse ($this->includableProducts() as $includable)
                        <div class="flex items-center justify-between gap-3" wire:key="includable-{{ $includable->id }}">
                            <flux:checkbox wire:model.live="formIncludedProducts.{{ $includable->id }}.included" :label="$includable->name.' ('.$includable->category->label().')'" />
                            @if ($formIncludedProducts[$includable->id]['included'] ?? false)
                                <div class="flex items-center gap-2">
                                    <flux:input size="sm" type="number" min="1" step="1" wire:model="formIncludedProducts.{{ $includable->id }}.quantity" placeholder="1" class="w-20" :aria-label="__('Included quantity')" />
                                    <span class="text-xs text-zinc-500">{{ $includable->per_unit_label ?: __('qty') }}</span>
                                </div>
                            @endif
                        </div>
                        <flux:error name="formIncludedProducts.{{ $includable->id }}.quantity" />
                    @empty
                        <p class="text-xs text-zinc-400">{{ __('Add add-ons, services, or keepsakes first to include them here.') }}</p>
                    @endforelse
                </div>
            @endif

            @unless ($formCategory === ProductCategory::Package->value)
            <flux:field>
                <flux:label>{{ __('Image') }}</flux:label>
                <div class="flex items-center gap-3">
                    @if ($formImage)
                        <img src="{{ $formImage->temporaryUrl() }}" alt="" class="size-16 rounded-lg object-cover">
                    @elseif ($existingImagePath)
                        <img src="{{ \App\Models\Product::imageUrlFor($existingImagePath) }}" alt="" class="size-16 rounded-lg object-cover">
                    @endif
                    <div class="flex-1">
                        <input type="file" wire:model="formImage" accept="image/*" class="block w-full text-sm text-zinc-600 file:mr-3 file:rounded-lg file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-zinc-800 hover:file:bg-zinc-200 hover:file:text-zinc-900 dark:text-zinc-300 dark:file:bg-zinc-700 dark:file:text-zinc-100 dark:hover:file:bg-zinc-600 dark:hover:file:text-white" />
                        <span wire:loading wire:target="formImage" class="text-xs text-zinc-400">{{ __('Uploading…') }}</span>
                        @if ($existingImagePath && ! $formImage)
                            <button type="button" wire:click="removeExistingImage" class="mt-1 block text-xs text-zinc-400 underline hover:text-red-600">{{ __('Remove image') }}</button>
                        @endif
                    </div>
                </div>
                <flux:error name="formImage" />
            </flux:field>
            @endunless

            <div class="grid grid-cols-2 gap-3">
                <flux:checkbox wire:model="formIsActive" :label="__('Active (visible in store)')" />
                @unless ($formCategory === ProductCategory::Package->value)
                    <flux:checkbox wire:model="formIsTaxable" :label="__('Taxable')" />
                @endunless
                @unless (in_array($formCategory, ['package', 'container', 'urn', 'urn_vault'], true))
                    <flux:checkbox wire:model="formIsRequired" :label="$formCategory === ProductCategory::Choice->value ? __('Required (customer must choose an option)') : __('Pre-selected (customer cannot remove)')" />
                @endunless
                @unless ($formCategory === ProductCategory::Choice->value)
                    <flux:checkbox wire:model="formAllowMultipleQuantity" :label="__('Allow quantity > 1')" />
                @endunless
            </div>

            @if ($formCategory === ProductCategory::Choice->value)
                <div class="border-t border-zinc-100 pt-4 dark:border-zinc-700">
                    <flux:heading size="sm" class="text-zinc-500">{{ __('Options (customer chooses one)') }}</flux:heading>
                    <p class="mt-1 text-xs text-zinc-500">{{ __('E.g. pick up, ship, or courier delivery. Each option\'s price is added to the price above, so set that to 0 to price each option on its own. Shown at the bottom of the Add-ons & Services step, once it has at least one option.') }}</p>
                    @if ($editingProductId)
                        <ul class="mt-2 space-y-1">
                            @foreach ($this->editingVariants() as $variant)
                                <li @class(['flex items-start justify-between gap-3 text-sm', 'rounded bg-zinc-50 dark:bg-zinc-700/40' => $editingVariantId === $variant->id]) wire:key="option-{{ $variant->id }}">
                                    <div class="min-w-0">
                                        <span>{{ $variant->name }} <span class="text-zinc-500">(${{ number_format(((float) $formPrice * 100 + $variant->price_delta_cents) / 100, 2) }})</span></span>
                                        @if ($variant->is_default)
                                            <flux:badge size="sm" color="green" class="ml-1">{{ __('Pre-selected') }}</flux:badge>
                                        @endif
                                        @if ($variant->description)
                                            <p class="truncate text-xs text-zinc-400">{{ $variant->description }}</p>
                                        @endif
                                    </div>
                                    <div class="flex shrink-0 gap-2">
                                        <button type="button" class="text-xs text-zinc-400 underline hover:text-brand-700" wire:click="editVariant({{ $variant->id }})">{{ __('Edit') }}</button>
                                        <button type="button" class="text-xs text-zinc-400 underline hover:text-red-600" wire:click="removeVariant({{ $variant->id }})">{{ __('Remove') }}</button>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                        <div class="mt-2 space-y-2">
                            <div class="flex gap-2">
                                <flux:input size="sm" wire:model="newVariantName" placeholder="{{ __('Option name') }}" />
                                <flux:input size="sm" type="number" step="0.01" wire:model="newVariantPrice" placeholder="{{ __('Price') }}" class="w-28" :aria-label="__('Option price')" />
                            </div>
                            <flux:textarea size="sm" rows="2" wire:model="newVariantDescription" placeholder="{{ __('Description (optional), shown when the customer clicks Show detail') }}" :aria-label="__('Option description')" />
                            <flux:error name="newVariantDescription" />
                            <flux:checkbox wire:model="newVariantIsDefault" :label="__('Pre-select this option for customers')" :description="__('Leave every option unchecked to make customers choose one themselves.')" />
                            <div class="flex justify-end gap-2">
                                @if ($editingVariantId)
                                    <flux:button size="sm" type="button" variant="ghost" wire:click="resetVariantForm">{{ __('Cancel') }}</flux:button>
                                @endif
                                <flux:button size="sm" type="button" wire:click="addVariant">{{ $editingVariantId ? __('Save option') : __('Add option') }}</flux:button>
                            </div>
                        </div>
                    @else
                        <p class="mt-2 text-xs text-zinc-400">{{ __('Save this product first, then edit it to add options.') }}</p>
                    @endif
                </div>
            @elseif ($editingProductId)
                <div class="border-t border-zinc-100 pt-4 dark:border-zinc-700">
                    <flux:heading size="sm" class="text-zinc-500">{{ __('Variants (e.g. size, finish)') }}</flux:heading>
                    <ul class="mt-2 space-y-1">
                        @foreach ($this->editingVariants() as $variant)
                            <li class="flex items-center justify-between text-sm" wire:key="variant-{{ $variant->id }}">
                                <span>{{ $variant->name }} @if ($variant->price_delta_cents) ({{ $variant->price_delta_cents >= 0 ? '+' : '' }}${{ number_format($variant->price_delta_cents / 100, 2) }}) @endif</span>
                                <button type="button" class="text-xs text-zinc-400 underline hover:text-red-600" wire:click="removeVariant({{ $variant->id }})">{{ __('Remove') }}</button>
                            </li>
                        @endforeach
                    </ul>
                    <div class="mt-2 flex gap-2">
                        <flux:input size="sm" wire:model="newVariantName" placeholder="{{ __('Variant name') }}" />
                        <flux:input size="sm" type="number" step="0.01" wire:model="newVariantPrice" placeholder="+/- price" class="w-28" />
                        <flux:button size="sm" type="button" variant="ghost" wire:click="addVariant">{{ __('Add') }}</flux:button>
                    </div>
                </div>
            @endif

            <div class="flex justify-end gap-2 pt-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
