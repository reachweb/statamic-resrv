@props(['calendar', 'disabledDays' => false, 'errors'])

<div class="{{ $attributes->get('class') }}">
    <div x-data="datepicker" class="relative w-full">

        <div wire:ignore>
            <div
                x-data="calendar({
                    mode: '{{ $calendar === 'range' ? 'range' : 'single' }}',
                    display: 'popup',
                    format: 'DD MMM YYYY',
                    inputRef: 'dateInput',
                    months: 2,
                    mobileMonths: 1,
                    minDate: dayjs().add({{ config('resrv-config.minimum_days_before') }}, 'day').format('YYYY-MM-DD'),
                    @if ($calendar === 'range')
                    minRange: {{ config('resrv-config.minimum_reservation_period_in_days', 0) }},
                    maxRange: {{ config('resrv-config.maximum_reservation_period_in_days', 30) }},
                    @endif
                    disabledDaysOfWeek: buildDisabledDaysOfWeek(),
                    dateMetadata: buildDateMetadata(),
                    value: getInitialValue(),
                })"
                x-ref="calendarInstance"
                @calendar:change="dateChanged($event.detail)"
                @calendar:open="onCalendarOpen()"
            >
                <div class="relative">
                    <div class="absolute inset-y-0 start-0 flex z-1 items-center ps-3.5 pointer-events-none">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" />
                        </svg>
                    </div>
                    <input
                        name="datepicker"
                        type="text"
                        readonly
                        x-ref="dateInput"
                        placeholder="{{ trans_choice('statamic-resrv::frontend.selectDate', ($calendar === 'range') ? 2 : 1) }}"
                        class="form-input h-11 bg-gray-50 border border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full px-10 py-2.5 cursor-pointer"
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
            </div>
        </div>

        @if ($errors->has('data.dates') || $errors->has('data.dates.date_start') || $errors->has('data.date_end'))
        <div class="bg-white border-zinc-100 rounded-md px-4 py-2 shadow text-red-600 text-sm space-y-1 z-10" x-anchor.offset.10="$refs.dateInput" x-show="!isDatesEmpty">
            <span class="block">{{ $errors->first('data.dates') }}</span>
            <span class="block">{{ $errors->first('data.dates.date_start') }}</span>
            <span class="block">{{ $errors->first('data.dates.date_end') }}</span>
        </div>
        @endif
    </div>
</div>

<style>
    .rc-day__label, .rc-day__dot {
        animation: rc-fade-in 0.3s ease-out;
    }
    @keyframes rc-fade-in {
        from { opacity: 0; transform: translateY(2px); }
        to { opacity: 1; transform: translateY(0); }
    }
    .rc-popup-overlay {
        z-index: 300 !important;
    }
</style>

@script
<script>
Alpine.data('datepicker', () => ({
    // Livewire & Config Properties
    mode: $wire.calendar,
    dates: $wire.data.dates,
    advanced: $wire.advanced,
    advancedSelected: $wire.entangle('data.advanced'),
    disabledDays: @json($disabledDays),
    showAvailabilityOnCalendar: $wire.showAvailabilityOnCalendar,
    availabilityCalendar: [],

    get isDatesEmpty() {
        return !this.dates || Object.keys(this.dates).length === 0 || (!this.dates.date_start && !this.dates.date_end);
    },

    init() {
        this.$watch('advancedSelected', async () => {
            if (!this.showAvailabilityOnCalendar) return;
            // Skip if user has never opened the calendar yet — onCalendarOpen
            // will fetch with the latest advancedSelected on first open.
            if (!this.availabilityCalendar || Object.keys(this.availabilityCalendar).length === 0) return;
            this.availabilityCalendar = await this.fetchAvailability();
            const cal = Alpine.$data(this.$refs.calendarInstance);
            cal?.updateDateMetadata?.(this.buildDateMetadata());
        });
    },

    async onCalendarOpen() {
        if (!this.showAvailabilityOnCalendar) return;
        if (this.availabilityCalendar && Object.keys(this.availabilityCalendar).length > 0) return;
        this.availabilityCalendar = await this.fetchAvailability();
        const cal = Alpine.$data(this.$refs.calendarInstance);
        cal?.updateDateMetadata?.(this.buildDateMetadata());
    },

    async fetchAvailability() {
        if (this.advanced !== false && this.advancedSelected === null) {
            return [];
        }
        return await $wire.availabilityCalendar();
    },

    getInitialValue() {
        if (this.isDatesEmpty) return '';
        let start = dayjs(this.dates.date_start).format('YYYY-MM-DD');
        if (this.mode === 'range' && this.dates.date_end) {
            return start + ' - ' + dayjs(this.dates.date_end).format('YYYY-MM-DD');
        }
        return start;
    },

    buildDateMetadata() {
        if (!this.availabilityCalendar || Object.keys(this.availabilityCalendar).length === 0) return {};
        return Object.fromEntries(
            Object.entries(this.availabilityCalendar).map(([date, info]) => [
                date,
                {
                    label: info?.available > 0 ? '{{ config('resrv-config.currency_symbol') }}' + Math.round(info.price) : '',
                    availability: (!info || info.available === 0) ? 'unavailable' : 'available',
                }
            ])
        );
    },

    buildDisabledDaysOfWeek() {
        if (!this.disabledDays) return [];
        const dayMap = { 'Sunday': 0, 'Monday': 1, 'Tuesday': 2, 'Wednesday': 3, 'Thursday': 4, 'Friday': 5, 'Saturday': 6 };
        return this.disabledDays.map(d => dayMap[d]).filter(d => d !== undefined);
    },

    dateChanged(detail) {
        const isoDates = detail.dates;

        if (!isoDates || isoDates.length === 0) {
            // Empty selection — short-circuit if already empty to avoid double-firing
            // when clearSelection() triggers calendar:change.
            if (this.isDatesEmpty) return;
            this.dates = {};
            $wire.clearDates();
            $dispatch('availability-search-cleared');
            return;
        }

        const dateStart = dayjs(isoDates[0]);
        let newDates = {};

        if (this.mode === 'range' && isoDates.length === 2) {
            newDates = {
                date_start: dateStart.format(),
                date_end: dayjs(isoDates[1]).format()
            };
        } else if (this.mode === 'single') {
            newDates = {
                date_start: dateStart.format(),
                date_end: dateStart.add(1, 'day').format()
            };
        }

        if (newDates.date_start && (this.mode === 'single' || newDates.date_end)) {
            this.dates = newDates;
            $wire.set('data.dates', newDates);
            // Calendar auto-closes via closeOnSelect (default true for single/range)
        }
    },

    clearSelection() {
        // Calendar's clear() emits @calendar:change with empty dates →
        // dateChanged() handles the Livewire side. Single source of truth.
        const cal = Alpine.$data(this.$refs.calendarInstance);
        cal?.clear?.();
        cal?.close?.();
    },
}));
</script>
@endscript
