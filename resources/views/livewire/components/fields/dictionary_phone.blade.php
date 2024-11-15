<div 
    {{ $attributes->class([
        'relative',
        'md:col-span-2' => $field['width'] === 100,
        'md:col-span-1' => $field['width'] === 50,
    ]) }}
    wire:key={{ $key }}
>
    <label for="{{ $field['handle'] }}" class="block mb-2 font-medium text-gray-900">
        {{ __($field['display']) }}
    </label>
    <div 
        x-data="phonebox(@js($this->getDictionaryItems($field['handle'])), '{{ $field['handle'] }}')"        
        class="flex w-full flex-col gap-1"
        x-on:keydown="handleKeydownOnOptions($event)"
        x-on:keydown.esc.window="isOpen = false, openedWithKeyboard = false"
    >
        <div class="relative flex w-full">
            <button 
                type="button"
                class="inline-flex w-max items-center justify-between gap-2 whitespace-nowrap rounded-l-md border bg-gray-50 border-r-0 bg-neutral-50 px-4 py-2.5 font-medium tracking-wide text-neutral-600 transition hover:opacity-75 focus-visible:outline-none focus-visible:neutral-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600"
                role="combobox"
                aria-controls="list"
                aria-haspopup="listbox"
                x-on:click="isOpen = ! isOpen"
                x-on:keydown.down.prevent="openedWithKeyboard = true"
                x-on:keydown.enter.prevent="openedWithKeyboard = true"
                x-on:keydown.space.prevent="openedWithKeyboard = true"
                x-bind:aria-expanded="isOpen || openedWithKeyboard"
                x-bind:aria-label="selectedOption ? selectedOption : 'Please Select'"
            >
                <span class="font-normal" x-text="selectedOption ? selectedOption : 'Please Select'"></span>
                <svg class="size-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd"/>
                </svg>
            </button>

            <div 
                x-show="isOpen || openedWithKeyboard"
                id="statesList"
                class="absolute left-0 top-11 z-10 w-full overflow-hidden rounded-md border border-neutral-300 bg-neutral-50"
                role="listbox"
                aria-label="list"
                x-on:click.outside="isOpen = false, openedWithKeyboard = false"
                x-on:keydown.down.prevent="$focus.wrap().next()"
                x-on:keydown.up.prevent="$focus.wrap().previous()"
                x-transition
                x-trap="openedWithKeyboard"
            >
                <div class="relative">
                    <svg class="absolute left-4 top-1/2 size-5 -translate-y-1/2 text-neutral-600/50" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="1.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/>
                    </svg>
                    <input 
                        type="text"
                        class="w-full border-b border-neutral-300 bg-neutral-50 py-2.5 pl-11 pr-4 text-sm text-neutral-600 focus:outline-none focus-visible:border-blue-600 disabled:cursor-not-allowed disabled:opacity-75"
                        name="searchField"
                        aria-label="Search"
                        x-on:input="getFilteredOptions($el.value)"
                        x-ref="searchField"
                        placeholder="{{ __('Filter...') }}"
                    />
                </div>
                <ul class="flex max-h-44 flex-col overflow-y-auto bg-white">
                    <li class="hidden px-4 py-2 text-sm text-neutral-600" x-ref="noResultsMessage">
                        <span>{{ __('No results found') }}</span>
                    </li>
                    <template x-for="(item, index) in options" x-bind:key="item.code">
                        <li 
                            class="combobox-option inline-flex cursor-pointer justify-between gap-6 bg-neutral-50 px-4 py-2 text-sm text-neutral-600 hover:bg-neutral-900/5 hover:text-neutral-900 focus-visible:bg-neutral-900/5 focus-visible:text-neutral-900 focus-visible:outline-none"
                            role="option"
                            x-on:click="setSelectedOption(item)"
                            x-on:keydown.enter="setSelectedOption(item)"
                            x-bind:id="'option-' + index"
                            tabindex="0"
                        >
                            <div class="flex items-center gap-2">
                                <span x-bind:class="selectedOption == item ? 'font-bold' : null">
                                    <span x-text="item.label"></span> (<span class="font-bold" x-text=item.code></span>)
                                </span>
                                <span class="sr-only" x-text="selectedOption == item ? 'selected' : null"></span>
                            </div>
                            <svg 
                                x-cloak
                                x-show="selectedOption == item"
                                class="size-4"
                                xmlns="http://www.w3.org/2000/svg"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                fill="none"
                                stroke-width="2"
                                aria-hidden="true"
                            >
                                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5">
                            </svg>
                        </li>
                    </template>
                </ul>
            </div>
            <input 
                id="phoneNumber" 
                type="tel" 
                class="w-full border-neutral-300 rounded-r-md border rounded-l-none bg-gray-50 p-2.5 
                focus:outline-none focus:ring-blue-500 focus:border-blue-500 
                disabled:cursor-not-allowed disabled:opacity-75" 
                x-ref="phoneNumber" 
                autocomplete="phone"
                wire:model="form.{{ $field['handle'] }}"
            />
        </div>        
    </div>
</div>

@script
<script>
Alpine.data('phonebox', (comboboxData, fieldHandle) => ({
    options: comboboxData,
    isOpen: false,
    openedWithKeyboard: false,
    selectedOption: null,
    setSelectedOption(option) {
        this.selectedOption = option.code
        this.isOpen = false
        this.openedWithKeyboard = false
        this.$refs.phoneNumber.value = option.code
        this.$refs.phoneNumber.focus()
    },
    getFilteredOptions(query) {
        this.options = comboboxData.filter((option) =>
            option.name.toLowerCase().includes(query.toLowerCase()) ||
            option.code.toLowerCase().includes(query.toLowerCase())
        )
        if (this.options.length === 0) {
            this.$refs.noResultsMessage.classList.remove('hidden')
        } else {
            this.$refs.noResultsMessage.classList.add('hidden')
        }
    },
    handleKeydownOnOptions(event) {
        if ((event.keyCode >= 65 && event.keyCode <= 90) || (event.keyCode >= 48 && event.keyCode <= 57) || event.keyCode === 8) {
            this.$refs.searchField.focus()
        }
    },
}))
</script>
@endscript