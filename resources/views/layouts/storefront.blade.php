@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head', ['lockLightMode' => true])

        <script src="https://js.stripe.com/v3/"></script>

        @if ($store = \App\Models\Store::current())
            @if ($store->brand_primary_color)
                <style>
                    :root {
                        --store-accent: {{ $store->brand_primary_color }};
                    }
                </style>
            @endif
        @endif
    </head>
    <body class="min-h-screen bg-brand-50/40 text-zinc-900 antialiased">
        <div class="flex min-h-screen flex-col">
            <header class="border-b border-brand-100 bg-white">
                <div class="mx-auto flex max-w-5xl items-center justify-between gap-4 px-4 py-4 sm:px-6">
                    <a href="{{ route('storefront.start', ['store' => $store?->slug]) }}" wire:navigate class="flex items-center gap-3">
                        <span class="flex size-10 items-center justify-center rounded-full bg-brand-700 text-sm font-semibold text-white">
                            {{ $store ? Illuminate\Support\Str::of($store->name)->substr(0, 1) : 'TM' }}
                        </span>
                        <span class="flex flex-col leading-tight">
                            <span class="font-serif text-lg font-medium text-brand-900">{{ $store?->name ?? config('app.name') }}</span>
                            <span class="text-xs text-zinc-500">{{ __('Cremation & memorial planning') }}</span>
                        </span>
                    </a>

                    <div class="flex items-center gap-4">
                        @if ($store?->contact_phone)
                            <a href="tel:{{ $store->contact_phone }}" class="hidden text-sm text-zinc-600 hover:text-brand-700 sm:block">
                                {{ __('Need help?') }} <span class="font-medium">{{ $store->contact_phone }}</span>
                            </a>
                        @endif

                        <livewire:storefront.cart-drawer :store="$store" />
                    </div>
                </div>
            </header>

            <main class="flex-1">
                {{ $slot }}
            </main>

            <footer class="border-t border-brand-100 bg-white py-6 text-center text-xs text-zinc-500">
                <p>&copy; {{ now()->year }} {{ $store?->name ?? config('app.name') }}. {{ __('All arrangements handled with care.') }}</p>
                @if ($store?->generalPriceListUrl())
                    <p class="mt-1">
                        <a href="{{ $store->generalPriceListUrl() }}" target="_blank" rel="noopener" class="underline hover:text-brand-700">{{ __('View our General Price List') }}</a>
                    </p>
                @endif
                <p class="mt-1 text-zinc-400">{{ __('Powered by') }} {{ config('app.name') }}</p>
            </footer>
        </div>

        @fluxScripts
    </body>
</html>
