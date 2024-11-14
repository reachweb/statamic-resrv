@props(['extra', 'selectedValue' => null, 'compact' => false, 'required' => false, 'hide' => false])

<div 
    x-data="{
        selected: false, 
        required: {{ $required ? 'true' : 'false' }},
        hide: {{ $hide ? 'true' : 'false' }},
        quantity: 1,
        handleConditionsChange(event) {
            this.required = event.detail[0].required.includes({{ $extra->id }});
            this.hide = event.detail[0].hide.includes({{ $extra->id }});
        },
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
        if (required) {
            selected = true;
            dispatchEvent();
        }
        $watch('quantity', () => {
            dispatchEvent();
        });
        $watch('hide', () => {
            if (hide && selected) {
                selected = false;
                dispatchRemovedEvent();
            }
        });
        $watch('required', () => {
            if (required) {
                selected = true
                dispatchEvent();
            }
        });
    "
    x-on:extra-conditions-changed.window="handleConditionsChange($event)"
>
    <div 
        x-show="! hide"
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="opacity-0 -translate-y-2"
        x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100 translate-y-0"
        x-transition:leave-end="opacity-0 translate-y-2"
        @class([
            'grid grid-cols-4 items-center',
            'py-3 lg:py-5 xl:py-8' => ! $compact, 
            'py-2 lg:py-3' => $compact
        ])
    >
        <div @class([
            'grid items-center order-0', 
            'col-span-3' => ! $extra->allow_multiple || ($extra->allow_multiple && $compact), 
            'col-span-2' => $extra->allow_multiple && ! $compact
        ])>
            <label class="inline-flex items-center cursor-pointer">
                <input 
                    type="checkbox" 
                    class="sr-only peer" 
                    x-model="selected"
                    x-on:change="selected === true ? dispatchEvent() : dispatchRemovedEvent()"
                    x-bind:disabled="required"
                >
                <div 
                    class="relative flex-shrink-0 w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 
                    rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white 
                    after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-gray-300 
                    after:border after:rounded-full after:w-5 after:h-5 after:transition-all peer-checked:bg-blue-600"
                    x-bind:class="{'cursor-not-allowed': required}"
                >
                </div>
                    <span class="ms-3 font-medium text-gray-900">{{ $extra->name }}</span> 
                    <span class="ml-2 text-xs text-gray-600 uppercase" x-show="required">{{ trans('statamic-resrv::frontend.required') }}</span>
            </label>
            @if ($extra->description)
            <div class="text-sm text-gray-500 mt-2 lg:mt-3">{{ $extra->description }}</div>
            @endif
        </div>
        @if ($extra->allow_multiple)
            <div @class(['order-2 col-span-4' => $compact])>
            @include('statamic-resrv::livewire.components.partials.extras-quantity')
            </div>
        @endif
        <div @class([
            'flex items-center justify-end text-gray-900 col-span-1',
            'order-1' => $compact,
        ])>
            <span>{{ config('resrv-config.currency_symbol') }} {{ $extra->price }}</span>
        </div>  
    </div>
</div>