@use(Carbon\Carbon)

<div class="relative">
    @if (data_get($availability, 'message.status') === true && data_get($availability, 'request.property') !== 'any')
        @if ($this->showExtras || $this->showOptions)
        <div class="flex flex-col gap-y-6 py-6">
            @if ($this->showOptions)
            <x-resrv::availability-options :$enabledOptions :options="$this->options" />
            @endif
            @if ($this->showExtras)
            <x-resrv::availability-extras :$enabledExtras :extras="$this->extras" />
            @endif
        </div>
        @endif    
    <div class="divide-y divide-gray-200">
        <div class="flex flex-col pb-6">
            <div class="text-lg font-medium mb-2">{{ trans('statamic-resrv::frontend.yourSearch') }}</div>
            <div class="mb-1">
                <div class="mb-1">
                    <span class="text-gray-500">{{ ucfirst(trans('statamic-resrv::frontend.from')) }}:</span> 
                    <span class=font-medium>{{ Carbon::parse($data->dates['date_start'])->format('D d M Y') }}</span>
                </div>
                <div class="mb-1">
                    <span class="text-gray-500">{{ ucfirst(trans('statamic-resrv::frontend.to')) }}:</span> 
                    <span class=font-medium>{{ Carbon::parse($data->dates['date_end'])->format('D d M Y') }}</span>
                </div>
                <div class="mb-1">
                    <span class="text-gray-500">{{ ucfirst(trans('statamic-resrv::frontend.duration')) }}:</span> 
                    <span class=font-medium>{{ data_get($availability, 'request.days') }} {{ trans('statamic-resrv::frontend.days') }}</span>
                </div>
            </div>
        </div>
        <div class="flex flex-col py-6">
            @include('statamic-resrv::livewire.components.partials.availability-results-pricing')
        </div>
    </div>
    <div class="mt-6 xl:mt-8">
        <button 
            type="button" 
            class="w-full px-6 py-3.5 text-base font-medium text-white bg-blue-700 hover:bg-blue-800 focus:ring-4 focus:outline-none focus:ring-blue-300 rounded-lg text-center"
            wire:click="checkout()"
        >
            {{ trans('statamic-resrv::frontend.bookNow') }}
        </button>
    </div>
    @elseif (data_get($availability, 'request.property') === 'any')
    <div class="flex flex-col py-4">
        <dt class="text-lg font-medium">{{ trans('statamic-resrv::frontend.multipleAvailable') }}</dt>
        <dd class="mb-1 text-gray-500">{{ trans('statamic-resrv::frontend.pleaseSelectProperty') }}</dd>
    </div>
    @elseif (! $errors->has('availability'))
    <div class="flex flex-col py-4">
        <dt class="text-lg font-medium">{{ trans('statamic-resrv::frontend.noAvailability') }}</dt>
        <dd class="mb-1 text-gray-500">{{ trans('statamic-resrv::frontend.tryAdjustingYourSearch') }}</dd>
    </div>
    @endif
    @if ($errors->has('availability'))
    <div class="flex flex-col py-4">
        <dt class="text-lg font-medium">{{ trans('statamic-resrv::frontend.searchError') }}</dt>
        <dd class="mb-1 text-gray-500">{{ $errors->first('availability') }}</dd>
    </div>
    @endif
    <div class="absolute left-0 right-0 top-0 w-full h-full bg-white/50" wire:loading.delay.long>
        <span class="flex items-center justify-center w-full h-full">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="animate-spin w-5 h-5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
            </svg>   
        </span>
    </div>
</div>
