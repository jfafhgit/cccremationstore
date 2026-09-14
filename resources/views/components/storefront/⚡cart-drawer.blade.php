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

    public function removeLine(string $key): void
    {
        $this->cart()->removeAny($key);
        $this->dispatch('cart-updated');
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

<div>
    <button
        type="button"
        wire:click="$toggle('open')"
        class="relative flex size-10 items-center justify-center rounded-full border border-brand-200 bg-white text-brand-800 transition hover:bg-brand-50"
        aria-label="{{ __('Open cart') }}"
    >
        <flux:icon.shopping-bag class="size-5" />
        @if ($store && $this->cart()->itemCount() > 0)
            <span class="absolute -right-1 -top-1 flex size-5 items-center justify-center rounded-full bg-brand-700 text-[10px] font-semibold text-white">
                {{ $this->cart()->itemCount() }}
            </span>
        @endif
    </button>

    @if ($open)
        <div class="fixed inset-0 z-50 flex justify-end" role="dialog" aria-modal="true">
            <div class="absolute inset-0 bg-zinc-900/40" wire:click="$set('open', false)"></div>

            <div class="relative flex h-full w-full flex-col bg-white shadow-xl sm:max-w-2xl">
                <div class="flex items-center justify-between border-b border-zinc-100 px-6 py-5">
                    <flux:heading size="lg">{{ __('Your selections') }}</flux:heading>
                    <flux:button variant="ghost" icon="x-mark" wire:click="$set('open', false)" aria-label="{{ __('Close cart') }}" />
                </div>

                <div class="flex-1 overflow-y-auto px-6 py-5">
                    @if ($store && $this->cart()->isEmpty())
                        <p class="text-sm text-zinc-500">{{ __("You haven't selected anything yet.") }}</p>
                    @elseif ($store)
                        <ul class="space-y-4">
                            @foreach ($this->cart()->allLines() as $key => $line)
                                <li class="flex items-start gap-4 border-b border-zinc-100 pb-4" wire:key="cart-line-{{ $key }}">
                                    @if ($imageUrl = Product::imageUrlFor($line['image_path'] ?? null))
                                        <img src="{{ $imageUrl }}" alt="" class="size-20 shrink-0 rounded-lg object-cover">
                                    @else
                                        <div class="flex size-20 shrink-0 items-center justify-center rounded-lg bg-zinc-100 text-zinc-300">
                                            <flux:icon.photo class="size-6" />
                                        </div>
                                    @endif

                                    <div class="min-w-0 flex-1">
                                        <p class="font-medium text-zinc-800">{{ $line['name'] }}</p>
                                        @if ($line['variant_name'] ?? null)
                                            <p class="text-xs text-zinc-500">{{ $line['variant_name'] }}</p>
                                        @endif
                                        <p class="mt-1 text-sm text-zinc-500">
                                            ${{ number_format($line['unit_price_cents'] / 100, 2) }} {{ __('each') }}
                                        </p>

                                        <div class="mt-3 flex items-center justify-between">
                                            @if ($this->isSlot($key))
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

                                            <button type="button" wire:click="removeLine('{{ $key }}')" class="text-xs text-zinc-400 underline hover:text-red-600">
                                                {{ __('Remove') }}
                                            </button>
                                        </div>
                                    </div>

                                    <span class="shrink-0 text-sm font-semibold text-zinc-800">
                                        ${{ number_format(($line['unit_price_cents'] * $line['quantity']) / 100, 2) }}
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                @if ($store && ! $this->cart()->isEmpty())
                    <div class="border-t border-zinc-100 px-6 py-5">
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
                            <div class="flex items-center justify-between pt-1 text-base font-semibold text-zinc-800">
                                <span>{{ __('Total') }}</span>
                                <span>${{ number_format($this->cart()->totalCents() / 100, 2) }}</span>
                            </div>
                        </div>
                        <flux:button
                            variant="primary"
                            class="mt-4 w-full !bg-brand-700 hover:!bg-brand-800"
                            href="{{ route('storefront.start', ['store' => $store->slug]) }}"
                            wire:navigate
                            wire:click="$set('open', false)"
                        >
                            {{ __('Continue') }}
                        </flux:button>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
