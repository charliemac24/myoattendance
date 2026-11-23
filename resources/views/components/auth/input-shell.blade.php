@props(['icon'])
<div {{ $attributes->class(['input-shell']) }}>
    <x-dynamic-component :component="'icons.'.$icon" />
    {{ $slot }}
    {{ $trailing ?? '' }}
</div>
