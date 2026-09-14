<?php

use App\Enums\StoreStatus;
use App\Models\Store;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component
{
    public bool $showCreateForm = false;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|string|max:255|alpha_dash|unique:stores,slug')]
    public string $slug = '';

    #[Validate('nullable|email|max:255')]
    public string $contactEmail = '';

    public function updatedName(): void
    {
        if (! $this->slug) {
            $this->slug = str($this->name)->slug();
        }
    }

    public function createStore(): void
    {
        $validated = $this->validate();

        $store = Store::create([
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            'status' => StoreStatus::Draft,
            'contact_email' => $validated['contactEmail'] ?: null,
            'timezone' => 'America/New_York',
            'platform_fee_bps' => 500,
        ]);

        $this->reset(['name', 'slug', 'contactEmail', 'showCreateForm']);

        Flux::toast(variant: 'success', text: __('Store created — set it up, then mark it active.'));

        $this->redirect(route('admin.stores.show', $store), navigate: true);
    }

    public function stores(): Collection
    {
        return Store::withCount(['products', 'orders'])->latest()->get();
    }
}; ?>

<div>
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ __('Stores') }}</flux:heading>
            <flux:subheading>{{ __('Every funeral home on the platform.') }}</flux:subheading>
        </div>
        <flux:button variant="primary" wire:click="$set('showCreateForm', true)">{{ __('New store') }}</flux:button>
    </div>

    @if ($showCreateForm)
        <div class="mt-6 rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:heading size="lg">{{ __('Create a new store') }}</flux:heading>
            <form wire:submit="createStore" class="mt-4 grid gap-4 sm:grid-cols-2">
                <flux:field>
                    <flux:label>{{ __('Funeral home name') }}</flux:label>
                    <flux:input wire:model.blur="name" required />
                    <flux:error name="name" />
                </flux:field>
                <flux:field>
                    <flux:label>{{ __('Subdomain') }}</flux:label>
                    <flux:input wire:model="slug" required>
                        <x-slot name="iconTrailing">
                            <span class="text-xs text-zinc-400">.{{ config('app.root_domain') }}</span>
                        </x-slot>
                    </flux:input>
                    <flux:error name="slug" />
                </flux:field>
                <flux:field class="sm:col-span-2">
                    <flux:label>{{ __('Contact email') }}</flux:label>
                    <flux:input type="email" wire:model="contactEmail" />
                    <flux:error name="contactEmail" />
                </flux:field>
                <div class="flex justify-end gap-2 sm:col-span-2">
                    <flux:button variant="ghost" wire:click="$set('showCreateForm', false)">{{ __('Cancel') }}</flux:button>
                    <flux:button type="submit" variant="primary">{{ __('Create store') }}</flux:button>
                </div>
            </form>
        </div>
    @endif

    <div class="mt-6 overflow-x-auto rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
        <table class="w-full text-sm">
            <thead class="border-b border-zinc-100 text-left text-xs uppercase tracking-wide text-zinc-400 dark:border-zinc-700">
                <tr>
                    <th class="px-4 py-3">{{ __('Store') }}</th>
                    <th class="px-4 py-3">{{ __('Status') }}</th>
                    <th class="px-4 py-3">{{ __('Stripe') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('Products') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('Orders') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                @forelse ($this->stores() as $store)
                    <tr wire:key="store-{{ $store->id }}" class="hover:bg-zinc-50 dark:hover:bg-zinc-700/50">
                        <td class="px-4 py-3">
                            <flux:link :href="route('admin.stores.show', $store)" wire:navigate class="font-medium">{{ $store->name }}</flux:link>
                            <p class="text-xs text-zinc-400">{{ $store->slug }}.{{ config('app.root_domain') }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <flux:badge :color="match ($store->status) { StoreStatus::Active => 'green', StoreStatus::Draft => 'zinc', StoreStatus::Suspended => 'red' }" size="sm">
                                {{ ucfirst($store->status->value) }}
                            </flux:badge>
                        </td>
                        <td class="px-4 py-3">
                            @if ($store->isStripeReady())
                                <flux:badge color="green" size="sm">{{ __('Connected') }}</flux:badge>
                            @else
                                <flux:badge color="zinc" size="sm">{{ __('Not connected') }}</flux:badge>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">{{ $store->products_count }}</td>
                        <td class="px-4 py-3 text-right">{{ $store->orders_count }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-zinc-500 dark:text-zinc-400">{{ __('No stores yet.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
