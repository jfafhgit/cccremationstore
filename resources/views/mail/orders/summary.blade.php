{{-- Line items and totals shared by the customer and staff order emails. Every value arrives pre-escaped by FormatsOrderForMail. --}}
**Order {{ $summary['orderNumber'] }}**@if ($summary['paidAt'])<br>Paid {{ $summary['paidAt'] }}@endif

<x-mail::table>
| Item | Qty | Amount |
|:-----|:---:|-------:|
@foreach ($summary['items'] as $item)
| {{ $item['name'] }}@if ($item['variant']) — {{ $item['variant'] }}@endif | {{ $item['quantity'] }} | {{ $item['total'] }} |
@endforeach
| Subtotal | | {{ $summary['subtotal'] }} |
@if ($summary['tax'])
| Sales tax | | {{ $summary['tax'] }} |
@endif
@if ($summary['processingFee'])
| Processing fee | | {{ $summary['processingFee'] }} |
@endif
| **Total paid** | | **{{ $summary['total'] }}** |
</x-mail::table>
