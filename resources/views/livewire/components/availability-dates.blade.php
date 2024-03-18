@props(['calendar', 'errors'])

<div class="{{ $attributes->get('class') }}">
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