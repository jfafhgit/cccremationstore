@props(['order', 'showAdminName' => false])

{{--
    An order's refunds for staff and admins. The admin page passes its
    "Issue refund" button in the actions slot.
--}}
@php
    $refundedCents = $order->refundedCents();
@endphp
<div {{ $attributes->class('rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800') }}>
    <div class="flex items-start justify-between gap-4">
        <div>
            <flux:heading size="sm" class="text-zinc-500">{{ __('Refunds') }}</flux:heading>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
                @if ($refundedCents === 0)
                    {{ __('Nothing refunded yet.') }}
                @else
                    {{ __('Refunded $:refunded of $:total.', ['refunded' => number_format($refundedCents / 100, 2), 'total' => $order->totalInDollars()]) }}
                @endif
            </p>
        </div>
        {{ $actions ?? '' }}
    </div>

    @if ($order->refunds->isNotEmpty())
        <ul class="mt-4 divide-y divide-zinc-100 text-sm dark:divide-zinc-700">
            @foreach ($order->refunds->sortByDesc('created_at') as $refund)
                <li class="flex items-start justify-between gap-4 py-2" wire:key="refund-{{ $refund->id }}">
                    <div>
                        <p>
                            {{ $refund->created_at->format('M j, Y g:i A') }}
                            &middot;
                            {{ $refund->refunded_by_user_id ? ($showAdminName && $refund->refundedBy ? $refund->refundedBy->name : config('app.name')) : __('Stripe dashboard') }}
                        </p>
                        @if ($refund->reason)
                            <p class="text-zinc-500 dark:text-zinc-400">{{ $refund->reason }}</p>
                        @endif
                        @if ($refund->application_fee_refunded_cents > 0)
                            <p class="text-zinc-500 dark:text-zinc-400">{{ __('Platform fee returned: $:amount', ['amount' => number_format($refund->application_fee_refunded_cents / 100, 2)]) }}</p>
                        @endif
                    </div>
                    <div class="text-right">
                        <p class="font-medium">${{ $refund->amountInDollars() }}</p>
                        @if ($refund->status !== 'succeeded')
                            <flux:badge size="sm" :color="in_array($refund->status, ['failed', 'canceled'], true) ? 'red' : 'amber'">{{ ucfirst(str_replace('_', ' ', $refund->status)) }}</flux:badge>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
