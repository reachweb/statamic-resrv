<div class="relative flex items-center gap-x-8">
    <div>
        <div 
            x-data="datepicker"
            class="relative"
        >
            <div class="absolute inset-y-0 start-0 flex z-1 items-center ps-3.5 pointer-events-none">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5p">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" />
                </svg>
            </div>
            <input 
                x-ref="dateInput"
                type="text" 
                placeholder="{{ trans_choice('statamic-resrv::frontend.selectDate', ($calendar === 'range') ? 2 : 1) }}"
                class="form-input min-w-[380px] h-11 bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full px-10 py-2.5"
            />
            <div 
                x-show="! isDatesEmpty"
                x-on:click="resetDates()"
                x-cloak
                class="absolute inset-y-0 end-0 flex z-1 items-center pe-3.5 cursor-pointer"
            >
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="w-6 h-6">
                    <path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M18 6l-12 12" /><path d="M6 6l12 12" />
                </svg>
            </div>
        </div>
        @if ($errors->has('data.dates.date_start') || $errors->has('data.dates.date_end'))
        <div class="mt-2 text-red-600 text-sm space-y-1">
            <span class="block">{{ $errors->first('data.dates.date_start') }}</span>
            <span class="block">{{ $errors->first('data.dates.date_end') }}</span>
        </div>
        @endif
    </div>

    @if ($advanced)
    <div>
        <select 
            id="availability-search-advanced" 
            class="form-select min-w-[200px] h-11 bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full py-2.5"
            wire:model.live="data.advanced"
        >
            <option selected value="any">{{ trans('statamic-resrv::frontend.selectProperty') }}</option>
            @foreach ($this->advancedProperties as $value => $label)
                <option value="{{ $value }}">
                    {{ $label }}                   
                </option>
            @endforeach
        </select>
        @if ($errors->has('data.advanced'))
        <div class="mt-2 text-red-600 text-sm space-y-1">
            <span class="block">{{ $errors->first('data.advanced') }}</span>
        </div>
        @endif
    </div>
    @endif

    @if ($enableQuantity)
    <div>
        <div class="relative flex items-center" x-data="{ quantity: $wire.entangle('data.quantity').live }"">
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
                x-bind:disabled="quantity === {{ $this->maxQuantity }}"
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
    @endif

    @if ($live === false)
    <div>
        <button 
            class="flex justify-center h-11 items-center text-white bg-blue-700 hover:bg-blue-800 focus:ring-4 focus:ring-blue-300 font-medium rounded-lg text-sm px-5 py-2.5 focus:outline-none disabled:opacity-50"
            wire:click="submit()"
            wire:loading.attr="disabled"
        >
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" width="18" height="18" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                <path stroke="none" d="M0 0h24v24H0z" fill="none"></path>
                <circle cx="10" cy="10" r="7"></circle>
                <line x1="21" y1="21" x2="15" y2="15"></line>
            </svg>
            <span class="uppercase pl-4">{{ trans('statamic-resrv::frontend.search') }}</span>   
        </button>
    </div>
    @endif
</div>

@script
<script>
Alpine.data('datepicker', () => ({
    mode: $wire.calendar,
    dates: $wire.data.dates,
    get isDatesEmpty() {
        return $wire.data.dates.length === 0;
    },
    init() {
        flatpickr(this.$refs.dateInput, {
            mode: this.mode ?? 'range',
            minDate: dayjs().add({{ config('resrv-config.minimum_days_before') }}, 'day').toDate(),
            defaultDate: this.mode === 'range' ? [this.dates.date_start, this.dates.date_end] : this.dates.date_start,
            onChange: (selectedDates, dateStr, instance) => {
                this.dateChanged(selectedDates);
            },
        });
    },
    dateChanged(selectedDates) {
        const dateStart = dayjs(selectedDates[0]);
        if (this.mode === 'range' && selectedDates.length === 2) {
            $wire.set('data.dates', {
                date_start: dateStart.format(),
                date_end: dayjs(selectedDates[1]).format(),
            });
        }
        if (this.mode === 'single') {
            // Add a day to the selected date for single mode
            const dateEnd = dateStart.add(1, 'day');
            $wire.set('data.dates', {
                date_start: dateStart.format(),
                date_end: dateEnd.format(),
            });
        }
    },
    resetDates() {
        this.$refs.dateInput._flatpickr.setDate([], false);
        $wire.clearDates();
        $dispatch('availability-search-cleared');
    },
}));
</script>
@endscript

