@props(['calendar', 'disabledDays' => false, 'errors'])

<div class="{{ $attributes->get('class') }}">
    <div 
        x-data="datepicker"
        class="relative"
    >
        <div class="absolute inset-y-0 start-0 flex z-1 items-center ps-3.5 pointer-events-none">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" />
            </svg>
        </div>
        <input 
            x-ref="dateInput"
            type="text" 
            placeholder="{{ trans_choice('statamic-resrv::frontend.selectDate', ($calendar === 'range') ? 2 : 1) }}"
            class="form-input h-11 bg-gray-50 border border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full px-10 py-2.5"
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
        @if ($errors->has('data.dates') || $errors->has('data.dates.date_start') || $errors->has('data.date_end'))
        <div class="bg-white border-zinc-100 rounded-md px-4 py-2 shadow text-red-600 text-sm space-y-1" x-anchor.offset.10="$refs.dateInput">
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
    mode: $wire.calendar,
    dates: $wire.data.dates,
    advanced: $wire.advanced,
    advancedSelected: $wire.entangle('data.advanced'),
    calendar: null,
    minPeriod: {{ config('resrv-config.minimum_reservation_period_in_days', 0) }},
    maxPeriod: {{ config('resrv-config.maximum_reservation_period_in_days', 30) }},
    disabledDays: @json($disabledDays),
    showAvailaiblityOnCalendar: $wire.showAvailaiblityOnCalendar,
    availabilityCalendar: [],
    
    get isDatesEmpty() {
        return $wire.data.dates.length === 0;
    },

    async init() {
        const minDate = dayjs().add({{ config('resrv-config.minimum_days_before') }}, 'day').format('YYYY-MM-DD');

        if (this.showAvailaiblityOnCalendar !== false) {
            if (this.advanced !== false && this.advancedSelected === null) {
                this.availabilityCalendar = [];
            } else {
                this.availabilityCalendar = await $wire.availabilityCalendar();
            }            
        }

        this.$watch('advancedSelected', async (value, oldValue) => {
            if (this.showAvailaiblityOnCalendar !== false && value !== null) {
                this.availabilityCalendar = await $wire.availabilityCalendar();    
            } else if (this.showAvailaiblityOnCalendar !== false && value === null) {
                this.availabilityCalendar = [];
            }
            this.calendar.update({
                dates: false,
            });
        });
        
        this.calendar = new window.calendar(this.$refs.dateInput, {
            type: this.mode === 'range' ? 'multiple' : 'default',
            inputMode: true,
            dateMin: minDate,
            selectionDatesMode: this.mode === 'range' ? 'multiple-ranged' : 'single',
            selectedDates: this.getInitialDates(),
            selectedWeekends: [],
            selectedTheme: 'light',
            displayDatesOutside: true,
            enableJumpToSelectedDate: true,
            positionToInput: 'auto',

            onCreateDateEls: (self, dateEl) => {
                if (this.showAvailaiblityOnCalendar !== false) {
                    this.addPriceToEachDate(dateEl);
                }
                if (this.disabledDays !== false) {
                    this.disableDays(dateEl);
                }
            },

            onChangeToInput: (self, event) => {
                if (! self.context.inputElement) return;
                if (this.mode === 'range' && self.context.selectedDates[0] && self.context.selectedDates[1]) {
                    self.context.inputElement.value = self.context.selectedDates[0]+' to '+self.context.selectedDates[1];
                    self.hide();
                } else if (this.mode === 'single' && self.context.selectedDates[0]) {
                    self.context.inputElement.value = self.context.selectedDates[0];
                    self.hide();
                } else {
                    self.context.inputElement.value = '';
                }
            },
            
            onClickDate: (self, event) => {
                if (this.mode === 'range') {
                    this.handleRanges(self, event);
                }
                
                this.dateChanged(self.context.selectedDates);
            },

            onUpdate(self) {
                //self.set({ selectedDates: [] });
            }
        });

        this.calendar.init();
    },

    getInitialDates() {
        if (! (this.dates.date_start || this.dates.date_end)) return [];
        let date_start = dayjs(this.dates.date_start).format('YYYY-MM-DD');
        let date_end = dayjs(this.dates.date_end).format('YYYY-MM-DD');
        if (this.mode === 'range') {
            this.$refs.dateInput.value = date_start+' to '+date_end;
            return [date_start+','+date_end];
        } else {
            this.$refs.dateInput.value = date_start;
            return [date_start];
        }
        return [];
    },

    dateChanged(selectedDates) {
        if (!selectedDates || selectedDates.length === 0) return;

        const dateStart = dayjs(selectedDates[0]);
        
        if (this.mode === 'range' && selectedDates.length === 2) {
            $wire.set('data.dates', {
                date_start: dateStart.format(),
                date_end: dayjs(selectedDates[1]).format()
            });
        }
        
        if (this.mode === 'single') {
            const dateEnd = dateStart.add(1, 'day');
            $wire.set('data.dates', {
                date_start: dateStart.format(),
                date_end: dateEnd.format()
            });
        }
    },

    addPriceToEachDate(dateEl) {
        const button = dateEl.querySelector('button');
        const date = dateEl.dataset.vcDate;

        if (Object.values(this.availabilityCalendar).length > 0) {
            if (this.availabilityCalendar[date] === undefined || this.availabilityCalendar[date]?.available === 0) {
                button.style.cssText = 'cursor: default; opacity: 0.5; text-decoration: line-through;';
                button.onclick = (event) => event.stopPropagation();
                return;
            }
            button.insertAdjacentHTML(
                'beforeend', 
                `<span class="vc-price">{{ config('resrv-config.currency_symbol') }}${Math.round(this.availabilityCalendar[date]?.price)}</span>`
            );
        } 
    },

    disableDays(dateEl) {
        if (! this.disabledDays.includes(dateEl.dataset.vcDateWeekDay)) return;
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
        }

        if (! self.context.selectedDates[1]) return;
        
        self.set(
            { disableAllDates: false, enableDates: [] },
            { dates: false, month: false, year: false, locale: false }
        );
    },

    resetDates() {
        this.calendar.set({ selectedDates: [] });
        this.calendar.update({
            dates: true,
        });
        this.$refs.dateInput.value = '';
        $wire.clearDates();
        $dispatch('availability-search-cleared');
    },
}));
</script>
@endscript