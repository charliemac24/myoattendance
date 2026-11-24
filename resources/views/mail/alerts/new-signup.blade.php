<x-mail::message>
# New sign-up

**{{ $details['organization'] }}** signed up and started its free trial.

<x-mail::table>
| | |
|:--|:--|
| Type | {{ $details['type'] }} |
| Owner | {{ $details['owner'] }}{{ $details['owner_email'] ? ' · '.$details['owner_email'] : '' }} |
@if ($details['trial_ends'])
| Trial ends | {{ $details['trial_ends'] }} |
@endif
| SMS answers | {{ $details['answers'] ? 'Yes' : 'No' }} |
@if ($details['answers'])
| People | {{ number_format($details['people']) }} |
| Texts per person per day | {{ $details['texts_per_day'] }} |
| Days | {{ $details['days'] }} |
| SMS estimate | {{ $details['estimate'] }} |
@endif
</x-mail::table>

<x-mail::button :url="$details['url']">
Open the school
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
