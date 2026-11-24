<x-mail::message>
# Payment to review

**{{ $details['organization'] }}** submitted a payment. It is waiting for you to check it and press Activate.

<x-mail::table>
| | |
|:--|:--|
| Amount | {{ $details['amount'] }} |
| Plan | {{ $details['plan'] }} |
@if ($details['period'])
| Period | {{ $details['period'] }} |
@endif
| Reference | {{ $details['reference'] ?: 'None' }} |
@if ($details['paid_at'])
| Paid on | {{ $details['paid_at'] }} |
@endif
@if ($details['submitted_by'])
| Submitted by | {{ $details['submitted_by'] }} |
@endif
</x-mail::table>

<x-mail::button :url="$details['url']">
Review payment
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
