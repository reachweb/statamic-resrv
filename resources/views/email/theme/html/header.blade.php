@props(['url' => $url])
@php($logo = config('resrv-config.logo'))
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
@if (! $logo)
{{ $slot }}
@else
<img src="{{ $logo }}" alt="{{ config('resrv-config.name') }}" style="width: 100%; max-width: 200px; height: auto; border: none; display: block;">
@endif
</a>
</td>
</tr>
