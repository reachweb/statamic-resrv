<div 
    x-data="datepicker"
    class="relative"
>
    <input 
        x-ref="dateInput"
        type="text" 
        class="min-w-[380px] form-input bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5"
    />
    <div 
        x-show="! isDatesEmpty"
        x-on:click="resetDates()"
        x-cloak
        class="absolute right-0 top-1/2 -translate-y-1/2 cursor-pointer px-2"
    >
        <svg xmlns="http://www.w3.org/2000/svg"  width="24"  height="24"  viewBox="0 0 24 24"  fill="none"  stroke="currentColor"  stroke-width="2"  stroke-linecap="round"  stroke-linejoin="round"  class="icon icon-tabler icons-tabler-outline icon-tabler-x"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M18 6l-12 12" /><path d="M6 6l12 12" /></svg>
    </div>
</div>

@script
<script>
Alpine.data('datepicker', () => {
    return {
        mode: $wire.mode,
        dates: $wire.data.dates,
        get isDatesEmpty() {
            return $wire.data.dates.length === 0;
        },
        init() {
            flatpickr(this.$refs.dateInput, {
                mode: this.mode,
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
            this.$refs.dateInput._flatpickr.clear();
            $wire.clear();
            $dispatch('availability-search-cleared');
        },
    }
});
</script>
@endscript