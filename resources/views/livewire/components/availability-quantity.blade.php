@props(['maxQuantity', 'errors'])

<div class="{{ $attributes->get('class') }}">
    <div class="relative flex items-center min-w-32" x-data="{ quantity: $wire.entangle('data.quantity').live }">
        <button 
            type="button" 
            class="bg-gray-100 hover:bg-gray-200 border border-gray-300 rounded-s-lg p-2.5 h-11 focus:ring-gray-100 focus:ring-2 focus:outline-none disabled:opacity-50 disabled:cursor-not-allowed"
            x-on:click="quantity--"
            x-bind:disabled="quantity === 1"
        >
            <svg class="w-3 h-3 text-gray-900" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 18 2">
                <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M1 1h16"/>
            </svg>
        </button>
        <input type="text" class="bg-gray-50 border-x-0 border-gray-300 h-11 font-medium text-center text-gray-900 text-sm focus:ring-blue-500 focus:border-blue-500 block w-full pb-4" placeholder="" x-bind:value="quantity" required />
        <div class="absolute bottom-1 start-1/2 -translate-x-1/2 rtl:translate-x-1/2 flex items-center text-xs text-gray-400 space-x-1 rtl:space-x-reverse">
            <span>{{ trans('statamic-resrv::frontend.quantityLabel') }}</span>
        </div>
        <button 
            type="button" 
            class="bg-gray-100 hover:bg-gray-200 border border-gray-300 rounded-e-lg p-2.5 h-11 focus:ring-gray-100 focus:ring-2 focus:outline-none disabled:opacity-50 disabled:cursor-not-allowed"
            x-on:click="quantity++"
            x-bind:disabled="quantity === {{ $maxQuantity }}"
        >
            <svg class="w-3 h-3 text-gray-900" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 18 18">
                <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 1v16M1 9h16"/>
            </svg>
        </button>
    </div>
    @if ($errors->has('data.quantity'))
    <div class="mt-2 text-red-600 text-sm space-y-1">
        <span class="block">{{ $errors->first('data.quantity') }}</span>
    </div>
    @endif
</div>   