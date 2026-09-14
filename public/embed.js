/**
 * Planning by Treasured Memories — embeddable storefront widget.
 *
 * Drop this on a funeral home's own website to show their cremation store
 * inline, without opening a new window/tab:
 *
 *   <div data-tm-cremation-store="your-store-slug"></div>
 *   <script src="https://YOUR-DOMAIN/embed.js" async></script>
 *
 * Why an iframe under the hood rather than injecting our markup directly:
 * the storefront is a full Livewire application (session-backed cart,
 * server round-trips, inline Stripe payment fields) running on our domain,
 * not the funeral home's. Browsers do not allow one origin's JavaScript to
 * safely execute another origin's app in the same document — that's a
 * security boundary, not a technical inconvenience — so a same-origin
 * <script> snippet cannot "run" our storefront directly on your page. An
 * iframe is the standard, secure way to embed another origin's interactive
 * app. This snippet just makes that iframe behave like part of your page:
 * it's borderless, full-width, and automatically resizes itself to fit
 * whatever step of the checkout the visitor is on (see the matching
 * postMessage listener below, and resources/views/layouts/storefront-embed
 * .blade.php for the side that reports its own height).
 */
(function () {
    'use strict';

    var ATTR = 'data-tm-cremation-store';

    function resolveOrigin() {
        var current = document.currentScript;

        if (!current) {
            // Fallback for browsers/loaders that don't set currentScript
            // (e.g. the snippet was injected dynamically): use the last
            // script tag that points at this file.
            var scripts = document.getElementsByTagName('script');
            for (var i = scripts.length - 1; i >= 0; i--) {
                if (scripts[i].src && scripts[i].src.indexOf('embed.js') !== -1) {
                    current = scripts[i];
                    break;
                }
            }
        }

        if (!current || !current.src) {
            return null;
        }

        var link = document.createElement('a');
        link.href = current.src;

        return {
            protocol: link.protocol,
            // The root domain, stripped of any "www." — store subdomains
            // are siblings of whatever host this script is served from.
            rootHost: link.host,
        };
    }

    function buildIframe(container, storeSlug, origin) {
        var iframe = document.createElement('iframe');
        var storeUrl = origin.protocol + '//' + storeSlug + '.' + origin.rootHost + '/embed';

        iframe.src = storeUrl;
        iframe.title = 'Cremation & memorial planning';
        iframe.style.width = '100%';
        iframe.style.border = '0';
        iframe.style.minHeight = '600px';
        iframe.style.display = 'block';
        iframe.setAttribute('scrolling', 'no');
        iframe.setAttribute('loading', 'lazy');

        container.appendChild(iframe);

        return iframe;
    }

    function init() {
        var origin = resolveOrigin();

        if (!origin) {
            return;
        }

        var containers = document.querySelectorAll('[' + ATTR + ']');
        var iframes = [];

        for (var i = 0; i < containers.length; i++) {
            var container = containers[i];
            var slug = container.getAttribute(ATTR);

            if (!slug) {
                continue;
            }

            iframes.push(buildIframe(container, slug, origin));
        }

        if (iframes.length === 0) {
            return;
        }

        window.addEventListener('message', function (event) {
            if (!event.data || event.data.type !== 'tm-cremation-store:resize') {
                return;
            }

            for (var j = 0; j < iframes.length; j++) {
                // Only trust a height reported by one of *our own* iframes,
                // never just because the message claims the right "type".
                if (event.source === iframes[j].contentWindow) {
                    iframes[j].style.height = Math.ceil(event.data.height) + 'px';
                    break;
                }
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
