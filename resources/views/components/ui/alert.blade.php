@props(['type' => 'success'])
<div {{ $attributes->class(['ui-alert', 'ui-alert-'.$type]) }} role="alert">
    @if ($type === 'success')
        <x-icons.check-circle />
    @endif
    <p>{{ $slot }}</p>
</div>
