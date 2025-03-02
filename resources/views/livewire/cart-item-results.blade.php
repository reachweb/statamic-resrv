@use(Carbon\Carbon)

<div>
    <div class="flex justify-between items-start">
        <div>
            <h3 class="text-lg font-medium">{{ $this->entry->get('title') }}</h3>
            @if(isset($data->availabilityData['advanced']) && $data->availabilityData['advanced'])
                <div class="mt-3">
                    <label class="block text-sm font-medium text-gray-700 mb-1">{{ trans('statamic-resrv::frontend.property') }}</label>
                    <div class="text-sm">{{ $data->availabilityData['advanced'] }}</div>
                </div>
            @endif
        </div>
        <button 
            wire:click="removeFromCart" 
            class="py-2.5 px-5 me-2 mb-2 text-sm font-medium inline-flex items-center text-gray-900 focus:outline-none bg-white rounded-lg border border-gray-200 
            hover:bg-gray-100 hover:text-red-700 focus:z-10 focus:ring-4 focus:ring-gray-100 transition-opacity duration-500"
            title="{{ trans('statamic-resrv::frontend.removeFromCart') }}"
        >
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-5">
                <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
            </svg>          
            <span class="pl-3">{{ trans('statamic-resrv::frontend.removeFromCart') }}</span>
        </button>
    </div>
    
    <div class="mt-4">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">{{ ucfirst(trans('statamic-resrv::frontend.from')) }}</label>
                <span>
                    {{ Carbon::parse($data->availabilityData['dates']['date_start'])->format('D d M Y') }}
                </span>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">{{ ucfirst(trans('statamic-resrv::frontend.to')) }}</label>
                <span>
                    {{ Carbon::parse($data->availabilityData['dates']['date_end'])->format('D d M Y') }}
                </span>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">{{ trans('statamic-resrv::frontend.quantity') }}</label>
                <span>
                    {{ $data->availabilityData['quantity'] }}
                </span>
            </div>
        </div>
    </div>
    
    <div class="mt-4">
        @if( ! $data->valid)
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
                @if(isset($data->results['message']['text']))
                    {{ $data->results['message']['text'] }}
                @else
                    {{ trans('statamic-resrv::frontend.notAvailableForSelectedDates') }}
                @endif
            </div>
        @else
            <div class="flex justify-between items-center">
                <div>
                    <p class="font-medium text-green-600">{{ trans('statamic-resrv::frontend.available') }}</p>
                    @if(isset($data->results['data']['price']))
                        <div class="flex items-center">
                            <p class="text-lg font-bold">
                                {{ config('resrv-config.currency_symbol') }} {{ $data->results['data']['price'] }}
                            </p>
                            @if(isset($data->results['data']['original_price']) && $data->results['data']['original_price'] !== null)
                                <p class="text-sm text-gray-400 line-through ml-2">
                                    {{ config('resrv-config.currency_symbol') }} {{ $data->results['data']['original_price'] }}
                                </p>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </div>

    <div class="relative" wire:loading>
        <div class="absolute inset-0 bg-white/50 flex items-center justify-center">
            <svg class="animate-spin h-5 w-5 text-blue-700" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
        </div>
    </div>
</div>
