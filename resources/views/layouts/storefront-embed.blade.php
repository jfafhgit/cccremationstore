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
            {{-- Shown in place of the store when the browser won't keep our
                 session cookie inside another site's page (see the script below). --}}
            <div id="tm-cookie-fallback" hidden class="mx-auto my-10 max-w-xl px-4 sm:px-6">
                <div class="rounded-2xl border border-brand-100 bg-white p-6 text-center shadow-sm sm:p-8">
                    <flux:heading size="lg" class="font-serif">{{ __('Please open our store in its own window') }}</flux:heading>
                    <flux:text class="mt-2">{{ __('Your browser\'s privacy settings don\'t let our store save your selections while it\'s shown inside this page. Everything works normally in a new window.') }}</flux:text>
                    <a href="{{ route('storefront.start', ['store' => $store?->slug]) }}" target="_blank" rel="noopener" class="mt-5 inline-block rounded-lg bg-store px-5 py-3 text-sm font-semibold text-store-foreground transition hover:bg-store-hover">
                        {{ __('Open our store in a new window') }}
                    </a>
                    @if ($store?->contact_phone)
                        <flux:text class="mt-4 text-xs">{{ __('Or call us at :phone.', ['phone' => $store->contact_phone]) }}</flux:text>
                    @endif
                </div>
            </div>

            <div id="tm-embed-app">
                {{ $slot }}
            </div>

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

                // The parent page does the scrolling, so sticky elements here
                // never move on their own. embed.js reports how far this frame's
                // top is scrolled above the visitor's view; sticky elements
                // offset their "top" by it to stay on screen.
                window.addEventListener('message', function (event) {
                    if (event.source !== window.parent || !event.data || event.data.type !== 'tm-cremation-store:viewport') {
                        return;
                    }

                    var top = Math.max(0, Number(event.data.top) || 0);
                    document.documentElement.style.setProperty('--tm-viewport-top', top + 'px');
                });
            })();

            // Some browsers (notably Safari) block cookies for a site shown in
            // another site's iframe, and the cart can't work without one. Ask
            // the server which session this browser actually sent back: a
            // different token than the page was rendered with means the
            // cookie was dropped, so offer the store in its own window.
            (function () {
                if (window.self === window.top || !window.fetch) {
                    return;
                }

                var renderedToken = @js(csrf_token());

                fetch(@js(route('storefront.embed.session-check', ['store' => $store?->slug])), {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                })
                    .then(function (response) {
                        return response.ok ? response.json() : null;
                    })
                    .then(function (data) {
                        if (!data || data.token === renderedToken) {
                            return;
                        }

                        document.getElementById('tm-cookie-fallback').hidden = false;
                        document.getElementById('tm-embed-app').hidden = true;
                    })
                    .catch(function () {
                        // A network hiccup isn't evidence of blocked cookies.
                    });
            })();
        </script>
    </body>
</html>
