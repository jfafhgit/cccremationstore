<x-mail::store-message :store-name="$frame['storeName']" :store-url="$frame['storeUrl']" :logo-url="$frame['logoUrl']" :footer="$frame['footer']">
# Vital Statistics {{ $isUpdate ? 'updated' : 'received' }} for {{ $deceasedName }}

@if ($isUpdate)
{{ $purchaserName }} made changes to the Vital Statistics for order {{ $orderNumber }}. Here is everything as it stands now.
@else
{{ $purchaserName }} completed the Vital Statistics for order {{ $orderNumber }}.
@endif

@foreach ($alerts as $alert)
<x-mail::panel>
**{{ $alert['title'] }}** {{ $alert['body'] }}
</x-mail::panel>
@endforeach

@foreach ($sections as $heading => $answers)
## {{ $heading }}
<x-mail::table>
| | |
|:--|:--|
@foreach ($answers as $label => $value)
| **{{ $label }}** | {{ $value }} |
@endforeach
</x-mail::table>

@endforeach
@if ($obituary)
## Memorial Story
{{ $obituary }}

@endif
@if ($servicePreferences)
## Service preferences
{{ $servicePreferences }}

@endif
@if ($additionalNotes)
## Anything else
{{ $additionalNotes }}

@endif
<x-mail::button :url="$portalUrl">
View order in staff portal
</x-mail::button>

For privacy, the Social Security number is never emailed in full. See it in the staff portal. Reply to this email to reach the family directly.

</x-mail::store-message>
