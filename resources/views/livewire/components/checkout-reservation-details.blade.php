@props(['entry', 'reservation'])

<div class="text-lg xl:text-xl font-medium mb-2">
    {{ trans('statamic-resrv::frontend.reservationDetails') }}
</div>
<hr class="h-px my-4 bg-gray-200 border-0">
<div wire:ignore>
    <div class="pb-3 md:pb-4 border-b border-gray-200">
        <p class="text-sm font-medium text-gray-500 truncate">
            {{ trans('statamic-resrv::frontend.entryTitle') }}
        </p>
        <p class="text-sm text-gray-900 truncate">
            {{ $entry->title }}
            @if ($reservation->quantity > 1)
            <span class="text-xs text-gray-500">
                (x{{ $reservation->quantity }})
            </span>
            @endif
        </p>
    </div>
    <div class="py-3 md:py-4 border-b border-gray-200">
        <p class="text-sm font-medium text-gray-500 truncate">
            {{ trans('statamic-resrv::frontend.reservationPeriod') }}
        </p>
        <p class="text-sm text-gray-900 truncate">
            {{ $reservation->date_start }}
            {{ trans('statamic-resrv::frontend.to') }}
            {{ $reservation->date_end }}
        </p>
    </div>
    @if ($reservation->property)
    <div class="py-3 md:py-4 border-b border-gray-200">
        <p class="text-sm font-medium text-gray-500 truncate">
            {{ trans('statamic-resrv::frontend.property') }}
        </p>
        <p class="text-sm text-gray-900 truncate">
            {{ $reservation->property }}
        </p>
    </div>
    @endif
</div>