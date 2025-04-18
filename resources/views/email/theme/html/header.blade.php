@props(['url' => $url])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
@if (config('resrv-config.logo') == false)
{{ $slot }}
@else
<img src="{{ config('resrv-config.logo') }}" alt="{{ config('resrv-config.name') }}" style="width: 100%; max-width: 200px; height: auto; border: none; display: block;">
@endif
</a>
</td>
</tr>
