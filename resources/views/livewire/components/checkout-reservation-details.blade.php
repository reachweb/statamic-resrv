@props(['entry', 'reservation'])

<div 
    x-data="{
        vw: Math.max(document.documentElement.clientWidth || 0, window.innerWidth || 0),
        open: false,
        toggle() {
            this.open = ! this.open;
        },
        updateViewportWidth() {
            this.vw = Math.max(document.documentElement.clientWidth || 0, window.innerWidth || 0);
            this.open = this.vw >= 1024;
        }
    }"
    x-init="() => {
        updateViewportWidth();
    }"
    x-on:resize.window.debounce="updateViewportWidth()"
>
    <div class="text-lg xl:text-xl font-medium mb-2">
        {{ $entry->title }}
    </div>
    <hr class="h-px mt-2 lg:my-3 bg-gray-200 border-0">
    <div wire:ignore x-show="open" x-bind="vw >= 1024 ? '' : { 'x-collapse': '' }">
        @if ($reservation->quantity > 1)
        <div class="pb-3 md:pb-4 border-b border-gray-200">
            <p class="font-medium text-gray-500 truncate">
                {{ trans('statamic-resrv::frontend.quantity') }}
            </p>
            <p class="text-gray-900 truncate">
                x{{ $reservation->quantity }}
            </p>
        </div>
        @endif
        <div class="py-3 md:py-4 border-b border-gray-200">
            <p class="font-medium text-gray-500 truncate">
                {{ trans('statamic-resrv::frontend.reservationPeriod') }}
            </p>
            <p class="text-gray-900 truncate">
                {{ $reservation->date_start }}
                {{ trans('statamic-resrv::frontend.to') }}
                {{ $reservation->date_end }}
            </p>
        </div>
        @if ($reservation->property)
        <div class="py-3 md:py-4 border-b border-gray-200">
            <p class="font-medium text-gray-500 truncate">
                {{ trans('statamic-resrv::frontend.property') }}
            </p>
            <p class="text-gray-900 truncate">
                {{ $reservation->getPropertyAttributeLabel() }}
            </p>
        </div>
        @endif
    </div>
    <button class="mt-2 w-full inline-flex justify-center items-center lg:hidden" x-on:click="toggle">
        <span class="text-gray-900">
            {{ trans('Show details') }}
        </span>
        <span class="ml-2" x-bind:class="{'transform rotate-180': open}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
            </svg>              
        </span>
    </button>
</div>