@props(['storeName', 'storeUrl', 'logoUrl' => null, 'footer'])
<x-mail::layout>
{{-- Header --}}
<x-slot:header>
{{ $storeName }}
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Footer --}}
<x-slot:footer>
{{ $footer }}
</x-slot:footer>
</x-mail::layout>
