@props(['src' => null, 'category' => 'package', 'alt' => ''])

@if ($src)
    <img src="{{ $src }}" alt="{{ $alt }}" {{ $attributes->class(['object-cover']) }}>
@else
    <div {{ $attributes->class(['flex items-center justify-center bg-gradient-to-br from-brand-50 to-brand-100/60 text-brand-300']) }} role="img" aria-label="{{ $alt ?: __('No image available') }}">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round" class="size-1/2 max-h-16 max-w-16 min-h-8 min-w-8" aria-hidden="true">
            @switch($category)
                @case('container')
                    <path d="M4 9l3-4h10l3 4v8l-3 2H7l-3-2z" />
                    <path d="M4 9h16" />
                    <path d="M12 9v10" />
                    @break
                @case('urn')
                    <path d="M9 3h6" />
                    <path d="M10 3v3M14 3v3" />
                    <path d="M8.5 6h7c1 1.5 3.5 3 3.5 7 0 4-3 7-7 7s-7-3-7-7c0-4 2.5-5.5 3.5-7z" />
                    <path d="M8 21h8" />
                    @break
                @case('keepsake')
                    <path d="M12 20s-7-4.5-7-10a4 4 0 0 1 7-2.5A4 4 0 0 1 19 10c0 5.5-7 10-7 10z" />
                    @break
                @case('addon')
                @case('service')
                    <path d="M7 3h7l4 4v14H7z" />
                    <path d="M14 3v4h4" />
                    <path d="M10 12h5M10 16h5" />
                    @break
                @default
                    <path d="M3 8h18v4H3z" />
                    <path d="M5 12v8h14v-8" />
                    <path d="M12 8v12" />
                    <path d="M12 8C11 5 7 5 7 7s3 1 5 1zM12 8c1-3 5-3 5-1s-3 1-5 1z" />
            @endswitch
        </svg>
    </div>
@endif
