<?php

use App\Enums\ProductCategory;
use App\Enums\ProductSortMode;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
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

    /** Additional price per unit (blank = normal pricing); the main price then becomes a one-time base fee. */
    #[Validate('nullable|numeric|min:0')]
    public string $formPerUnitPrice = '';

    #[Validate('nullable|string|max:50')]
    public string $formPerUnitLabel = '';

    #[Validate('boolean')]
    public bool $formIsActive = true;

    #[Validate('boolean')]
    public bool $formAllowMultipleQuantity = false;

    #[Validate('boolean')]
    public bool $formAvailableForImmediate = true;

    #[Validate('boolean')]
    public bool $formAvailableForPreNeed = true;

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
        $this->reset(['editingProductId', 'formName', 'formDescription', 'formIncludedItems', 'formPrice', 'formTaxableAmount', 'formPerUnitPrice', 'formPerUnitLabel', 'formImage', 'existingImagePath', 'newVariantName', 'newVariantPrice']);
        $this->formCategory = $category ?? ProductCategory::Package->value;
        $this->formIsTaxable = true;
        $this->formIsRequired = false;
        $this->formIsActive = true;
        $this->formAllowMultipleQuantity = false;
        $this->formAvailableForImmediate = true;
        $this->formAvailableForPreNeed = true;
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
        $this->formIsActive = $product->is_active;
        $this->formAllowMultipleQuantity = $product->allow_multiple_quantity;
        $this->formAvailableForImmediate = $product->available_for_immediate;
        $this->formAvailableForPreNeed = $product->available_for_pre_need;
        $this->formImage = null;
        $this->existingImagePath = $product->image_path;
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
            ], attributes: ['formTaxableAmount' => 'taxable amount']);
        }

        $isSlotCategory = in_array($validated['formCategory'], [
            ProductCategory::Package->value,
            ProductCategory::Container->value,
            ProductCategory::Urn->value,
        ], true);
        $hasPerUnitPrice = ! $isSlotCategory && trim($this->formPerUnitPrice) !== '';

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
            'is_required' => ! $isSlotCategory && $this->formIsRequired,
            'per_unit_price_cents' => $hasPerUnitPrice ? (int) round(((float) $this->formPerUnitPrice) * 100) : null,
            'per_unit_label' => $hasPerUnitPrice ? ($this->formPerUnitLabel ?: null) : null,
            'is_active' => $this->formIsActive,
            'allow_multiple_quantity' => $this->formAllowMultipleQuantity,
            'available_for_immediate' => $this->formAvailableForImmediate,
            'available_for_pre_need' => $this->formAvailableForPreNeed,
        ];

        if ($this->formImage) {
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
            Product::create($data);
        }

        Flux::modal('product-form')->close();
        Flux::toast(variant: 'success', text: __('Product saved.'));
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

        ProductVariant::create([
            'product_id' => $this->editingProductId,
            'name' => $this->newVariantName,
            'price_delta_cents' => (int) round(((float) $this->newVariantPrice) * 100),
        ]);

        $this->newVariantName = '';
        $this->newVariantPrice = '0.00';
    }

    public function removeVariant(int $variantId): void
    {
        ProductVariant::whereKey($variantId)->where('product_id', $this->editingProductId)->delete();
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
                <flux:heading size="lg">{{ $category->label() }}s</flux:heading>
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
                            <x-product-image :src="$product->imageUrl()" :category="$product->category->value" class="h-16 w-full" />
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
                                    <p class="mt-1 text-[11px] text-zinc-400">{{ __(':count variants', ['count' => $product->variants->count()]) }}</p>
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
                <flux:label>{{ trim($formPerUnitPrice) !== '' && ! in_array($formCategory, ['package', 'container', 'urn'], true) ? __('Base price (USD, charged once)') : __('Price (USD)') }}</flux:label>
                <flux:input type="number" step="0.01" min="0" wire:model.live.debounce.400ms="formPrice" />
                <flux:error name="formPrice" />
            </flux:field>

            @unless (in_array($formCategory, ['package', 'container', 'urn'], true))
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

            @if ($formCategory === ProductCategory::Package->value)
                <flux:field>
                    <flux:label>{{ __('Taxable amount (USD)') }}</flux:label>
                    <flux:description>{{ __('The part of the package price that sales tax applies to, after any package discount. Enter 0 if none of it is taxable.') }}</flux:description>
                    <flux:input type="number" step="0.01" min="0" wire:model="formTaxableAmount" />
                    <flux:error name="formTaxableAmount" />
                </flux:field>
            @endif

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

            <div class="grid grid-cols-2 gap-3">
                <flux:checkbox wire:model="formIsActive" :label="__('Active (visible in store)')" />
                @unless ($formCategory === ProductCategory::Package->value)
                    <flux:checkbox wire:model="formIsTaxable" :label="__('Taxable')" />
                @endunless
                @unless (in_array($formCategory, ['package', 'container', 'urn'], true))
                    <flux:checkbox wire:model="formIsRequired" :label="__('Pre-selected (customer cannot remove)')" />
                @endunless
                <flux:checkbox wire:model="formAllowMultipleQuantity" :label="__('Allow quantity > 1')" />
                <flux:checkbox wire:model="formAvailableForImmediate" :label="__('Available for immediate need')" />
                <flux:checkbox wire:model="formAvailableForPreNeed" :label="__('Available for pre-need planning')" />
            </div>

            @if ($editingProductId)
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
