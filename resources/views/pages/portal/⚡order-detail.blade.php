<?php

use App\Models\Order;
use App\Models\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

new #[Layout('layouts::portal')] class extends Component
{
    public Order $currentOrder;

    #[Locked]
    public ?string $revealedSsn = null;

    /**
     * Show the full Social Security number, on purpose and on the record.
     */
    public function revealSsn(): void
    {
        $ssn = $this->currentOrder->detail?->ssn;

        abort_unless($ssn, 404);

        Log::info('Staff viewed a Social Security number.', [
            'order_id' => $this->currentOrder->id,
            'store_user_id' => Auth::guard('store')->id(),
        ]);

        $this->revealedSsn = $ssn;
    }

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
            @if ($currentOrder->service_city)
                <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Service area: :city, :state', ['city' => $currentOrder->service_city, 'state' => $currentOrder->service_state]) }}</p>
            @endif
        </div>
    </div>

    <div class="mt-6 rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
        <div class="border-b border-zinc-100 px-5 py-4 dark:border-zinc-800">
            <flux:heading size="sm">{{ __('Items') }}</flux:heading>
        </div>
        <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
            @foreach ($currentOrder->items as $item)
                <li class="px-5 py-3 text-sm">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="font-medium text-zinc-800 dark:text-zinc-100">{{ $item->name_snapshot }}</p>
                            @if ($item->variant_snapshot)
                                <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $item->variant_snapshot }}</p>
                            @endif
                        </div>
                        <div class="text-right">
                            <p>${{ number_format($item->regularTotalCents() / 100, 2) }}</p>
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $item->quantity }} &times; ${{ number_format($item->unit_price_cents / 100, 2) }}</p>
                        </div>
                    </div>
                    @if ($item->discountCents() > 0)
                        <div class="mt-1 flex items-center justify-between text-zinc-500 dark:text-zinc-400">
                            <span class="pl-4">{{ $item->discountLabel() }}</span>
                            <span>&minus;${{ number_format($item->discountCents() / 100, 2) }}</span>
                        </div>
                    @endif
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

    @if ($currentOrder->detail)
        <x-vital-statistics :detail="$currentOrder->detail" :revealed-ssn="$revealedSsn" class="mt-6" />
    @else
        <div class="mt-6 rounded-xl border border-dashed border-zinc-200 p-5 text-center text-sm text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
            {{ $currentOrder->store->usesExternalVitalStatistics()
                ? __('Vital Statistics are collected on the form on your website, so they are not shown here.')
                : __("The family hasn't started the Vital Statistics form yet.") }}
        </div>
    @endif
</div>
