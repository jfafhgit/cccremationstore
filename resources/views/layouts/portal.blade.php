@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-zinc-50 text-zinc-900 antialiased dark:bg-zinc-900 dark:text-zinc-100">
        @php($store = \App\Models\Store::current())
        @auth('store')
            <div class="flex min-h-screen flex-col">
                <header class="border-b border-brand-100 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="mx-auto flex max-w-5xl items-center justify-between gap-4 px-4 py-4 sm:px-6">
                        <div class="flex items-center gap-3">
                            <span class="flex size-9 items-center justify-center rounded-full bg-brand-700 text-sm font-semibold text-white">
                                {{ Illuminate\Support\Str::of($store?->name ?? 'TM')->substr(0, 1) }}
                            </span>
                            <div class="flex flex-col leading-tight">
                                <span class="font-serif text-base font-medium text-brand-900 dark:text-brand-100">{{ $store?->name }}</span>
                                <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Staff portal') }}</span>
                            </div>
                        </div>

                        <nav class="flex items-center gap-4 text-sm">
                            <flux:link :href="route('portal.orders')" wire:navigate>{{ __('Orders') }}</flux:link>
                            <flux:link :href="route('portal.leads')" wire:navigate>{{ __('Incomplete orders') }}</flux:link>
                            <span class="text-zinc-300 dark:text-zinc-700">|</span>
                            <span class="text-zinc-500 dark:text-zinc-400">{{ auth('store')->user()->name }}</span>
                            <form method="POST" action="{{ route('portal.logout') }}">
                                @csrf
                                <flux:button size="sm" variant="ghost" type="submit">{{ __('Log out') }}</flux:button>
                            </form>
                        </nav>
                    </div>
                </header>

                <main class="mx-auto w-full max-w-5xl flex-1 px-4 py-8 sm:px-6">
                    {{ $slot }}
                </main>
            </div>
        @else
            <main class="flex min-h-screen items-center justify-center px-4">
                {{ $slot }}
            </main>
        @endauth

        @fluxScripts
    </body>
</html>
