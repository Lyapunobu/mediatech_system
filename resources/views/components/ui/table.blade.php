@props(['caption', 'tableClass' => ''])
<div {{ $attributes->class(['overflow-x-auto']) }} tabindex="0" role="region" aria-label="{{ $caption }}">
    <table @class(['ui-table', $tableClass])>
        <caption class="sr-only">{{ $caption }}</caption>
        {{ $slot }}
    </table>
</div>
