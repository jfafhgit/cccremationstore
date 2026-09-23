<?php

use App\Models\Order;
use App\Models\Store;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::portal')] class extends Component
{
    public Order $currentOrder;

    /**
     * Scoped to the current store rather than relying on implicit route
     * model binding, which would otherwise happily resolve an order id
     * belonging to a different funeral home.
     */
    public function mount(int|string $order): void
    {
        $this->currentOrder = Store::current()->orders()
            ->with(['items', 'detail'])
            ->findOrFail($order);
    }
}; ?>

<div>
    <flux:link :href="route('portal.orders')" wire:navigate class="text-sm text-zinc-500">&larr; {{ __('Back to orders') }}</flux:link>

    <div class="mt-4 flex items-start justify-between">
        <div>
            <flux:heading size="xl" class="font-serif">{{ __('Order :number', ['number' => $currentOrder->order_number]) }}</flux:heading>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('Placed :date', ['date' => $currentOrder->created_at->format('F j, Y \a\t g:i A')]) }}
            </p>
        </div>
        <flux:badge>{{ $currentOrder->status->label() }}</flux:badge>
    </div>

    <div class="mt-8 grid gap-6 sm:grid-cols-2">
        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
            <flux:heading size="sm" class="text-zinc-500">{{ __('Purchaser') }}</flux:heading>
            <p class="mt-2 font-medium">{{ $currentOrder->purchaserName() }}</p>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $currentOrder->purchaser_email }}</p>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $currentOrder->purchaser_phone }}</p>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('Relationship:') }} {{ $currentOrder->relationship_to_deceased }}</p>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
            <flux:heading size="sm" class="text-zinc-500">{{ __('Their loved one') }}</flux:heading>
            <p class="mt-2 font-medium">{{ $currentOrder->deceasedName() }}</p>
            @if ($currentOrder->timing)
                <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $currentOrder->timing->label() }}</p>
            @endif
        </div>
    </div>

    <div class="mt-6 rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
        <div class="border-b border-zinc-100 px-5 py-4 dark:border-zinc-800">
            <flux:heading size="sm">{{ __('Items') }}</flux:heading>
        </div>
        <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
            @foreach ($currentOrder->items as $item)
                <li class="flex items-center justify-between px-5 py-3 text-sm">
                    <div>
                        <p class="font-medium text-zinc-800 dark:text-zinc-100">{{ $item->name_snapshot }}</p>
                        @if ($item->variant_snapshot)
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $item->variant_snapshot }}</p>
                        @endif
                    </div>
                    <div class="text-right">
                        <p>{{ $item->quantity }} &times; ${{ number_format($item->unit_price_cents / 100, 2) }}</p>
                    </div>
                </li>
            @endforeach
        </ul>
        <div class="space-y-1 border-t border-zinc-100 px-5 py-4 dark:border-zinc-800">
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
            @if ($currentOrder->processing_fee_cents > 0)
                <div class="flex items-center justify-between text-sm text-zinc-500 dark:text-zinc-400">
                    <span>{{ __('Processing fee') }}</span>
                    <span>${{ $currentOrder->processingFeeInDollars() }}</span>
                </div>
            @endif
            <div class="flex items-center justify-between pt-1 font-semibold">
                <span>{{ __('Total') }}</span>
                <span>${{ $currentOrder->totalInDollars() }}</span>
            </div>
        </div>
    </div>

    @if ($detail = $currentOrder->detail)
        <div class="mt-6 rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
            <flux:heading size="sm" class="text-zinc-500">{{ __('Additional details') }}</flux:heading>
            <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                @if ($detail->date_of_birth)
                    <div><dt class="text-zinc-400">{{ __('Date of birth') }}</dt><dd>{{ $detail->date_of_birth->format('M j, Y') }}</dd></div>
                @endif
                @if ($detail->date_of_death)
                    <div><dt class="text-zinc-400">{{ __('Date of passing') }}</dt><dd>{{ $detail->date_of_death->format('M j, Y') }}</dd></div>
                @endif
                @if ($detail->place_of_death)
                    <div><dt class="text-zinc-400">{{ __('Place of passing') }}</dt><dd>{{ $detail->place_of_death }}</dd></div>
                @endif
                @if (! is_null($detail->veteran_status))
                    <div><dt class="text-zinc-400">{{ __('Veteran') }}</dt><dd>{{ $detail->veteran_status ? __('Yes') : __('No') }}</dd></div>
                @endif
            </dl>
            @if ($detail->obituary_text)
                <div class="mt-4">
                    <dt class="text-sm text-zinc-400">{{ __('Obituary') }}</dt>
                    <dd class="mt-1 whitespace-pre-line text-sm">{{ $detail->obituary_text }}</dd>
                </div>
            @endif
            @if ($detail->service_preferences)
                <div class="mt-4">
                    <dt class="text-sm text-zinc-400">{{ __('Service preferences') }}</dt>
                    <dd class="mt-1 whitespace-pre-line text-sm">{{ $detail->service_preferences }}</dd>
                </div>
            @endif
            @if ($detail->additional_notes)
                <div class="mt-4">
                    <dt class="text-sm text-zinc-400">{{ __('Additional notes') }}</dt>
                    <dd class="mt-1 whitespace-pre-line text-sm">{{ $detail->additional_notes }}</dd>
                </div>
            @endif
        </div>
    @else
        <div class="mt-6 rounded-xl border border-dashed border-zinc-200 p-5 text-center text-sm text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
            {{ __("The purchaser hasn't submitted the follow-up details form yet.") }}
        </div>
    @endif
</div>
