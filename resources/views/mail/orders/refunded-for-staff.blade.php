<x-mail::store-message :store-name="$frame['storeName']" :store-url="$frame['storeUrl']" :logo-url="$frame['logoUrl']" :footer="$frame['footer']">
# Refund issued for {{ $deceasedName }}

{{ $purchaserName }} has been refunded {{ $amount }} on order {{ $orderNumber }}, back to the card they paid with. The refund comes out of your Stripe balance.

<x-mail::table>
| | |
|:--|:--|
| **This refund** | {{ $amount }} |
| **Refunded so far** | {{ $refundedSoFar }} of {{ $total }} |
@if ($platformFeeReturned)
| **Platform fee returned to you** | {{ $platformFeeReturned }} |
@endif
| **Issued from** | {{ $issuedFrom }} |
@if ($reason)
| **Reason** | {{ $reason }} |
@endif
</x-mail::table>

<x-mail::button :url="$portalUrl">
View order in staff portal
</x-mail::button>

</x-mail::store-message>
