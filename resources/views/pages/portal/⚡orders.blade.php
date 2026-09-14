<?php

use App\Models\Store;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::portal')] class extends Component
{
    use WithPagination;

    #[Computed]
    public function orders(): LengthAwarePaginator
    {
        return Store::current()->orders()
            ->whereNotNull('paid_at')
            ->latest('paid_at')
            ->paginate(20);
    }
}; ?>

<div>
    <flux:heading size="xl" class="font-serif">{{ __('Orders') }}</flux:heading>
    <flux:subheading class="mt-1">{{ __('Completed and paid orders for your store.') }}</flux:subheading>

    <div class="mt-6 overflow-x-auto rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
        <table class="w-full text-sm">
            <thead class="border-b border-zinc-100 text-left text-xs uppercase tracking-wide text-zinc-400 dark:border-zinc-800">
                <tr>
                    <th class="px-4 py-3">{{ __('Order #') }}</th>
                    <th class="px-4 py-3">{{ __('Deceased') }}</th>
                    <th class="px-4 py-3">{{ __('Purchaser') }}</th>
                    <th class="px-4 py-3">{{ __('Status') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('Total') }}</th>
                    <th class="px-4 py-3">{{ __('Paid') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($this->orders() as $order)
                    <tr wire:key="order-{{ $order->id }}" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                        <td class="px-4 py-3">
                            <flux:link :href="route('portal.order-detail', ['order' => $order->id])" wire:navigate class="font-medium">
                                {{ $order->order_number }}
                            </flux:link>
                        </td>
                        <td class="px-4 py-3">{{ $order->deceasedName() ?: '—' }}</td>
                        <td class="px-4 py-3">{{ $order->purchaserName() ?: '—' }}</td>
                        <td class="px-4 py-3">
                            <flux:badge size="sm">{{ $order->status->label() }}</flux:badge>
                        </td>
                        <td class="px-4 py-3 text-right">${{ $order->totalInDollars() }}</td>
                        <td class="px-4 py-3 text-zinc-500 dark:text-zinc-400">{{ $order->paid_at?->format('M j, Y') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-zinc-500 dark:text-zinc-400">
                            {{ __('No paid orders yet.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $this->orders()->links() }}
    </div>
</div>
