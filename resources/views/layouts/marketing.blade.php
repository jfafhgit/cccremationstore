@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head', ['lockLightMode' => true, 'fonts' => ['instrument-sans', 'eb-garamond']])
    </head>
    <body class="theme-gold min-h-screen bg-white text-zinc-900 antialiased">
        <div class="flex min-h-screen flex-col">
            <header class="border-b-2 border-gold-500 bg-black">
                <div class="mx-auto flex max-w-5xl items-center justify-between gap-4 px-4 py-3 sm:px-6">
                    <a href="{{ route('home') }}" class="shrink-0">
                        <img src="{{ asset('images/treasured-memories-logo.webp') }}" alt="{{ config('app.name') }}" class="h-11 w-auto sm:h-14" />
                    </a>
                    <div class="flex shrink-0 items-center gap-2">
                        <flux:button href="{{ route('login') }}" variant="ghost" class="!text-white hover:!bg-white/10 hover:!text-gold-300">
                            {{ __('Log in') }}
                        </flux:button>
                        <flux:button href="#contact" variant="primary" class="bg-linear-to-b from-gold-300 to-gold-500 hover:from-gold-200 hover:to-gold-400">
                            {{ __('Request a demo') }}
                        </flux:button>
                    </div>
                </div>
            </header>

            <main class="flex-1">
                {{ $slot }}
            </main>

            <footer class="border-t-2 border-gold-500 bg-black py-6 text-center text-xs text-zinc-400">
                <p>&copy; {{ now()->year }} {{ config('app.name') }}. {{ __('All rights reserved.') }}</p>
            </footer>
        </div>

        @fluxScripts
    </body>
</html>
