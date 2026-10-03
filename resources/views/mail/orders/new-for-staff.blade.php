<x-mail::store-message :store-name="$frame['storeName']" :store-url="$frame['storeUrl']" :logo-url="$frame['logoUrl']" :footer="$frame['footer']">
# New order for {{ $deceasedName }}

A family just completed a purchase on your {{ $storeName }} online store.

<x-mail::table>
| | |
|:--|:--|
| **Purchaser** | {{ $purchaserName }}@if ($relationship) ({{ $relationship }})@endif |
| **Email** | {{ $purchaserEmail }} |
@if ($purchaserPhone)
| **Phone** | {{ $purchaserPhone }} |
@endif
| **For** | {{ $deceasedName }} |
@if ($timing)
| **Timing** | {{ $timing }} |
@endif
</x-mail::table>

@include('mail.orders.summary')

<x-mail::button :url="$portalUrl">
View order in staff portal
</x-mail::button>

@if ($usesExternalForm)
The family has been sent to the Vital Statistics form on your website. Reply to this email to reach the family directly.
@else
The family has been sent a link to complete the Vital Statistics form. You'll get another email once they submit it. Reply to this email to reach the family directly.
@endif

</x-mail::store-message>
