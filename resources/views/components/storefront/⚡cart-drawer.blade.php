<?php

use App\Models\Product;
use App\Models\Store;
use App\Services\Cart;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    public ?Store $store = null;

    public bool $open = false;

    /** Keys iterated from Cart::allLines() that are single-select slots — fixed quantity of 1, no stepper, just a remove action. */
    private const SLOT_KEYS = ['package', 'container', 'urn'];

    #[On('cart-updated')]
    public function refreshCart(): void
    {
        // Re-rendering is enough; cart state is read fresh from the session
        // in the view below via $this->cart().
    }

    public function cart(): Cart
    {
        return new Cart($this->store);
    }

    public function isSlot(string $key): bool
    {
        return in_array($key, self::SLOT_KEYS, true);
    }

    /**
     * @param  array<string, mixed>  $line
     */
    public function isRequiredKey(string $key, array $line): bool
    {
        return match ($key) {
            'container' => (bool) $this->store?->requires_container,
            'urn' => (bool) $this->store?->requires_urn,
            default => (bool) ($line['is_required'] ?? false),
        };
    }

    public function removeLine(string $key): void
    {
        // The package underlies everything else in the cart, so removing it
        // is really a "start over" — send the customer back to step one
        // instead of leaving them mid-flow with an orphaned cart.
        if ($key === 'package') {
            $this->startOver();

            return;
        }

        $this->cart()->removeAny($key);
        $this->dispatch('cart-updated');
    }

    public function startOver(): void
    {
        $this->cart()->clear();
        $this->open = false;
        $this->dispatch('cart-updated');

        $this->redirect(route('storefront.start', ['store' => $this->store->slug]), navigate: true);
    }

    public function incrementLine(string $key): void
    {
        $line = $this->cart()->allLines()->get($key);

        if (! $line) {
            return;
        }

        $this->cart()->updateLineQuantity($key, $line['quantity'] + 1);
        $this->dispatch('cart-updated');
    }

    public function decrementLine(string $key): void
    {
        $line = $this->cart()->allLines()->get($key);

        if (! $line) {
            return;
        }

        $this->cart()->updateLineQuantity($key, $line['quantity'] - 1);
        $this->dispatch('cart-updated');
    }
}; ?>

<div x-data="{ open: $wire.entangle('open') }" x-effect="document.body.classList.toggle('overflow-hidden', open)" @keydown.escape.window="open = false">
    <button
        type="button"
        x-on:click="open = true"
        class="relative flex size-10 items-center justify-center rounded-full border border-brand-200 bg-white text-brand-800 transition hover:bg-brand-50"
        aria-label="{{ __('Open cart') }}"
    >
        <flux:icon.shopping-bag class="size-5" />
        @if ($store && $this->cart()->itemCount() > 0)
            <span class="absolute -right-1 -top-1 flex size-5 items-center justify-center rounded-full bg-store text-[10px] font-semibold text-store-foreground">
                {{ $this->cart()->itemCount() }}
            </span>
        @endif
    </button>

    <div x-show="open" style="display: none" class="fixed inset-0 z-50" role="dialog" aria-modal="true" aria-label="{{ __('Your selections') }}">
        <div
            x-show="open"
            x-transition.opacity.duration.300ms
            class="absolute inset-0 bg-zinc-900/40"
            x-on:click="open = false"
        ></div>

        <div class="pointer-events-none absolute inset-y-0 right-0 flex w-full max-w-full sm:max-w-md">
            <div
                x-show="open"
                x-transition:enter="transform transition ease-out duration-300"
                x-transition:enter-start="translate-x-full"
                x-transition:enter-end="translate-x-0"
                x-transition:leave="transform transition ease-in duration-200"
                x-transition:leave-start="translate-x-0"
                x-transition:leave-end="translate-x-full"
                class="pointer-events-auto flex h-dvh w-full flex-col bg-white shadow-2xl"
            >
                <div class="flex shrink-0 items-center justify-between border-b border-zinc-100 px-4 py-4 sm:px-6 sm:py-5">
                    <flux:heading size="lg">{{ __('Your selections') }}</flux:heading>
                    <flux:button variant="ghost" icon="x-mark" x-on:click="open = false" aria-label="{{ __('Close cart') }}" />
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-4 py-4 sm:px-6 sm:py-5">
                    @if ($store && $this->cart()->isEmpty())
                        <p class="text-sm text-zinc-500">{{ __("You haven't selected anything yet.") }}</p>
                    @elseif ($store)
                        <ul class="space-y-4">
                            @foreach ($this->cart()->allLines() as $key => $line)
                                <li class="flex items-start gap-3 sm:gap-4 border-b border-zinc-100 pb-4" wire:key="cart-line-{{ $key }}">
                                    <x-product-image :src="Product::imageUrlFor($line['image_path'] ?? null)" :category="$line['category'] ?? 'package'" class="size-16 shrink-0 rounded-lg sm:size-20" />

                                    <div class="min-w-0 flex-1">
                                        <p class="font-medium text-zinc-800">{{ $line['name'] }}</p>
                                        @if ($line['variant_name'] ?? null)
                                            <p class="text-xs text-zinc-500">{{ $line['variant_name'] }}</p>
                                        @endif
                                        <p class="mt-1 text-sm text-zinc-500">
                                            @if ($line['base_price_cents'] ?? 0)
                                                ${{ number_format($line['base_price_cents'] / 100, 2) }} + ${{ number_format($line['unit_price_cents'] / 100, 2) }} {{ ($line['unit_label'] ?? null) ? __('per :unit', ['unit' => $line['unit_label']]) : __('each') }}
                                            @else
                                                ${{ number_format($line['unit_price_cents'] / 100, 2) }} {{ __('each') }}
                                            @endif
                                        </p>

                                        <div class="mt-3 flex items-center justify-between">
                                            @if ($this->isSlot($key) || (($line['is_required'] ?? false) && ! array_key_exists('base_price_cents', $line)))
                                                <span class="text-xs text-zinc-400">{{ __('Qty: 1') }}</span>
                                            @else
                                                <div class="flex items-center gap-2">
                                                    <button
                                                        type="button"
                                                        wire:click="decrementLine('{{ $key }}')"
                                                        class="flex size-7 items-center justify-center rounded-full border border-zinc-200 text-sm hover:border-brand-300"
                                                        aria-label="{{ __('Decrease quantity') }}"
                                                    >&minus;</button>
                                                    <span class="w-5 text-center text-sm font-medium">{{ $line['quantity'] }}</span>
                                                    <button
                                                        type="button"
                                                        wire:click="incrementLine('{{ $key }}')"
                                                        class="flex size-7 items-center justify-center rounded-full border border-zinc-200 text-sm hover:border-brand-300"
                                                        aria-label="{{ __('Increase quantity') }}"
                                                    >+</button>
                                                </div>
                                            @endif

                                            @if ($this->isRequiredKey($key, $line))
                                                <span class="text-xs font-medium text-brand-700">{{ __('Required') }}</span>
                                            @elseif ($key === 'package')
                                                <flux:modal.trigger name="confirm-start-over">
                                                    <button type="button" class="text-xs text-zinc-400 underline hover:text-red-600">
                                                        {{ __('Remove') }}
                                                    </button>
                                                </flux:modal.trigger>
                                            @else
                                                <button type="button" wire:click="removeLine('{{ $key }}')" class="text-xs text-zinc-400 underline hover:text-red-600">
                                                    {{ __('Remove') }}
                                                </button>
                                            @endif
                                        </div>
                                    </div>

                                    <span class="shrink-0 text-sm font-semibold text-zinc-800">
                                        ${{ number_format($this->cart()->lineTotalCents($line) / 100, 2) }}
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                        @if ($store && ! $this->cart()->isEmpty())
                            <div class="mt-6 border-t border-zinc-100 pt-5 pb-[max(0.5rem,env(safe-area-inset-bottom))]">
                            <div class="space-y-1 text-sm">
                                <div class="flex items-center justify-between text-zinc-600">
                                    <span>{{ __('Subtotal') }}</span>
                                    <span>${{ number_format($this->cart()->subtotalCents() / 100, 2) }}</span>
                                </div>
                                @if ($this->cart()->taxCents() > 0)
                                    <div class="flex items-center justify-between text-zinc-600">
                                        <span>{{ __('Tax') }}</span>
                                        <span>${{ number_format($this->cart()->taxCents() / 100, 2) }}</span>
                                    </div>
                                @endif
                                @if ($this->cart()->processingFeeCents() > 0)
                                    <div class="flex items-center justify-between text-zinc-600">
                                        <span>{{ __('Processing fee') }}</span>
                                        <span>${{ number_format($this->cart()->processingFeeCents() / 100, 2) }}</span>
                                    </div>
                                @endif
                                <div class="flex items-center justify-between pt-1 text-base font-semibold text-zinc-800">
                                    <span>{{ __('Total') }}</span>
                                    <span>${{ number_format($this->cart()->totalCents() / 100, 2) }}</span>
                                </div>
                            </div>
                            <flux:button
                                variant="primary"
                                class="mt-4 w-full !bg-store hover:!bg-store-hover !text-store-foreground"
                                x-on:click="open = false"
                            >
                                {{ __('Continue') }}
                            </flux:button>
                            <flux:modal.trigger name="confirm-start-over">
                                <button
                                    type="button"
                                    class="mt-3 w-full text-center text-xs text-zinc-400 underline hover:text-red-600"
                                >
                                    {{ __('Clear cart & start over') }}
                                </button>
                            </flux:modal.trigger>
                            </div>
                        @endif
                    @endif
                </div>
            </div>
        </div>
    </div>

    <flux:modal name="confirm-start-over" class="max-w-sm">
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">{{ __('Start over?') }}</flux:heading>
                <flux:subheading>
                    {{ __('This clears everything in your cart — package, container, urn, and keepsakes — so you can begin again.') }}
                </flux:subheading>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button variant="danger" wire:click="startOver">{{ __('Start over') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
