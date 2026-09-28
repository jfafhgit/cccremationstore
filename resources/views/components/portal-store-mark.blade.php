@props(['store', 'subtitle' => null])

{{-- The location's logo when it has one, otherwise its initial and name. --}}
<div {{ $attributes->class('flex items-center gap-3') }}>
    @if ($store?->brandLogoUrl())
        <img src="{{ $store->brandLogoUrl() }}" alt="{{ $store->name }}" class="h-10 max-w-48 object-contain" />
    @else
        <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-brand-700 text-sm font-semibold text-white">
            {{ Illuminate\Support\Str::of($store?->name ?? 'TM')->substr(0, 1) }}
        </span>
        <span class="font-serif text-base font-medium leading-tight text-brand-900 dark:text-brand-100">{{ $store?->name }}</span>
    @endif
    @if ($subtitle)
        <span class="border-l border-zinc-200 pl-3 text-xs text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">{{ $subtitle }}</span>
    @endif
</div>
