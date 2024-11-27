@props(['extra', 'selectedValue' => null, 'compact' => false, 'required' => false, 'hide' => false])

<div 
    x-data="{
        selected: false, 
        required: {{ $required ? 'true' : 'false' }},
        hide: {{ $hide ? 'true' : 'false' }},
        quantity: 1,

        init() {
            this.initializeSelection(@js($selectedValue));
            this.initializeRequired();
            this.setupWatchers();
        },

        initializeSelection(selectedValue) {
            if (selectedValue) {
                this.selected = true;
                this.quantity = selectedValue.quantity;
            }
        },

        initializeRequired() {
            if (this.required) {
                this.selectAndDispatch();
            }
        },

        setupWatchers() {
            this.$watch('quantity', () => this.selected === true ? this.dispatchEvent() : null);
            this.$watch('hide', () => this.handleHideChange());
            this.$watch('required', () => this.handleRequiredChange());
        },

        handleHideChange() {
            if (this.hide && this.selected) {
                this.selected = false;
                this.dispatchRemovedEvent();
            }
        },

        handleRequiredChange() {
            if (this.required) {
                this.selectAndDispatch();
            }
        },

        selectAndDispatch() {
            this.selected = true;
            this.dispatchEvent();
        },

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
            this.quantity = 1;
            this.$dispatch('extra-removed', {
                id: {{ $extra->id }}, 
            });
        }
    }"
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
            'my-3 lg:my-5' => ! $compact, 
            'my-2 lg:my-3' => $compact
        ])
    >
        <div @class([
            'order-0', 
            'col-span-3' => ! $extra->allow_multiple || ($extra->allow_multiple && $compact), 
            'col-span-2' => $extra->allow_multiple && ! $compact
        ])>
            <div>
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
                    <span class="ml-2 text-xs text-gray-600 uppercase" x-show="required" x-cloak>{{ trans('statamic-resrv::frontend.required') }}</span>
                </label>
                @if ($extra->description)
                <div class="text-sm text-gray-500 mt-1">{{ $extra->description }}</div>
                @endif
            </div>           
        </div>
        @if ($extra->allow_multiple)
            <div @class(['order-2 col-span-4 justify-start' => $compact])>
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