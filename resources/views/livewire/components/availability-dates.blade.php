@props(['calendar', 'disabledDays' => false, 'errors'])

<div class="{{ $attributes->get('class') }}">
    <div 
        x-data="datepicker"
        x-resize.document="isMobile = $width < 390"
        class="relative w-full"
    >
        <div class="relative">
            <div class="absolute inset-y-0 start-0 flex z-1 items-center ps-3.5 pointer-events-none">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" />
                </svg>
            </div>
            <input 
                x-on:click="openCalendar()"
                name="datepicker"
                type="text" 
                readonly
                x-bind:value="displayDate"
                x-ref="dateInput"
                placeholder="{{ trans_choice('statamic-resrv::frontend.selectDate', ($calendar === 'range') ? 2 : 1) }}"
                class="form-input h-11 bg-gray-50 border border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full px-10 py-2.5"
            />
            <div x-show="! isDatesEmpty" x-cloak class="absolute inset-y-0 end-0 flex z-10 items-center pe-3.5">
                <button 
                    type="button" 
                    x-on:click.stop="clearSelection()"
                    class="cursor-pointer"
                    aria-label="Clear selection"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5 text-gray-500 hover:text-gray-700">
                        <path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M18 6l-12 12" /><path d="M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <div 
                x-data="{ loading: false }" 
                x-on:availability-search-updated.window="loading = true" 
                x-on:availability-results-updated.window="setTimeout(() => {loading = false}, 300)"
            >
                <span x-show="loading === true" class="absolute left-0 right-0 top-0 flex items-center justify-center w-full h-full bg-white/50 ">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="animate-spin w-5 h-5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                    </svg>   
                </span>
            </div>
        </div>

        <div
            x-show="isModalOpen"
            x-transition:enter="ease-out duration-300"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="ease-in duration-200"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            x-cloak
            class="fixed inset-0 z-[300] flex items-center justify-center p-3 md:p-4 bg-black bg-opacity-50"
            wire:ignore
        >
            <div
                x-on:click.outside="isModalOpen = false"
                x-show="isModalOpen"
                x-transition:enter="ease-out duration-300"
                x-transition:enter-start="opacity-0 scale-95"
                x-transition:enter-end="opacity-100 scale-100"
                x-transition:leave="ease-in duration-200"
                x-transition:leave-start="opacity-100 scale-100"
                x-transition:leave-end="opacity-0 scale-95"
                class="relative flex flex-col w-full max-h-full bg-white rounded-lg shadow lg:w-auto md:h-auto lg:max-w-3xl"
            >
                <div class="flex items-center justify-between px-4 py-2 lg:py-4 border-b">
                    <h3 class="text-lg font-semibold">{{ trans_choice('statamic-resrv::frontend.selectDate', ($calendar === 'range') ? 2 : 1) }}</h3>
                    <button x-on:click="isModalOpen = false" class="p-1 rounded-full hover:bg-gray-200">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="max-h-full xl:p-2 pb-12 lg:pb-0 overflow-y-auto">
                    <div x-ref="calendarContainer"></div>
                </div>               
            </div>
        </div>
        
        @if ($errors->has('data.dates') || $errors->has('data.dates.date_start') || $errors->has('data.date_end'))
        <div class="bg-white border-zinc-100 rounded-md px-4 py-2 shadow text-red-600 text-sm space-y-1 z-10" x-anchor.offset.10="$refs.dateInput">
            <span class="block">{{ $errors->first('data.dates') }}</span>
            <span class="block">{{ $errors->first('data.dates.date_start') }}</span>
            <span class="block">{{ $errors->first('data.dates.date_end') }}</span>
        </div>
        @endif
    </div>
</div>

@script
<script>
Alpine.data('datepicker', () => ({
    // Livewire & Config Properties
    mode: $wire.calendar,
    dates: $wire.data.dates, 
    advanced: $wire.advanced,
    advancedSelected: $wire.entangle('data.advanced'),
    minPeriod: {{ config('resrv-config.minimum_reservation_period_in_days', 0) }},
    maxPeriod: {{ config('resrv-config.maximum_reservation_period_in_days', 30) }},
    disabledDays: @json($disabledDays),
    showAvailabilityOnCalendar: $wire.showAvailabilityOnCalendar,
    availabilityCalendar: [],
    
    // UI State
    isMobile: true,
    isModalOpen: false,
    calendar: null,

    // Computed property to display the formatted date
    get displayDate() {
        if (this.isDatesEmpty) return '';
        let start = dayjs(this.dates.date_start).format('DD MMM YYYY');
        if (this.mode === 'range' && this.dates.date_end) {
            let end = dayjs(this.dates.date_end).format('DD MMM YYYY');
            return `${start} to ${end}`;
        }
        return start;
    },

    get isDatesEmpty() {
        return !this.dates || Object.keys(this.dates).length === 0 || (!this.dates.date_start && !this.dates.date_end);
    },

    init() {
        this.$watch('advancedSelected', async () => {
            if (this.calendar) {
                this.availabilityCalendar = await this.fetchAvailability();
                this.calendar.update({ dates: false });
            }
        });
    },

    async openCalendar() {
        this.isModalOpen = true;
        if (!this.calendar) {
            if (this.showAvailabilityOnCalendar) {
                this.availabilityCalendar = await this.fetchAvailability();
            }
            this.$nextTick(() => {
                this.initializeCalendar();
            });
        }
    },
    
    async fetchAvailability() {
        if (this.advanced !== false && this.advancedSelected === null) {
            return [];
        }
        return await $wire.availabilityCalendar();
    },

    initializeCalendar() {
        const minDate = dayjs().add({{ config('resrv-config.minimum_days_before') }}, 'day').format('YYYY-MM-DD');

        this.calendar = new window.calendar(this.$refs.calendarContainer, {
            type: this.isMobile ? 'default' : 'multiple',
            dateMin: minDate,
            selectionDatesMode: this.mode === 'range' ? 'multiple-ranged' : 'single',
            selectedDates: this.getInitialDates(),
            selectedTheme: 'light',
            selectedWeekends: [],
            displayDatesOutside: true,
            enableJumpToSelectedDate: true,
            
            onCreateDateEls: (self, dateEl) => {
                if (this.showAvailabilityOnCalendar) this.addPriceToEachDate(dateEl);
                if (this.disabledDays) this.disableDays(dateEl);
            },
            
            onClickDate: (self, event) => {
                if (this.mode === 'range') this.handleRanges(self, event);
                this.dateChanged(self.context.selectedDates);
            },
        });
        
        this.calendar.init();
    },
    
    getInitialDates() {
        if (this.isDatesEmpty) return [];
        let date_start = dayjs(this.dates.date_start).format('YYYY-MM-DD');
        if (this.mode === 'range' && this.dates.date_end) {
            let date_end = dayjs(this.dates.date_end).format('YYYY-MM-DD');
            return [date_start + ',' + date_end];
        }
        return [date_start];
    },

    dateChanged(selectedDates) {
        if (!selectedDates || selectedDates.length === 0) {
            this.clearSelection();
            return;
        };

        const dateStart = dayjs(selectedDates[0]);
        let newDates = {};
        
        if (this.mode === 'range' && selectedDates.length === 2) {
            newDates = {
                date_start: dateStart.format(),
                date_end: dayjs(selectedDates[1]).format()
            };
            this.isModalOpen = false;
        } else if (this.mode === 'single') {
            const dateEnd = dateStart.add(1, 'day');
            newDates = {
                date_start: dateStart.format(),
                date_end: dateEnd.format()
            };
            this.isModalOpen = false;
        }

        if (newDates.date_start && (this.mode === 'single' || newDates.date_end)) {
            this.dates = newDates;
            $wire.set('data.dates', newDates);
            this.isModalOpen = false;
        }        
    },

    clearSelection() {
        if (this.calendar) {
            this.calendar.set({ selectedDates: [] });
        }
        this.dates = {};
        this.isModalOpen = false;
        $wire.set('data.dates', {});
        $dispatch('availability-search-cleared');
    },

    addPriceToEachDate(dateEl) {
        const button = dateEl.querySelector('button');
        const date = dateEl.dataset.vcDate;

        if (Object.values(this.availabilityCalendar).length > 0) {
            if (this.availabilityCalendar[date] === undefined || this.availabilityCalendar[date] === null || this.availabilityCalendar[date]?.available === 0) {
                button.style.cssText = 'cursor: default; opacity: 0.5; text-decoration: line-through;';
                button.onclick = (event) => event.stopPropagation();
                return;
            }
            button.insertAdjacentHTML(
                'beforeend', 
                `<span class="px-1 vc-price">{{ config('resrv-config.currency_symbol') }}${Math.round(this.availabilityCalendar[date]?.price)}</span>`
            );
        } 
    },

    disableDays(dateEl) {
        if (!this.disabledDays.includes(dateEl.dataset.vcDateWeekDay)) return;
        dateEl.onclick = (event) => event.stopPropagation();
        dateEl.style.cssText = 'cursor: default; opacity: 0.5;';
    },

    handleRanges(self, event) {
        const dateBtnEl = event.target;
        const minPeriod = this.minPeriod;
        const maxPeriod = this.maxPeriod;

        if (self.context.selectedDates.length === 1) {
            const dateStr = dateBtnEl.parentElement.dataset.vcDate;
            const date = dayjs(dateStr);
            const minDate = date.add(minPeriod, 'days');
            const maxDate = date.add(maxPeriod, 'days');
            
            self.set(
                { 
                    disableAllDates: true, 
                    enableDates: [`${minDate.format('YYYY-MM-DD')}:${maxDate.format('YYYY-MM-DD')}`] 
                },
                { dates: false, month: false, year: false, locale: false }
            );
        } else if (self.context.selectedDates.length === 0) {
             self.set(
                { disableAllDates: false, enableDates: [] },
                { dates: false, month: false, year: false, locale: false }
            );
        }
    },
}));
</script>
@endscript