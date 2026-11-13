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

        // The frame is sized to its content, so this page scrolls instead of
        // the frame. Tell each frame how far its top is scrolled out of view,
        // and how much of it is on screen, so its sticky elements (like the
        // keepsake allowance) and overlays (like the image zoom) stay visible.
        var viewportFrame = null;

        function reportViewport() {
            viewportFrame = null;

            for (var k = 0; k < iframes.length; k++) {
                if (iframes[k].contentWindow) {
                    var rect = iframes[k].getBoundingClientRect();
                    var visibleTop = Math.max(0, -rect.top);
                    var visibleBottom = Math.min(rect.height, window.innerHeight - rect.top);

                    iframes[k].contentWindow.postMessage({
                        type: 'tm-cremation-store:viewport',
                        top: visibleTop,
                        height: Math.max(0, visibleBottom - visibleTop),
                    }, '*');
                }
            }
        }

        function scheduleViewportReport() {
            if (viewportFrame === null) {
                viewportFrame = window.requestAnimationFrame(reportViewport);
            }
        }

        // How much of the top of the window this page covers with its own
        // fixed or sticky header (or reserves via scroll-padding-top), so the
        // top of the store, where its cart button is, isn't left underneath.
        function coveredTop(iframe) {
            var covered = parseFloat(window.getComputedStyle(document.documentElement).scrollPaddingTop) || 0;

            if (!document.elementsFromPoint) {
                return covered;
            }

            var rect = iframe.getBoundingClientRect();
            var xs = [rect.left + 10, rect.left + rect.width / 2, rect.right - 10];

            for (var x = 0; x < xs.length; x++) {
                var stack = document.elementsFromPoint(Math.max(0, xs[x]), 1);

                for (var s = 0; s < stack.length; s++) {
                    for (var el = stack[s]; el && el !== document.body; el = el.parentElement) {
                        var position = window.getComputedStyle(el).position;

                        if ((position === 'fixed' || position === 'sticky') && !el.contains(iframe)) {
                            var box = el.getBoundingClientRect();

                            if (box.top <= 1) {
                                covered = Math.max(covered, box.bottom);
                            }
                        }
                    }
                }
            }

            return covered;
        }

        // A new checkout step began; if the visitor is scrolled past the top
        // of the store (or it's hidden under this page's header), bring it
        // back into view. Measured again once the scroll settles, because
        // some headers only slide in when the page scrolls up.
        function scrollToTopOf(iframe, isCorrection) {
            var offset = coveredTop(iframe);
            var top = iframe.getBoundingClientRect().top;

            if (top >= offset - 1) {
                return;
            }

            window.scrollTo({ top: window.pageYOffset + top - offset, behavior: 'smooth' });

            if (!isCorrection) {
                setTimeout(function () {
                    scrollToTopOf(iframe, true);
                }, 700);
            }
        }

        // The visitor made a choice and the store wants its next section
        // (top px below the frame's top) at the top of the window, just
        // below any header this page covers it with.
        function scrollToOffsetIn(iframe, top, isCorrection) {
            var target = window.pageYOffset + iframe.getBoundingClientRect().top + top - coveredTop(iframe) - 16;

            if (Math.abs(target - window.pageYOffset) < 2) {
                return;
            }

            window.scrollTo({ top: target, behavior: 'smooth' });

            if (!isCorrection) {
                setTimeout(function () {
                    scrollToOffsetIn(iframe, top, true);
                }, 700);
            }
        }

        // The store wants a spot (bottom px below the frame's top) brought
        // up into view, without scrolling past another spot (limit px below
        // the frame's top) so it stays below any header this page has.
        function revealIn(iframe, bottom, limit) {
            var frameTop = iframe.getBoundingClientRect().top;
            var overflow = frameTop + bottom + 16 - window.innerHeight;
            var by = Math.min(overflow, frameTop + limit - coveredTop(iframe) - 16);

            if (by > 0) {
                window.scrollTo({ top: window.pageYOffset + by, behavior: 'smooth' });
            }
        }

        window.addEventListener('scroll', scheduleViewportReport, { passive: true });
        window.addEventListener('resize', scheduleViewportReport);

        for (var m = 0; m < iframes.length; m++) {
            iframes[m].addEventListener('load', scheduleViewportReport);
        }

        window.addEventListener('message', function (event) {
            if (!event.data) {
                return;
            }

            for (var j = 0; j < iframes.length; j++) {
                // Only trust messages from one of *our own* iframes, never
                // just because the message claims the right "type".
                if (event.source !== iframes[j].contentWindow) {
                    continue;
                }

                if (event.data.type === 'tm-cremation-store:resize') {
                    // The content's own height, so the frame shrinks on short
                    // steps as well as growing on long ones.
                    iframes[j].style.minHeight = '0';
                    iframes[j].style.height = Math.ceil(event.data.height) + 'px';
                    scheduleViewportReport();
                } else if (event.data.type === 'tm-cremation-store:scroll-into-view') {
                    scrollToTopOf(iframes[j]);
                } else if (event.data.type === 'tm-cremation-store:scroll-to') {
                    scrollToOffsetIn(iframes[j], Math.max(0, Number(event.data.top) || 0));
                } else if (event.data.type === 'tm-cremation-store:reveal') {
                    revealIn(iframes[j], Number(event.data.bottom) || 0, Number(event.data.limit) || 0);
                }

                break;
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
