<x-mail::message>
# SMS price requested

**{{ $details['organization'] }}** asked for its custom SMS price{{ $details['requested_by'] ? ' ('.$details['requested_by'].')' : '' }}. Set the price, then send the payment instructions.

@if ($details['answers'])
<x-mail::table>
| | |
|:--|:--|
| People | {{ number_format($details['people']) }} |
| Texts per person per day | {{ $details['texts_per_day'] }} |
| Days | {{ $details['days'] }} |
| Estimate | {{ $details['estimate'] }} |
</x-mail::table>

School's answers: {{ $details['answers'] }}
@else
The school did not answer the SMS questions.
@endif

<x-mail::button :url="$details['url']">
Open the school
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
