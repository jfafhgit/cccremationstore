@props([
    'sidebar' => false,
])

{{-- Light mode uses the dark-lettered logo; dark mode uses the white-lettered one. --}}
<a {{ $attributes->class(['flex shrink-0 items-center', 'me-auto' => $sidebar, 'me-4' => ! $sidebar]) }}>
    <img src="{{ asset('images/treasured-memories-logo-light.png') }}" alt="{{ config('app.name') }}" class="h-14 w-auto dark:hidden" />
    <img src="{{ asset('images/treasured-memories-logo.webp') }}" alt="{{ config('app.name') }}" class="hidden h-14 w-auto dark:block" />
</a>
