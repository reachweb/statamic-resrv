@props(['extra', 'selectedValue' => null])

<div 
    x-data="{
        selected: false, 
        quantity: 1,
        dispatchEvent() {
            this.$dispatch('extra-changed', {
                id: {{ $extra->id }}, 
                price: '{{ $extra->price }}',
                quantity: this.quantity,
            });
        },
        dispatchRemovedEvent() {
            this.$dispatch('extra-removed', {
                id: {{ $extra->id }}, 
            });
        }
    }"
    x-init="
        let alreadySelected = @js($selectedValue);
        if (alreadySelected) {
            selected = true;
            quantity = alreadySelected.quantity;
        }
        $watch('quantity', () => {
            dispatchEvent();
        });
    "
>
    <div class="grid grid-cols-4 items-center py-3 lg:py-5">
        <div @class(["grid items-center py-3 lg:py-5", "col-span-3" => ! $extra->allow_multiple, "col-span-2" => $extra->allow_multiple])>
            <label class="inline-flex items-center cursor-pointer">
                <input 
                    type="checkbox" 
                    class="sr-only peer" 
                    x-model="selected"
                    x-on:change="selected === true ? dispatchEvent() : dispatchRemovedEvent()"
                >
                <div 
                    class="relative flex-shrink-0 w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 
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
            <template x-if="selected === true">
                <div class="max-w-xs mx-auto flex flex-col lg:flex-row items-center">
                    <label for="counter-input" class="block mb-2 lg:mb-0 lg:mr-3 text-sm font-medium text-gray-900">{{ trans('statamic-resrv::frontend.quantity') }}</label>
                    <div class="relative flex items-center">
                        <button 
                            type="button"
                            class="flex-shrink-0 bg-gray-100 hover:bg-gray-200 inline-flex items-center justify-center border border-gray-300 rounded-md 
                            h-5 w-5 focus:ring-gray-100 focus:ring-2 focus:outline-none disabled:opacity-50 disabled:cursor-not-allowed"
                            x-on:click.throttle="quantity = Math.max(1, quantity - 1)"
                            x-bind:disabled="quantity === 1"
                        >
                            <svg class="w-2.5 h-2.5 text-gray-900" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 18 2">
                                <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M1 1h16"/>
                            </svg>
                        </button>
                        <input 
                            type="text" 
                            id="counter-input" 
                            class="flex-shrink-0 text-gray-900 border-0 bg-transparent text-sm font-normal focus:outline-none focus:ring-0 max-w-[2.5rem] text-center" 
                            placeholder="" 
                            x-bind:value="quantity" 
                            required
                        />
                        <button 
                            type="button"
                            class="flex-shrink-0 bg-gray-100 hover:bg-gray-200 inline-flex items-center justify-center border border-gray-300 rounded-md 
                            h-5 w-5 focus:ring-gray-100 focus:ring-2 focus:outline-none disabled:opacity-50 disabled:cursor-not-allowed"
                            x-on:click.throttle="quantity = Math.min(quantity + 1, {{ $extra->maximum }})"
                            x-bind:disabled="quantity >= {{ $extra->maximum }}"
                        >
                            <svg class="w-2.5 h-2.5 text-gray-900" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 18 18">
                                <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 1v16M1 9h16"/>
                            </svg>
                        </button>
                    </div>
                </div>
            </template>
        </div>
        @endif
        <div class="flex items-center justify-end text-gray-900">
            <span>{{ config('resrv-config.currency_symbol') }} {{ $extra->price }}</span>
        </div>  
    </div>
</div>