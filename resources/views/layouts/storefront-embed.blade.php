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
    <body class="bg-transparent text-zinc-900 antialiased">
        {{-- Everything the parent needs to make room for. The iframe's own
             document can never measure shorter than the iframe currently is,
             so the height is taken from this wrapper instead (flow-root keeps
             children's margins inside it). No min-h-screen on the body for
             the same reason: it would pin the height to the iframe's. --}}
        <div id="tm-embed-content" class="flow-root">
            {{ $slot }}

            @if ($store?->generalPriceListUrl())
                <p class="pb-4 text-center text-xs text-zinc-500">
                    <a href="{{ $store->generalPriceListUrl() }}" target="_blank" rel="noopener" class="underline hover:text-brand-700">{{ __('View our General Price List') }}</a>
                </p>
            @endif
        </div>

        @fluxScripts

        {{-- Tells the parent page (via embed.js) how tall we are, so it can
             size its iframe to fit — the wizard's height changes at every
             step, and there's no other way for the parent to know that
             across origins. Also asks it to bring the top of the store into
             view when the checkout moves to a new step. See public/embed.js
             for the receiving side. --}}
        <script>
            (function () {
                if (window.self === window.top) {
                    return; // not embedded in an iframe — nothing to report.
                }

                var lastHeight = 0;

                function content() {
                    return document.getElementById('tm-embed-content');
                }

                function reportHeight() {
                    var element = content();

                    if (!element) {
                        return;
                    }

                    var height = Math.ceil(element.getBoundingClientRect().height);

                    if (height === lastHeight) {
                        return;
                    }

                    lastHeight = height;
                    window.parent.postMessage({ type: 'tm-cremation-store:resize', height: height }, '*');
                }

                var observer = null;

                function observe() {
                    if (!('ResizeObserver' in window) || !content()) {
                        return;
                    }

                    observer = observer || new ResizeObserver(reportHeight);
                    observer.disconnect();
                    observer.observe(content());
                }

                if (!('ResizeObserver' in window)) {
                    window.addEventListener('resize', reportHeight);
                    setInterval(reportHeight, 500);
                }

                window.addEventListener('tm-cremation-store:step-changed', function () {
                    window.parent.postMessage({ type: 'tm-cremation-store:scroll-into-view' }, '*');
                });

                // wire:navigate swaps the body, so observe the new wrapper too.
                document.addEventListener('livewire:navigated', function () {
                    lastHeight = 0;
                    observe();
                    reportHeight();
                });
                window.addEventListener('load', reportHeight);
                observe();
                reportHeight();
            })();
        </script>
    </body>
</html>
