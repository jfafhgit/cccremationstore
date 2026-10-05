<?php

use App\Models\Store;
use Flux\Flux;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public Store $currentStore;

    /** The store's name, typed to confirm deleting every order. */
    public string $deleteAllConfirmation = '';

    public function mount(Store $store): void
    {
        $this->currentStore = $store;
    }

    #[Computed]
    public function orders(): LengthAwarePaginator
    {
        return $this->currentStore->orders()->latest()->paginate(20);
    }

    /**
     * Permanently delete every order in this store, e.g. to clear out test
     * orders before the store goes live. Nothing is changed in Stripe.
     */
    public function deleteAllOrders(): void
    {
        $this->validate(
            ['deleteAllConfirmation' => ['required', Rule::in([$this->currentStore->name])]],
            ['deleteAllConfirmation.in' => __('Type the store name exactly as shown to confirm.')],
            ['deleteAllConfirmation' => __('store name')],
        );

        // Items, details, and refunds go with each order (cascading foreign keys).
        $deletedCount = $this->currentStore->orders()->delete();

        $this->reset('deleteAllConfirmation');
        $this->resetPage();
        unset($this->orders);

        Flux::modal('delete-all-orders')->close();
        Flux::toast(variant: 'success', text: trans_choice('Deleted :count order.|Deleted :count orders.', $deletedCount, ['count' => $deletedCount]));
    }
}; ?>

<div>
    <flux:link :href="route('admin.stores.show', $currentStore)" wire:navigate class="text-sm text-zinc-500">&larr; {{ $currentStore->name }}</flux:link>

    <div class="mt-4 flex items-center justify-between">
        <flux:heading size="xl">{{ __('Orders') }}</flux:heading>
        @if ($this->orders()->total() > 0)
            <flux:modal.trigger name="delete-all-orders">
                <flux:button variant="ghost" icon="trash" class="!text-red-600">{{ __('Delete all orders') }}</flux:button>
            </flux:modal.trigger>
        @endif
    </div>

    <flux:modal name="delete-all-orders" class="md:w-md">
        <form wire:submit="deleteAllOrders" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('Delete all :count orders?', ['count' => $this->orders()->total()]) }}</flux:heading>
                <flux:text class="mt-2">{{ __('Use this to clear out test orders before the store goes live. Every order in :store, with the family\'s details and refund history, is permanently erased here. Payments already made stay in Stripe. This cannot be undone.', ['store' => $currentStore->name]) }}</flux:text>
            </div>

            <flux:field>
                <flux:label>{{ __('Type :name to confirm', ['name' => $currentStore->name]) }}</flux:label>
                <flux:input wire:model="deleteAllConfirmation" autocomplete="off" />
                <flux:error name="deleteAllConfirmation" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="danger">{{ __('Delete all orders') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <div class="mt-6 overflow-x-auto rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
        <table class="w-full text-sm">
            <thead class="border-b border-zinc-100 text-left text-xs uppercase tracking-wide text-zinc-400 dark:border-zinc-700">
                <tr>
                    <th class="px-4 py-3">{{ __('Order #') }}</th>
                    <th class="px-4 py-3">{{ __('Deceased') }}</th>
                    <th class="px-4 py-3">{{ __('Purchaser') }}</th>
                    <th class="px-4 py-3">{{ __('Status') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('Total') }}</th>
                    <th class="px-4 py-3">{{ __('Placed') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                @forelse ($this->orders() as $order)
                    <tr wire:key="order-{{ $order->id }}" class="hover:bg-zinc-50 dark:hover:bg-zinc-700/50">
                        <td class="px-4 py-3">
                            <flux:link :href="route('admin.stores.order-detail', ['store' => $currentStore, 'order' => $order])" wire:navigate class="font-medium">
                                {{ $order->order_number }}
                            </flux:link>
                        </td>
                        <td class="px-4 py-3">{{ $order->deceasedName() ?: '—' }}</td>
                        <td class="px-4 py-3">{{ $order->purchaserName() ?: '—' }}</td>
                        <td class="px-4 py-3"><flux:badge size="sm">{{ $order->status->label() }}</flux:badge></td>
                        <td class="px-4 py-3 text-right">${{ $order->totalInDollars() }}</td>
                        <td class="px-4 py-3 text-zinc-500 dark:text-zinc-400">{{ $order->created_at->format('M j, Y') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-zinc-500 dark:text-zinc-400">{{ __('No orders yet.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $this->orders()->links() }}
    </div>
</div>
