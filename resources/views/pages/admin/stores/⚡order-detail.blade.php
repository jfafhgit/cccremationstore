<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Store;
use Flux\Flux;
use Livewire\Component;

new class extends Component
{
    public Store $currentStore;

    public Order $currentOrder;

    public string $status = '';

    public string $internalNotes = '';

    /**
     * Both {store} and {order} are resolved here rather than trusted as
     * independent implicit bindings, so an order id can never be viewed
     * under the wrong store's URL.
     */
    public function mount(Store $store, int|string $order): void
    {
        $this->currentStore = $store;
        $this->currentOrder = $store->orders()->with(['items', 'detail'])->findOrFail($order);
        $this->status = $this->currentOrder->status->value;
        $this->internalNotes = $this->currentOrder->internal_notes ?? '';
    }

    public function updateStatus(): void
    {
        $this->currentOrder->update([
            'status' => OrderStatus::from($this->status),
            'internal_notes' => $this->internalNotes ?: null,
        ]);

        Flux::toast(variant: 'success', text: __('Order updated.'));
    }
}; ?>

<div>
    <flux:link :href="route('admin.stores.orders', $currentStore)" wire:navigate class="text-sm text-zinc-500">
        &larr; {{ __('Back to orders') }}
    </flux:link>

    <div class="mt-4 flex items-start justify-between">
        <div>
            <flux:heading size="xl">{{ __('Order :number', ['number' => $currentOrder->order_number]) }}</flux:heading>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ $currentStore->name }} &middot; {{ $currentOrder->created_at->format('F j, Y \a\t g:i A') }}</p>
        </div>
        <flux:badge>{{ $currentOrder->status->label() }}</flux:badge>
    </div>

    <div class="mt-8 grid gap-6 sm:grid-cols-2">
        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:heading size="sm" class="text-zinc-500">{{ __('Purchaser') }}</flux:heading>
            <p class="mt-2 font-medium">{{ $currentOrder->purchaserName() }}</p>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $currentOrder->purchaser_email }}</p>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $currentOrder->purchaser_phone }}</p>
        </div>
        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:heading size="sm" class="text-zinc-500">{{ __('Their loved one') }}</flux:heading>
            <p class="mt-2 font-medium">{{ $currentOrder->deceasedName() }}</p>
            @if ($currentOrder->timing)
                <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $currentOrder->timing->label() }}</p>
            @endif
        </div>
    </div>

    <div class="mt-6 rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
        <div class="border-b border-zinc-100 px-5 py-4 dark:border-zinc-700">
            <flux:heading size="sm">{{ __('Items') }}</flux:heading>
        </div>
        <ul class="divide-y divide-zinc-100 dark:divide-zinc-700">
            @foreach ($currentOrder->items as $item)
                <li class="flex items-center justify-between px-5 py-3 text-sm">
                    <div>
                        <p class="font-medium">{{ $item->name_snapshot }}</p>
                        @if ($item->variant_snapshot)
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $item->variant_snapshot }}</p>
                        @endif
                    </div>
                    <p>{{ $item->quantity }} &times; ${{ number_format($item->unit_price_cents / 100, 2) }}</p>
                </li>
            @endforeach
        </ul>
        <div class="space-y-1 border-t border-zinc-100 px-5 py-4 dark:border-zinc-700">
            <div class="flex items-center justify-between text-sm text-zinc-500 dark:text-zinc-400">
                <span>{{ __('Subtotal') }}</span>
                <span>${{ $currentOrder->subtotalInDollars() }}</span>
            </div>
            @if ($currentOrder->tax_cents > 0)
                <div class="flex items-center justify-between text-sm text-zinc-500 dark:text-zinc-400">
                    <span>{{ __('Tax') }}</span>
                    <span>${{ $currentOrder->taxInDollars() }}</span>
                </div>
            @endif
            <div class="flex items-center justify-between pt-1 font-semibold">
                <span>{{ __('Total') }}</span>
                <span>${{ $currentOrder->totalInDollars() }}</span>
            </div>
        </div>
    </div>

    @if ($detail = $currentOrder->detail)
        <div class="mt-6 rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:heading size="sm" class="text-zinc-500">{{ __('Additional details') }}</flux:heading>
            @if ($detail->obituary_text)
                <p class="mt-3 whitespace-pre-line text-sm">{{ $detail->obituary_text }}</p>
            @endif
        </div>
    @endif

    <div class="mt-6 rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800">
        <flux:heading size="sm" class="text-zinc-500">{{ __('Manage order') }}</flux:heading>
        <form wire:submit="updateStatus" class="mt-4 space-y-4">
            <flux:field>
                <flux:label>{{ __('Status') }}</flux:label>
                <flux:select wire:model="status">
                    @foreach (OrderStatus::cases() as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </flux:select>
            </flux:field>
            <flux:field>
                <flux:label>{{ __('Internal notes') }}</flux:label>
                <flux:description>{{ __('Visible to platform admins only — never shown to the funeral home or purchaser.') }}</flux:description>
                <flux:textarea wire:model="internalNotes" rows="3" />
            </flux:field>
            <div class="flex justify-end">
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </div>
</div>
