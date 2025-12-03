@use(Carbon\Carbon)

<div class="relative">
    @if ($availableDates->isNotEmpty())
        <div class="divide-y divide-gray-200">
            <div class="flex flex-col pb-4">
                <div class="text-lg font-medium mb-2">{{ trans('statamic-resrv::frontend.availableDates') }}</div>
                @if ($data->hasDates())
                <div class="mb-1 text-sm text-gray-500">
                    {{ trans('statamic-resrv::frontend.availableDatesFrom') }} {{ Carbon::parse($data->dates['date_start'])->format('D d M Y') }}
                </div>
                @endif
            </div>
            
            @foreach ($availableDates as $date => $properties)
                <div class="py-4">
                    <div class="flex items-center gap-4 mb-3">
                        <div class="flex flex-col">
                            <div class="text-sm font-medium text-gray-500">
                                {{ Carbon::parse($date)->format('D') }}
                            </div>
                            <div class="text-xl font-semibold text-gray-900">
                                {{ Carbon::parse($date)->format('d M Y') }}
                            </div>
                        </div>
                        <div class="text-sm text-gray-500">
                            {{ count($properties) }} {{ trans_choice('statamic-resrv::frontend.optionsAvailable', count($properties)) }}
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
                        @foreach ($properties as $property => $info)
                            <div class="flex flex-col p-3 bg-gray-50 rounded-lg border border-gray-200 hover:border-blue-300 transition-colors cursor-pointer"
                                 wire:click="selectDate('{{ $date }}', '{{ $property }}')"
                                 wire:key="{{ $date }}-{{ $property }}">
                                <div class="text-sm font-medium text-gray-900">
                                    {{ $this->advancedProperties[$property] ?? $property }}
                                </div>
                                <div class="mt-2 text-lg font-semibold text-blue-600">
                                    {{ config('resrv-config.currency_symbol') }} {{ $info['price'] }}
                                </div>
                                @if ($info['available'] <= 5)
                                    <div class="text-xs text-orange-600 mt-1 font-medium">
                                        {{ trans('statamic-resrv::frontend.only') }} {{ $info['available'] }} {{ trans('statamic-resrv::frontend.left') }}
                                    </div>
                                @elseif ($info['available'] > 1)
                                    <div class="text-xs text-gray-500 mt-1">
                                        {{ $info['available'] }} {{ trans('statamic-resrv::frontend.available') }}
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @elseif ($data->hasDates())
        <div class="flex flex-col py-4">
            <dt class="text-lg font-medium">{{ trans('statamic-resrv::frontend.noAvailableDates') }}</dt>
            <dd class="mb-1 text-gray-500">{{ trans('statamic-resrv::frontend.tryAdjustingYourSearch') }}</dd>
        </div>
    @else
        <div class="flex flex-col py-4">
            <dt class="text-lg font-medium">{{ trans('statamic-resrv::frontend.pleaseSelectStartDate') }}</dt>
        </div>
    @endif

    @if ($errors->has('availability') && $data->hasDates())
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
