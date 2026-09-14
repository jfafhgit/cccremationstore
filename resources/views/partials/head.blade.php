<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])

@if ($lockLightMode ?? false)
    {{-- The public storefront/marketing pages don't offer dark mode, so we
         skip @fluxAppearance entirely here: that directive is what reads
         the visitor's OS preference (or a stored choice) and adds the
         ".dark" class to <html>. Without it, ".dark" is never applied and
         nothing on these pages can render in dark styles. --}}
    <meta name="color-scheme" content="light">
@else
    @fluxAppearance
@endif
