<x-mail::store-message :store-name="$frame['storeName']" :store-url="$frame['storeUrl']" :logo-url="$frame['logoUrl']" :footer="$frame['footer']">
# Your refund is on its way

Hello {{ $purchaserFirstName }},

{{ $storeName }} has refunded **{{ $amount }}** to the card you paid with for order {{ $orderNumber }}. Refunds usually appear on your statement within 5 to 10 business days, depending on your bank.

@if ($isPartial)
<x-mail::table>
| | |
|:--|:--|
| **This refund** | {{ $amount }} |
| **Refunded so far** | {{ $refundedSoFar }} of {{ $total }} |
</x-mail::table>
@endif

## Questions?
@if ($canReply && $contactPhone)
Reply to this email or call us at {{ $contactPhone }}.
@elseif ($canReply)
Just reply to this email.
@elseif ($contactPhone)
Call us at {{ $contactPhone }}.
@else
Please contact {{ $storeName }} directly.
@endif

With care,<br>
The {{ $storeName }} Team

</x-mail::store-message>
