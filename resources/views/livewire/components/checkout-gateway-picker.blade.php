@props(['gateways'])

<div class="my-6 xl:my-8">
    <div class="text-lg xl:text-xl font-medium mb-2">
        {{ trans('statamic-resrv::frontend.selectPaymentMethod') }}
    </div>
    <div class="mt-4 space-y-3">
        @foreach ($gateways as $gateway)
            <button
                type="button"
                wire:click="$dispatch('gateway-selected', { gateway: @js($gateway['name']) })"
                wire:loading.attr="disabled"
                class="flex items-center justify-between w-full p-4 border-2 border-gray-200 rounded-lg hover:border-blue-500 hover:bg-blue-50 transition-colors duration-200 cursor-pointer disabled:opacity-50 disabled:cursor-wait"
            >
                <span class="text-base font-medium">{{ $gateway['label'] }}</span>
                <svg wire:loading.remove xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-gray-400">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                </svg>
                <svg wire:loading xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" class="w-5 h-5 animate-spin text-blue-500">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
            </button>
        @endforeach
    </div>
</div>
