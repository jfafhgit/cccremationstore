<?php

use App\Models\Store;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * "Leads" for a funeral home's own staff means people who started a
 * checkout on their store but never completed payment — not the
 * platform's own prospective-funeral-home inquiries (App\Models\Lead),
 * which are a separate, admin-only concern (see routes/admin.php).
 */
new #[Layout('layouts::portal')] class extends Component
{
    use WithPagination;

    #[Computed]
    public function incompleteOrders(): LengthAwarePaginator
    {
        return Store::current()->orders()
            ->whereNull('paid_at')
            ->whereNotNull('purchaser_email')
            ->latest()
            ->paginate(20);
    }
}; ?>

<div>
    <flux:heading size="xl" class="font-serif">{{ __('Incomplete orders') }}</flux:heading>
    <flux:subheading class="mt-1">{{ __('People who started an order but have not completed payment yet — worth a follow-up call.') }}</flux:subheading>

    <div class="mt-6 overflow-x-auto rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
        <table class="w-full text-sm">
            <thead class="border-b border-zinc-100 text-left text-xs uppercase tracking-wide text-zinc-400 dark:border-zinc-800">
                <tr>
                    <th class="px-4 py-3">{{ __('Started') }}</th>
                    <th class="px-4 py-3">{{ __('Purchaser') }}</th>
                    <th class="px-4 py-3">{{ __('Email') }}</th>
                    <th class="px-4 py-3">{{ __('Phone') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('Cart total') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($this->incompleteOrders() as $order)
                    <tr wire:key="lead-{{ $order->id }}">
                        <td class="px-4 py-3 text-zinc-500 dark:text-zinc-400">{{ $order->created_at->format('M j, Y g:i A') }}</td>
                        <td class="px-4 py-3 font-medium">{{ $order->purchaserName() }}</td>
                        <td class="px-4 py-3">{{ $order->purchaser_email }}</td>
                        <td class="px-4 py-3">{{ $order->purchaser_phone }}</td>
                        <td class="px-4 py-3 text-right">${{ $order->totalInDollars() }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-zinc-500 dark:text-zinc-400">
                            {{ __('No incomplete orders right now.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $this->incompleteOrders()->links() }}
    </div>
</div>
