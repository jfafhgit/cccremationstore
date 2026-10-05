<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Store;
use App\Services\RefundService;
use Flux\Flux;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Stripe\Exception\ApiErrorException;

new class extends Component
{
    public Store $currentStore;

    public Order $currentOrder;

    public string $status = '';

    public string $internalNotes = '';

    #[Locked]
    public ?string $revealedSsn = null;

    /**
     * Show the full Social Security number, on purpose and on the record.
     */
    public function revealSsn(): void
    {
        $ssn = $this->currentOrder->detail?->ssn;

        abort_unless($ssn, 404);

        Log::info('Admin viewed a Social Security number.', [
            'order_id' => $this->currentOrder->id,
            'user_id' => auth()->id(),
        ]);

        $this->revealedSsn = $ssn;
    }

    /**
     * Both {store} and {order} are resolved here rather than trusted as
     * independent implicit bindings, so an order id can never be viewed
     * under the wrong store's URL.
     */
    public function mount(Store $store, int|string $order): void
    {
        $this->currentStore = $store;
        $this->currentOrder = $store->orders()->with(['items', 'detail', 'refunds.refundedBy'])->findOrFail($order);
        $this->status = $this->currentOrder->status->value;
        $this->internalNotes = $this->currentOrder->internal_notes ?? '';
    }

    /** "full", "minus_fee", or "custom". */
    public string $refundOption = 'full';

    public string $refundAmount = '';

    public string $refundReason = '';

    /**
     * Everything left to refund, less the processing fee the family paid,
     * or null when the order had no processing fee (or it'd leave nothing).
     */
    public function refundableMinusFeeCents(): ?int
    {
        $fee = $this->currentOrder->processing_fee_cents;
        $amount = $this->currentOrder->refundableCents() - $fee;

        return $fee > 0 && $amount > 0 ? $amount : null;
    }

    public function issueRefund(RefundService $refunds): void
    {
        $refundableCents = $this->currentOrder->refundableCents();

        $this->validate([
            'refundOption' => ['required', Rule::in(['full', 'minus_fee', 'custom'])],
            'refundAmount' => [Rule::requiredIf($this->refundOption === 'custom'), 'nullable', 'numeric', 'gt:0', 'lte:'.($refundableCents / 100)],
            'refundReason' => ['nullable', 'string', 'max:1000'],
        ], [
            'refundAmount.lte' => __('The refund can\'t be more than the $:amount left to refund.', ['amount' => number_format($refundableCents / 100, 2)]),
        ]);

        $amountCents = match ($this->refundOption) {
            'full' => $refundableCents,
            'minus_fee' => $this->refundableMinusFeeCents() ?? 0,
            'custom' => (int) round(((float) $this->refundAmount) * 100),
        };

        // The platform fee goes back too, unless only part of the payment is being refunded.
        $returnPlatformFee = $this->refundOption !== 'custom';

        try {
            $refund = $refunds->refund($this->currentOrder, $amountCents, $this->refundReason, auth()->user(), $returnPlatformFee);
        } catch (\InvalidArgumentException $e) {
            $this->addError('refundAmount', $e->getMessage());

            return;
        } catch (ApiErrorException|\RuntimeException $e) {
            Log::error('Could not issue a refund in Stripe.', [
                'order_id' => $this->currentOrder->id,
                'message' => $e->getMessage(),
            ]);

            $this->addError('refundAmount', __('Stripe could not issue the refund: :message', ['message' => $e->getMessage()]));

            return;
        }

        $this->currentOrder->refresh();
        $this->status = $this->currentOrder->status->value;
        $this->reset(['refundOption', 'refundAmount', 'refundReason']);

        Flux::modal('issue-refund')->close();

        if ($returnPlatformFee && $this->currentOrder->platformFeeReturnedCents() < $this->currentOrder->platform_fee_cents) {
            Flux::toast(variant: 'warning', text: __('Refunded $:amount, but the platform fee could not be returned. Return it from the Stripe dashboard.', ['amount' => $refund->amountInDollars()]));
        } else {
            Flux::toast(variant: 'success', text: __('Refunded $:amount.', ['amount' => $refund->amountInDollars()]));
        }
    }

    public function updateStatus(): void
    {
        $this->currentOrder->update([
            'status' => OrderStatus::from($this->status),
            'internal_notes' => $this->internalNotes ?: null,
        ]);

        Flux::toast(variant: 'success', text: __('Order updated.'));
    }

    /**
     * Permanently delete the order with its items, details, and refund
     * records, e.g. a test order. Nothing is changed in Stripe.
     */
    public function deleteOrder(): void
    {
        $this->currentOrder->delete();

        Flux::toast(variant: 'success', text: __('Order :number deleted.', ['number' => $this->currentOrder->order_number]));

        $this->redirect(route('admin.stores.orders', $this->currentStore), navigate: true);
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
        <div class="flex items-center gap-2">
            <flux:badge>{{ $currentOrder->status->label() }}</flux:badge>
            <flux:button
                size="sm"
                variant="ghost"
                icon="trash"
                class="!text-red-600"
                wire:click="deleteOrder"
                wire:confirm="{{ $currentOrder->paid_at
                    ? __('Permanently delete order :number? It was paid, so its payment stays in Stripe, but this order, the family\'s details, and its refund history are erased here. Only do this for test orders.', ['number' => $currentOrder->order_number])
                    : __('Permanently delete order :number? This cannot be undone.', ['number' => $currentOrder->order_number]) }}"
            >{{ __('Delete') }}</flux:button>
        </div>
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
            @if ($currentOrder->service_city)
                <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Service area: :city, :state', ['city' => $currentOrder->service_city, 'state' => $currentOrder->service_state]) }}</p>
            @endif
        </div>
    </div>

    <div class="mt-6 rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
        <div class="border-b border-zinc-100 px-5 py-4 dark:border-zinc-700">
            <flux:heading size="sm">{{ __('Items') }}</flux:heading>
        </div>
        <ul class="divide-y divide-zinc-100 dark:divide-zinc-700">
            @foreach ($currentOrder->items as $item)
                <li class="px-5 py-3 text-sm">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="font-medium">{{ $item->name_snapshot }}</p>
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

    @if ($currentOrder->paid_at && $currentOrder->stripe_payment_intent_id)
        @php
            $refundableCents = $currentOrder->refundableCents();
        @endphp
        <x-order-refunds :order="$currentOrder" show-admin-name class="mt-6">
            @if ($refundableCents > 0)
                <x-slot:actions>
                    <flux:modal.trigger name="issue-refund">
                        <flux:button size="sm" icon="arrow-uturn-left">{{ __('Issue refund') }}</flux:button>
                    </flux:modal.trigger>
                </x-slot:actions>
            @endif
        </x-order-refunds>

        <flux:modal name="issue-refund" class="md:w-md">
            <form wire:submit="issueRefund" class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ __('Issue a refund') }}</flux:heading>
                    <flux:text class="mt-2">{{ __('Refunds go back to the card the family paid with, from :store\'s Stripe account. This can\'t be undone.', ['store' => $currentStore->name]) }}</flux:text>
                </div>

                <flux:radio.group wire:model.live="refundOption" :label="__('Amount')">
                    @php
                        $feeNote = $currentOrder->platform_fee_cents > 0 ? __('The platform fee is returned to :store too.', ['store' => $currentStore->name]) : null;
                    @endphp
                    <flux:radio value="full" :label="__('Full amount ($:amount)', ['amount' => number_format($refundableCents / 100, 2)])" :description="$feeNote" />
                    @if ($minusFeeCents = $this->refundableMinusFeeCents())
                        <flux:radio value="minus_fee" :label="__('Full amount minus the processing fee ($:amount)', ['amount' => number_format($minusFeeCents / 100, 2)])" :description="$feeNote" />
                    @endif
                    <flux:radio value="custom" :label="__('Custom amount')" :description="$feeNote ? __('The platform fee is kept.') : null" />
                </flux:radio.group>

                @if ($refundOption === 'custom')
                    <flux:input wire:model="refundAmount" type="number" step="0.01" min="0.01" :max="$refundableCents / 100" icon="currency-dollar" :label="__('Refund amount')" :description="__('Up to $:amount.', ['amount' => number_format($refundableCents / 100, 2)])" />
                @endif
                <flux:error name="refundAmount" />

                <flux:textarea wire:model="refundReason" rows="2" :label="__('Reason (optional)')" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="danger">{{ __('Refund') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif

    @if ($currentOrder->paid_at && ! $currentStore->usesExternalVitalStatistics())
        <div class="mt-6 flex justify-end">
            <flux:button size="sm" :href="$currentOrder->detailsUrl()" target="_blank" icon:trailing="arrow-top-right-on-square">
                {{ __('Open Vital Statistics form') }}
            </flux:button>
        </div>
    @endif

    @if ($currentOrder->detail)
        <x-vital-statistics :detail="$currentOrder->detail" :revealed-ssn="$revealedSsn" class="mt-3 dark:bg-zinc-800" />
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
