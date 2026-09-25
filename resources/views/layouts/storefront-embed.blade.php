@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head', ['lockLightMode' => true])

        <script src="https://js.stripe.com/v3/"></script>

        @include('partials.store-brand-colors', ['store' => $store = \App\Models\Store::current()])

        {{-- No header/footer chrome here on purpose: this page is loaded
             inside another site's page (via an auto-resizing iframe), so
             our own branding chrome would just duplicate the host site's. --}}
        <style>
            html, body {
                background: transparent;
            }
        </style>
    </head>
    <body class="min-h-screen bg-transparent text-zinc-900 antialiased">
        {{ $slot }}

        @if ($store?->generalPriceListUrl())
            <p class="pb-4 text-center text-xs text-zinc-500">
                <a href="{{ $store->generalPriceListUrl() }}" target="_blank" rel="noopener" class="underline hover:text-brand-700">{{ __('View our General Price List') }}</a>
            </p>
        @endif

        @fluxScripts

        {{-- Tells the parent page (via embed.js) how tall we are, so it can
             size its iframe to fit — the wizard's height changes at every
             step, and there's no other way for the parent to know that
             across origins. See public/embed.js for the receiving side. --}}
        <script>
            (function () {
                if (window.self === window.top) {
                    return; // not embedded in an iframe — nothing to report.
                }

                function reportHeight() {
                    window.parent.postMessage({
                        type: 'tm-cremation-store:resize',
                        height: document.documentElement.scrollHeight,
                    }, '*');
                }

                if ('ResizeObserver' in window) {
                    new ResizeObserver(reportHeight).observe(document.documentElement);
                } else {
                    window.addEventListener('resize', reportHeight);
                    setInterval(reportHeight, 1000);
                }

                document.addEventListener('livewire:navigated', reportHeight);
                window.addEventListener('load', reportHeight);
                reportHeight();
            })();
        </script>
    </body>
</html>
