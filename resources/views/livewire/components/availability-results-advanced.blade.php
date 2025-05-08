@props(['availability', 'advancedProperties'])

<div {{ $attributes->merge(['class' => 'my-3 lg:my-4']) }}>
    <div class="text=-sm font-medium text-gray-600 mb-3">
        {{ trans('statamic-resrv::frontend.pleaseSelectPropertyToBook') }}:
    </div>
    <div class="grid grid-cols-1 gap-2" role="list">
        @foreach ($availability as $property => $data)
            @if (data_get($data, 'message.status') !== true)
            <div class="flex flex-col items-center justify-center rounded-lg bg-gray-50 border border-gray-300 text-gray-900 h-full" role="listitem">
                <div class="mt-2 px-2 font-bold text-sm">
                    {{ data_get($advancedProperties, $property) }}
                </div>
                <div class="mt-2 mb-3 px-2 font-medium">
                    {{ trans('statamic-resrv::frontend.noAvailability') }}
                </div>
            </div>
            @else
            <div class="flex flex-col rounded-lg bg-gray-50 border border-blue-600 text-gray-900 h-full" role="listitem">
                <div class="mt-2 px-2 text-center font-bold text-sm">
                    {{ data_get($advancedProperties, $property) }}
                </div>
                <div class="mt-2 mb-3 px-2 text-center font-medium">
                    {{ config('resrv-config.currency_symbol') }} {{ data_get($data, 'data.price') }}
                </div>
                <button
                    type="button"
                    class="p-2 text-center text-sm font-medium bg-blue-600 hover:bg-blue-800 w-full text-white rounded-b-lg uppercase transition-colors duration-300" 
                    wire:click="checkoutProperty('{{ $property }}')"
                    aria-label="Book {{ data_get($advancedProperties, $property) }} now"
                >
                    {{ trans('statamic-resrv::frontend.bookNow') }}
                </button>
            </div>
            @endif
        @endforeach
    </div>
</div>