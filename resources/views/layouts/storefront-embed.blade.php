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
