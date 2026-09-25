{{-- Re-themes the storefront in the funeral home's brand color: the exact color
     for buttons (the "store" colors in app.css) and a generated, contrast-safe
     scale in place of the platform's teal "brand" shades. --}}
@if ($store?->brandColor())
    <style>
        :root {
            --store-accent: {{ $store->brandColor() }};
            --store-accent-foreground: {{ $store->brandForegroundColor() }};
            @foreach ($store->brandPalette() as $shade => $color)
                --color-brand-{{ $shade }}: {{ $color }};
            @endforeach
        }
    </style>
@endif
