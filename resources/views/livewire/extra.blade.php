<div>
    <div @class(["grid items-center py-3 lg:py-5", "grid-cols-2" => ! $extra->allow_multiple, "grid-cols-3" => $extra->allow_multiple])>
        <div>
            <label class="inline-flex items-center cursor-pointer">
                <input type="checkbox" class="sr-only peer" wire:model.live="selected">
                <div 
                    class="relative w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 
                    rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white 
                    after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-gray-300 
                    after:border after:rounded-full after:w-5 after:h-5 after:transition-all peer-checked:bg-blue-600"
                >
                </div>
                    <span class="ms-3 font-medium text-gray-900">{{ $extra->name }}</span>
            </label>
            @if ($extra->description)
            <div class="text-sm text-gray-500 mt-2 lg:mt-3">{{ $extra->description }}</div>
            @endif
        </div>
        @if ($extra->allow_multiple)
        <div class="flex items-center justify-center">
            @if ($quantity > 0)
            <div wire:transition.in.opacity.duration.200ms class="max-w-xs mx-auto flex items-center" x-data="{ quantity: $wire.entangle('quantity').live }">
                <label for="counter-input" class="block mr-3 text-sm font-medium text-gray-900">{{ trans('statamic-resrv::frontend.quantity') }}:</label>
                <div class="relative flex items-center">
                    <button 
                        type="button" 
                        class="flex-shrink-0 bg-gray-100 hover:bg-gray-200 inline-flex items-center justify-center border border-gray-300 rounded-md 
                        h-5 w-5 focus:ring-gray-100 focus:ring-2 focus:outline-none disabled:opacity-50 disabled:cursor-not-allowed"
                        x-on:click="quantity--"
                        x-bind:disabled="quantity === 1"
                    >
                        <svg class="w-2.5 h-2.5 text-gray-900" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 18 2">
                            <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M1 1h16"/>
                        </svg>
                    </button>
                    <input type="text" id="counter-input" class="flex-shrink-0 text-gray-900 border-0 bg-transparent text-sm font-normal focus:outline-none focus:ring-0 max-w-[2.5rem] text-center" placeholder="" x-bind:value="quantity" required />
                    <button 
                        type="button"
                        class="flex-shrink-0 bg-gray-100 hover:bg-gray-200 inline-flex items-center justify-center border border-gray-300 rounded-md 
                        h-5 w-5 focus:ring-gray-100 focus:ring-2 focus:outline-none disabled:opacity-50 disabled:cursor-not-allowed"
                        x-on:click="quantity++"
                        x-bind:disabled="quantity === $wire.extra.maximum"
                    >
                        <svg class="w-2.5 h-2.5 text-gray-900" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 18 18">
                            <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 1v16M1 9h16"/>
                        </svg>
                    </button>
                </div>
            </div>
            @endif
        </div>
        @endif
        <div class="flex items-center justify-end text-gray-900">
            <span>{{ config('resrv-config.currency_symbol') }} {{ $extra->price }}</span>
        </div>  
    </div>
</div>