@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-zinc-50 text-zinc-900 antialiased dark:bg-zinc-900 dark:text-zinc-100">
        @php($store = \App\Models\Store::current())
        @auth('store')
            @php($storeUser = auth('store')->user())
            @php($isOwner = $store && $storeUser->isOwnerOf($store))
            @php($otherLocations = $store ? $storeUser->stores()->active()->whereKeyNot($store->id)->orderBy('name')->get() : collect())
            <div class="flex min-h-screen flex-col">
                <header class="border-b border-brand-100 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-4 px-4 py-4 sm:px-6">
                        <div class="flex items-center gap-3">
                            <x-portal-store-mark :store="$store" :subtitle="__('Staff portal')" />

                            @if ($otherLocations->isNotEmpty())
                                <flux:dropdown>
                                    <flux:button size="sm" variant="ghost" icon:trailing="chevron-down">{{ __('Switch location') }}</flux:button>
                                    <flux:menu>
                                        @foreach ($otherLocations as $location)
                                            <form method="POST" action="{{ route('portal.switch-location', ['location' => $location->slug]) }}" class="w-full">
                                                @csrf
                                                <flux:menu.item as="button" type="submit" class="w-full cursor-pointer">{{ $location->name }}</flux:menu.item>
                                            </form>
                                        @endforeach
                                    </flux:menu>
                                </flux:dropdown>
                            @endif
                        </div>

                        <nav class="flex flex-wrap items-center gap-4 text-sm">
                            <flux:link :href="route('portal.orders')" wire:navigate>{{ __('Orders') }}</flux:link>
                            <flux:link :href="route('portal.leads')" wire:navigate>{{ __('Incomplete orders') }}</flux:link>
                            @if ($isOwner)
                                <flux:link :href="route('portal.payments')" wire:navigate>
                                    {{ __('Payments') }}
                                    @unless ($store->isStripeReady())
                                        <span class="ml-1 inline-block size-2 rounded-full bg-red-500" aria-label="{{ __('Needs attention') }}"></span>
                                    @endunless
                                </flux:link>
                            @endif
                            @if ($store?->platform_fee_model === \App\Enums\PlatformFeeModel::Subscription || $store?->stripe_customer_id)
                                <flux:link :href="route('portal.billing')" wire:navigate>
                                    {{ __('Billing') }}
                                    @if ($store->isSubscriptionPastDue() || $store->needsSubscriptionSetup())
                                        <span class="ml-1 inline-block size-2 rounded-full bg-red-500" aria-label="{{ __('Needs attention') }}"></span>
                                    @endif
                                </flux:link>
                            @endif
                            <span class="text-zinc-300 dark:text-zinc-700">|</span>
                            <span class="text-zinc-500 dark:text-zinc-400">{{ $storeUser->name }}</span>
                            <form method="POST" action="{{ route('portal.logout') }}">
                                @csrf
                                <flux:button size="sm" variant="ghost" type="submit">{{ __('Log out') }}</flux:button>
                            </form>
                        </nav>
                    </div>
                </header>

                <main class="mx-auto w-full max-w-5xl flex-1 px-4 py-8 sm:px-6">
                    {{ $slot }}
                </main>
            </div>
        @else
            <main class="flex min-h-screen items-center justify-center px-4">
                {{ $slot }}
            </main>
        @endauth

        @fluxScripts
    </body>
</html>
