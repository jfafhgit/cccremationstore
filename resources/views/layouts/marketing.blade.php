@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head', ['lockLightMode' => true])
    </head>
    <body class="min-h-screen bg-brand-50/40 text-zinc-900 antialiased">
        <div class="flex min-h-screen flex-col">
            <header class="border-b border-brand-100 bg-white/90 backdrop-blur">
                <div class="mx-auto flex max-w-5xl items-center justify-between gap-4 px-4 py-4 sm:px-6">
                    <a href="{{ route('home') }}" class="flex items-center gap-3">
                        <span class="flex size-10 items-center justify-center rounded-full bg-brand-700 text-sm font-semibold text-white">TM</span>
                        <span class="flex flex-col leading-tight">
                            <span class="font-serif text-lg font-medium text-brand-900">{{ config('app.name') }}</span>
                            <span class="text-xs text-zinc-500">{{ __('Online cremation & memorial planning for funeral homes') }}</span>
                        </span>
                    </a>

                    <flux:button href="#contact" variant="primary" class="!bg-brand-700 hover:!bg-brand-800">
                        {{ __('Request a demo') }}
                    </flux:button>
                </div>
            </header>

            <main class="flex-1">
                {{ $slot }}
            </main>

            <footer class="border-t border-brand-100 bg-white py-6 text-center text-xs text-zinc-500">
                <p>&copy; {{ now()->year }} {{ config('app.name') }}. {{ __('All rights reserved.') }}</p>
            </footer>
        </div>

        @fluxScripts
    </body>
</html>
