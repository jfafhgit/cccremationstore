<x-mail::store-message :store-name="$frame['storeName']" :store-url="$frame['storeUrl']" :logo-url="$frame['logoUrl']" :footer="$frame['footer']">
# Thank you, {{ $purchaserFirstName }}

@if ($isImmediate)
We are sorry for your loss. Your payment for {{ $deceasedName }}'s arrangements with {{ $storeName }} has been received, and our team has been notified.
@else
Your payment for {{ $deceasedName }}'s arrangements with {{ $storeName }} has been received, and our team has been notified.
@endif

<x-mail::panel>
@if ($usesExternalForm)
**One more step, when you're ready.** Please complete the Vital Statistics form on our website: the information we need for official records, such as the death certificate.
@else
**One more step, when you're ready.** Please complete the Vital Statistics form: the information we need for official records, such as the death certificate. You can save your progress and come back to it anytime — this link doesn't expire.
@endif
</x-mail::panel>

<x-mail::button :url="$detailsUrl">
Complete Vital Statistics
</x-mail::button>

@include('mail.orders.summary')

## Questions?
@if ($canReply && $contactPhone)
We are here to help. Reply to this email or call us at {{ $contactPhone }}.
@elseif ($canReply)
We are here to help. Just reply to this email.
@elseif ($contactPhone)
We are here to help. Call us at {{ $contactPhone }}.
@else
We are here to help. Please contact {{ $storeName }} directly.
@endif

With care,<br>
The {{ $storeName }} Team

</x-mail::store-message>
