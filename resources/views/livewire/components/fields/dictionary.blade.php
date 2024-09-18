@props(['field', 'key', 'errors'])

<div {{ $attributes->class(['relative', 'md:col-span-2' => $field['width'] === 100, 'md:col-span-1' => $field['width'] === 50,]) }} wire:key={{ $key }}>
    <label for="{{ $field['handle'] }}" class="block mb-2 font-medium text-gray-900">
        {{ __($field['display']) }}
    </label>
    <div x-data="filteredSelect"
        x-on:keydown.escape="if(selectOpen){ selectOpen=false; $refs.selectButton.focus(); }"
        x-on:keydown.down="if(selectOpen){ selectableItemActiveNext(); } else { selectOpen=true; } $event.preventDefault();"
        x-on:keydown.up="if(selectOpen){ selectableItemActivePrevious(); } else { selectOpen=true; } $event.preventDefault();"
        x-on:keydown.enter.prevent="if(selectOpen && selectableItemActive){ selectedItem=selectableItemActive; selectOpen=false; $refs.selectButton.focus(); }"
        x-on:keydown="selectKeydown($event);"
        class="relative w-full">

        <button x-ref="selectButton" x-on:click="selectOpen=!selectOpen"
            x-bind:id="selectId + '-button'"
            aria-haspopup="listbox"
            x-bind:aria-expanded="selectOpen"
            x-bind:aria-labelledby="selectId + '-label'"
            x-bind:class="{ 'focus:ring-2 focus:ring-offset-2 focus:ring-neutral-400' : !selectOpen }"
            class="relative flex items-center justify-between bg-gray-50 border border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5">
            <span x-text="selectedItem ? selectableItems[selectedItem] : '{{ __('Select') }}'" class="truncate">{{ __('Select') }}</span>
            <span class="absolute inset-y-0 right-0 flex items-center pr-2 pointer-events-none">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" class="w-5 h-5 text-gray-400"><path fill-rule="evenodd" d="M10 3a.75.75 0 01.55.24l3.25 3.5a.75.75 0 11-1.1 1.02L10 4.852 7.3 7.76a.75.75 0 01-1.1-1.02l3.25-3.5A.75.75 0 0110 3zm-3.76 9.2a.75.75 0 011.06.04l2.7 2.908 2.7-2.908a.75.75 0 111.1 1.02l-3.25 3.5a.75.75 0 01-1.1 0l-3.25-3.5a.75.75 0 01.04-1.06z" clip-rule="evenodd"></path></svg>
            </span>
        </button>

        <div x-show="selectOpen"
            x-transition:enter="transition ease-out duration-50"
            x-transition:enter-start="opacity-0 -translate-y-1"
            x-transition:enter-end="opacity-100"
            x-bind:class="{ 'bottom-0 mb-10' : selectDropdownPosition == 'top', 'top-0 mt-10' : selectDropdownPosition == 'bottom' }"
            class="absolute w-full py-1 mt-1 bg-white rounded-md shadow-md ring-1 ring-black ring-opacity-5 focus:outline-none z-10"
            x-on:click.away="selectOpen = false"
            x-cloak>

            <div class="px-3 py-2">
                <label x-bind:for="selectId + '-filter'" class="sr-only">{{ __('Filter') }}</label>
                <input 
                    x-ref="filterInput"
                    x-model="filterText" 
                    x-on:input="updateFilteredItems()" 
                    x-bind:id="selectId + '-filter'"
                    type="text" 
                    placeholder="{{ __('Filter...') }}" 
                    class="w-full px-2 py-1 border rounded"
                    aria-autocomplete="list"
                    x-bind:aria-controls="selectId + '-listbox'"
                    role="combobox"
                    aria-expanded="true">
            </div>

            <ul 
                x-ref="selectableItemsList" 
                x-bind:id="selectId + '-listbox'"
                role="listbox"
                x-bind:aria-labelledby="selectId + '-label'"
                class="max-h-56 overflow-auto">
                <template x-for="[code, label] in Object.entries(filteredItems)" :key="code">
                    <li 
                        x-bind:id="code + '-' + selectId"
                        x-on:click="selectedItem = code; selectOpen = false; $refs.selectButton.focus();"
                        x-bind:class="{ 'bg-neutral-100 text-gray-900' : selectableItemIsActive(code), '' : ! selectableItemIsActive(code) }"
                        x-on:mousemove="selectableItemActive = code"
                        class="relative flex items-center h-full py-2 pl-8 text-gray-700 cursor-default select-none transition-colors duration-300 hover:bg-neutral-100"
                        role="option"
                        x-bind:aria-selected="selectedItem == code"
                        x-bind:tabindex="selectableItemIsActive(code) ? 0 : -1">
                        <svg x-show="selectedItem == code" class="absolute left-0 w-4 h-4 ml-2 stroke-current text-neutral-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"></polyline></svg>
                        <span class="block font-medium truncate" x-text="label"></span>
                    </li>
                </template>
            </ul>

        </div>

    </div>
    @if (array_key_exists('instructions', $field))
    <p id="{{ $field['handle'] }}-explanation" class="mt-2 text-gray-500">
        {{ __($field['instructions']) }}
    </p>
    @endif
    @if ($errors->has('form.' . $field['handle']))
    <p class="mt-2 text-red-600">{{ implode(', ', $errors->get('form.' . $field['handle'])) }}</p>
    @endif
</div>

@script
<script>
Alpine.data('filteredSelect', () => ({
    selectOpen: false,
    selectedItem: null,
    selectableItems: @json($this->getDictionaryItems($field['handle'])),
    filteredItems: {},
    selectableItemActive: null,
    selectId: null,
    selectKeydownValue: '',
    selectKeydownTimeout: 1000,
    selectKeydownClearTimeout: null,
    selectDropdownPosition: 'bottom',
    filterText: '',

    init() {
        this.selectId = this.$id('select');
        this.filteredItems = {...this.selectableItems};
        
        this.$watch('selectOpen', () => {
            if (!this.selectedItem) {
                this.selectableItemActive = Object.keys(this.filteredItems)[0];
            } else {
                this.selectableItemActive = this.selectedItem;
            }
            this.$nextTick(() => {
                this.selectScrollToActiveItem();
                if (this.selectOpen) {
                    this.$refs.filterInput.focus();
                }
            });
            this.selectPositionUpdate();
            window.addEventListener('resize', () => this.selectPositionUpdate());
        });
    },

    updateFilteredItems() {
        this.filteredItems = Object.fromEntries(
            Object.entries(this.selectableItems).filter(([code, label]) =>
                label.toLowerCase().includes(this.filterText.toLowerCase())
            )
        );
        this.selectableItemActive = Object.keys(this.filteredItems)[0];
    },

    selectableItemIsActive(code) {
        return this.selectableItemActive === code;
    },

    selectableItemActiveNext() {
        let keys = Object.keys(this.filteredItems);
        let index = keys.indexOf(this.selectableItemActive);
        if (index < keys.length - 1) {
            this.selectableItemActive = keys[index + 1];
            this.selectScrollToActiveItem();
        }
    },

    selectableItemActivePrevious() {
        let keys = Object.keys(this.filteredItems);
        let index = keys.indexOf(this.selectableItemActive);
        if (index > 0) {
            this.selectableItemActive = keys[index - 1];
            this.selectScrollToActiveItem();
        }
    },

    selectScrollToActiveItem() {
        if (this.selectableItemActive) {
            let activeElement = document.getElementById(this.selectableItemActive + '-' + this.selectId);
            if (activeElement) {
                let newScrollPos = (activeElement.offsetTop + activeElement.offsetHeight) - this.$refs.selectableItemsList.offsetHeight;
                if (newScrollPos > 0) {
                    this.$refs.selectableItemsList.scrollTop = newScrollPos;
                } else {
                    this.$refs.selectableItemsList.scrollTop = 0;
                }
                activeElement.focus();
            }
        }
    },

    selectKeydown(event) {
        if (event.keyCode >= 65 && event.keyCode <= 90) {
            this.selectKeydownValue += event.key;
            let selectedItemBestMatch = this.selectItemsFindBestMatch();
            if (selectedItemBestMatch) {
                if (this.selectOpen) {
                    this.selectableItemActive = selectedItemBestMatch;
                    this.selectScrollToActiveItem();
                } else {
                    this.selectedItem = this.selectableItemActive = selectedItemBestMatch;
                }
            }
            
            if (this.selectKeydownValue != '') {
                clearTimeout(this.selectKeydownClearTimeout);
                this.selectKeydownClearTimeout = setTimeout(() => {
                    this.selectKeydownValue = '';
                }, this.selectKeydownTimeout);
            }
        }
    },

    selectItemsFindBestMatch() {
        let typedValue = this.selectKeydownValue.toLowerCase();
        let bestMatch = null;
        let bestMatchIndex = -1;
        for (let [code, label] of Object.entries(this.filteredItems)) {
            let index = label.toLowerCase().indexOf(typedValue);
            if (index > -1 && (bestMatchIndex == -1 || index < bestMatchIndex)) {
                bestMatch = code;
                bestMatchIndex = index;
            }
        }
        return bestMatch;
    },

    selectPositionUpdate() {
        let selectDropdownBottomPos = this.$refs.selectButton.getBoundingClientRect().top + this.$refs.selectButton.offsetHeight + parseInt(window.getComputedStyle(this.$refs.selectableItemsList).maxHeight);
        if (window.innerHeight < selectDropdownBottomPos) {
            this.selectDropdownPosition = 'top';
        } else {
            this.selectDropdownPosition = 'bottom';
        }
    }
}));
</script>
@endscript