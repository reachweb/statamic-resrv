<div 
    x-data="datepicker"
    class="flex"
>
    <input 
        x-ref="dateInput"
        type="text" 
        class="min-w-[380px] form-input bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5"
    />
</div>

@script
<script>
Alpine.data('datepicker', () => {
    return {
        mode: $wire.mode,
        dates: $wire.data.dates,
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
    }
});
</script>
@endscript