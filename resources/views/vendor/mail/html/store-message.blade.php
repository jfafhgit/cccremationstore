{{--
    Like <x-mail::message>, but headed by the funeral home (its logo, or its
    name) instead of the platform, for the order emails. $storeName is plain
    text; $footer is Markdown and must already be escaped.
--}}
@props(['storeName', 'storeUrl', 'logoUrl' => null, 'footer'])
<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<tr>
<td class="header">
<a href="{{ $storeUrl }}" style="display: inline-block;">
@if ($logoUrl)
<img src="{{ $logoUrl }}" alt="{{ $storeName }}" style="max-height: 64px; max-width: 280px; border: 0;">
@else
<span style="font-family: Georgia, 'Times New Roman', serif; font-size: 26px; font-weight: normal; color: #18181b;">{{ $storeName }}</span>
@endif
</a>
</td>
</tr>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
{{ $footer }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
