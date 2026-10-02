<?php

use App\Enums\UsState;
use App\Models\Store;
use App\Models\StoreLocation;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Component;

new class extends Component
{
    public Store $currentStore;

    public bool $locationPricingEnabled = false;

    public string $newState = '';

    public string $newCity = '';

    public function mount(Store $store): void
    {
        $this->currentStore = $store;
        $this->locationPricingEnabled = $store->location_pricing_enabled;
    }

    public function updatedLocationPricingEnabled(bool $enabled): void
    {
        $this->currentStore->update(['location_pricing_enabled' => $enabled]);

        Flux::toast(variant: 'success', text: $enabled
            ? __('Location-based pricing turned on.')
            : __('Location-based pricing turned off.'));
    }

    /**
     * The store's cities, grouped by state.
     *
     * @return Collection<string, Collection<int, StoreLocation>>
     */
    public function locationsByState(): Collection
    {
        return $this->currentStore->locations()->get()
            ->groupBy(fn (StoreLocation $location) => $location->state->label())
            ->sortKeys();
    }

    public function addLocation(): void
    {
        $this->newCity = trim($this->newCity);

        $this->validate([
            'newState' => ['required', Rule::enum(UsState::class)],
            'newCity' => [
                'required',
                'string',
                'max:255',
                Rule::unique('store_locations', 'city')
                    ->where('store_id', $this->currentStore->id)
                    ->where('state', $this->newState),
            ],
        ], [
            'newCity.unique' => __('That city is already listed for this state.'),
        ], [
            'newState' => 'state',
            'newCity' => 'city',
        ]);

        $this->currentStore->locations()->create([
            'state' => $this->newState,
            'city' => $this->newCity,
        ]);

        // Keep the state selected so several cities can be added in a row.
        $this->reset('newCity');
        Flux::toast(variant: 'success', text: __('City added. Set its package prices on the Products page.'));
    }

    public function removeLocation(int $locationId): void
    {
        $this->currentStore->locations()->whereKey($locationId)->delete();

        Flux::toast(variant: 'success', text: __('City removed.'));
    }
}; ?>

<div>
    <flux:link :href="route('admin.stores.show', $currentStore)" wire:navigate class="text-sm text-zinc-500">&larr; {{ $currentStore->name }}</flux:link>

    <div class="mt-4">
        <flux:heading size="xl">{{ __('Locations') }}</flux:heading>
        <flux:subheading>{{ __('Price packages by the city the family is in. Families choose their state and city before seeing packages.') }}</flux:subheading>
    </div>

    <div class="mt-6 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
        <flux:checkbox wire:model.live="locationPricingEnabled" :label="__('Location-based package pricing')" :description="__('When on, each package is priced per city and only offered in the cities it has a price for. Only packages are affected.')" />
    </div>

    <div class="mt-6 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
        <flux:heading size="lg">{{ __('Cities served') }}</flux:heading>

        <form wire:submit="addLocation" class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-start">
            <flux:field class="sm:w-56">
                <flux:select wire:model="newState" :aria-label="__('State')">
                    <option value="">{{ __('Choose a state') }}</option>
                    @foreach (UsState::cases() as $state)
                        <option value="{{ $state->value }}">{{ $state->label() }}</option>
                    @endforeach
                </flux:select>
                <flux:error name="newState" />
            </flux:field>
            <flux:field class="flex-1">
                <flux:input wire:model="newCity" :placeholder="__('City')" :aria-label="__('City')" />
                <flux:error name="newCity" />
            </flux:field>
            <flux:button type="submit" variant="primary">{{ __('Add city') }}</flux:button>
        </form>

        <div class="mt-6 space-y-5">
            @forelse ($this->locationsByState() as $stateName => $locations)
                <div wire:key="state-{{ $stateName }}">
                    <flux:heading size="sm" class="text-zinc-500">{{ $stateName }}</flux:heading>
                    <ul class="mt-2 divide-y divide-zinc-100 rounded-lg border border-zinc-100 dark:divide-zinc-700 dark:border-zinc-700">
                        @foreach ($locations as $location)
                            <li class="flex items-center justify-between px-3 py-2 text-sm" wire:key="location-{{ $location->id }}">
                                <span>{{ $location->city }}</span>
                                <button type="button" class="text-xs text-zinc-400 underline hover:text-red-600" wire:click="removeLocation({{ $location->id }})" wire:confirm="{{ __('Remove :city? Its package prices will be deleted too.', ['city' => $location->label()]) }}">{{ __('Remove') }}</button>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @empty
                <p class="text-sm text-zinc-500">{{ __('No cities yet. Add the cities you serve, then set each package\'s price per city on the Products page.') }}</p>
            @endforelse
        </div>
    </div>
</div>
