@props(['maxAdults', 'maxChildren', 'maxInfants'])

<div x-data="guests">
    <div 
        x-on:click.stop="toggleGuestsPopup" 
        x-ref="guestsButton"
        {{ $attributes->merge(['class' => 'flex items-center min-w-[200px] h-11 bg-gray-50 border border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full py-2.5 px-3']) }}
    >
        <div class="flex flex-col flex-grow cursor-pointer select-none">
            <span x-html="guestsText"></span>
        </div>
        <div class="ml-4" x-bind:class="{'rotate-180' : guestsPopup}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
            </svg>                      
        </div>                
    </div>
    <div 
        x-show="guestsPopup" 
        x-transition:enter="transition ease-out duration-200" 
        x-transition:enter-start="opacity-0 translate-y-1" 
        x-transition:enter-end="opacity-100 translate-y-0" 
        x-transition:leave="transition ease-in duration-150" 
        x-transition:leave-start="opacity-100 translate-y-0" 
        x-transition:leave-end="opacity-0 translate-y-1" 
        class="absolute top-full z-50 mt-1 w-screen max-w-sm transform px-4 sm:px-0" 
        role="menu" 
        aria-orientation="vertical" 
        aria-labelledby="menu-button"  
        tabindex="-1" 
        x-on:click.outside="toggleGuestsPopup"
        x-cloak
        x-ref="guestsPopup"
        x-anchor.offset.10="$refs.guestsButton"
        >
        <div class="overflow-hidden shadow rounded-md border border-gray-300" role="none">
            <div class="relative">
                <div class="bg-gray-50 p-4 lg:min-w-xl">
                    <div class="flex items-center justify-between py-2 border-b border-b-p-gray">
                        <div class="pr-8">
                            <span class="block">{{ trans('Adults') }}</span>
                            <span class="block text-sm font-light">{{ trans('Ages 10 or above') }}</span>
                        </div>
                        <div class="flex items-center">
                            <div 
                                class="flex items-center text-xl justify-center h-8 w-8 border border-black rounded-full cursor-pointer"
                                x-bind:class="{'opacity-25' : adults == 1}"
                                x-on:click="decrement('adults')"
                            >
                                <span class="mt-px">-</span>
                            </div>
                            <div class="px-4" x-html="adults"></div>
                            <div 
                                class="flex items-center text-xl justify-center h-8 w-8 border border-black rounded-full cursor-pointer"
                                x-bind:class="{'opacity-25' : adults == 8}"
                                x-on:click="increment('adults')"
                            >
                                <span class="mt-px">+</span>
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center justify-between py-2 border-b border-b-p-gray">
                        <div class="pr-8">
                            <span class="block">{{ trans('Children') }}</span>
                            <span class="block text-sm font-light">{{ trans('Ages 3-10') }}</span>
                        </div>
                        <div class="flex items-center">
                            <div 
                                class="flex items-center text-xl justify-center h-8 w-8 border border-black rounded-full cursor-pointer"
                                x-bind:class="{'opacity-25' : children == 0}"
                                x-on:click="decrement('children')"
                            >
                                <span class="mt-px">-</span>
                            </div>
                            <div class="px-4" x-html="children"></div>
                            <div 
                                class="flex items-center text-xl justify-center h-8 w-8 border border-black rounded-full cursor-pointer"
                                x-bind:class="{'opacity-25' : children == 4}"
                                x-on:click="increment('children')"
                            >
                                <span class="mt-px">+</span>
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center justify-between py-2">
                        <div class="pr-8">
                            <span class="block">{{ trans('Infants') }}</span>
                            <span class="block text-sm font-light">{{ trans('Up to 3 years old') }}</span>
                        </div>
                        <div class="flex items-center">
                            <div 
                                class="flex items-center text-xl justify-center h-8 w-8 border border-black rounded-full cursor-pointer"
                                x-bind:class="{'opacity-25' : infants == 0}"
                                x-on:click="decrement('infants')"
                            >
                                <span class="mt-px">-</span>
                            </div>
                            <div class="px-4" x-html="infants"></div>
                            <div 
                                class="flex items-center text-xl justify-center h-8 w-8 border border-black rounded-full cursor-pointer"
                                x-bind:class="{'opacity-25' : infants == 2}"
                                x-on:click="increment('infants')"
                            >
                                <span class="mt-px">+</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>


@script
<script>
Alpine.data('guests', () => ({
    adults: $wire.data.custom.adults,
    children: $wire.data.custom.children,
    infants: $wire.data.custom.infants,
    guestsPopup: false,

    init() {
        if (this.adults === undefined) {
            this.adults = 2;
            $wire.set('data.custom.adults', 1);
        }
        if (this.children === undefined) {
            this.children = 0;
            $wire.set('data.custom.children', 0);
        }
        if (this.infants === undefined) {
            this.infants = 0;
            $wire.set('data.custom.infants', 0);
        }
        this.$watch('adults', value => {
            $wire.set('data.custom.adults', value);
        });

        this.$watch('children', value => {
            $wire.set('data.custom.children', value);
        });

        this.$watch('children', value => {
            $wire.set('data.custom.infants', value);
        });
    },

    toggleGuestsPopup() {
        this.guestsPopup = ! this.guestsPopup
    },
    guestsText() { 
        return `<span class="">${this.adults} ${this.adults > 1 ? 'adults' : 'adult'}</span>
        ${this.children > 0 ? `<span class="">, ${this.children} ${this.children > 1 ? 'children' : 'child'}</span>` : ''}
        ${this.infants > 0 ? `<span class="">, ${this.infants} ${this.infants > 1 ? 'infants' : 'infant'}</span>` : ''}`
    },  
    increment(key) {
        if (key == 'adults' && this.adults == {{ $maxAdults }}) {
            return ''
        }
        if (key == 'childs' && this.childs == {{ $maxChildren }}) {
            return ''
        }
        if (key == 'infants' && this.infants == {{ $maxInfants }}) {
            return ''
        }
        this[key] = this[key] + 1
    },        
    decrement(key) {
        if (key == 'adults' && this.adults == 1) {
            return ''
        }
        if (this[key] !== 0) {
            this[key] = this[key] - 1
        }
    },
}));
</script>
@endscript