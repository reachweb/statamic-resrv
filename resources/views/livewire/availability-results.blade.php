@use(Carbon\Carbon)

<div>
    <hr class="h-px my-6 bg-gray-200 border-0">
    @if (data_get($availability, 'message.status') === 1)
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
            <div class="text-lg font-medium mb-3">{{ trans('statamic-resrv::frontend.paymentDetails') }}</div>
            <div class="flex items-center space-x-4 mb-2">
                <div class="flex-1 min-w-0">
                    <p class="font-medium text-gray-500 truncate">
                        {{ trans('statamic-resrv::frontend.totalAmount') }}
                    </p>
                 </div>
                 <div class="inline-flex items-center text-base font-medium">
                    {{ config('resrv-config.currency_symbol') }} {{ $availability->get('data')['price'] }}
                 </div>
            </div>
            <div class="flex items-center space-x-4 mb-2">
                <div class="flex-1 min-w-0">
                    <p class="font-medium text-gray-500 truncate">
                        {{ trans('statamic-resrv::frontend.payableNow') }}
                    </p>
                 </div>
                 <div class="inline-flex items-center text-base font-medium">
                    {{ config('resrv-config.currency_symbol') }} {{ $availability->get('data')['payment'] }}
                 </div>
            </div>
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
    @elseif (! $errors->has('availability'))
    <div class="flex flex-col pb-6">
        <dt class="text-lg font-medium">{{ trans('statamic-resrv::frontend.noAvailability') }}</dt>
        <dd class="mb-1 text-gray-500 lg:text-lg ">{{ trans('statamic-resrv::frontend.tryAdjustingYourSearch') }}</dd>
    </div>
    @endif
    @if ($errors->has('availability'))
    <div class="flex flex-col pb-6">
        <dt class="text-lg font-medium">{{ trans('statamic-resrv::frontend.searchError') }}</dt>
        <dd class="mb-1 text-gray-500 lg:text-lg ">{{ $errors->first('availability') }}</dd>
    </div>
    @endif
</div>
