<?php

use App\Models\Store;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public Store $currentStore;

    public function mount(Store $store): void
    {
        $this->currentStore = $store;
    }

    #[Computed]
    public function orders(): LengthAwarePaginator
    {
        return $this->currentStore->orders()->latest()->paginate(20);
    }
}; ?>

<div>
    <flux:link :href="route('admin.stores.show', $currentStore)" wire:navigate class="text-sm text-zinc-500">&larr; {{ $currentStore->name }}</flux:link>

    <flux:heading size="xl" class="mt-4">{{ __('Orders') }}</flux:heading>

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
