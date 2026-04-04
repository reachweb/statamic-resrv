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
                x-on:keydown.escape.window="isModalOpen = false"
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
                    <template x-if="calendarReady">
                        <div
                            x-data="calendar({
                                mode: mode === 'range' ? 'range' : 'single',
                                months: 2,
                                mobileMonths: 1,
                                minDate: dayjs().add({{ config('resrv-config.minimum_days_before') }}, 'day').format('YYYY-MM-DD'),
                                minRange: mode === 'range' ? {{ config('resrv-config.minimum_reservation_period_in_days', 0) }} : undefined,
                                maxRange: mode === 'range' ? {{ config('resrv-config.maximum_reservation_period_in_days', 30) }} : undefined,
                                disabledDaysOfWeek: buildDisabledDaysOfWeek(),
                                dateMetadata: buildDateMetadata(),
                                value: getInitialValue(),
                            })"
                            x-ref="calendarInstance"
                            @calendar:change="dateChanged($event.detail)"
                        >
                        </div>
                    </template>
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

    // UI State
    isMobile: true,
    isModalOpen: false,
    calendarReady: false,

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
            this.availabilityCalendar = await this.fetchAvailability();
            const calendarEl = this.$refs.calendarInstance;
            if (calendarEl && calendarEl._x_dataStack) {
                Alpine.$data(calendarEl).updateDateMetadata(this.buildDateMetadata());
            }
        });
    },

    async openCalendar() {
        if (!this.calendarReady) {
            if (this.showAvailabilityOnCalendar) {
                this.availabilityCalendar = await this.fetchAvailability();
            }
            this.calendarReady = true;
        }
        this.isModalOpen = true;
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
        const selectedDates = detail.dates;
        if (!selectedDates || selectedDates.length === 0) return;

        const dateStart = dayjs(selectedDates[0].toString());
        let newDates = {};

        if (this.mode === 'range' && selectedDates.length === 2) {
            newDates = {
                date_start: dateStart.format(),
                date_end: dayjs(selectedDates[1].toString()).format()
            };
        } else if (this.mode === 'single') {
            const dateEnd = dateStart.add(1, 'day');
            newDates = {
                date_start: dateStart.format(),
                date_end: dateEnd.format()
            };
        }

        if (newDates.date_start && (this.mode === 'single' || newDates.date_end)) {
            this.dates = newDates;
            $wire.set('data.dates', newDates);
            this.isModalOpen = false;
        }
    },

    clearSelection() {
        const calendarEl = this.$refs.calendarInstance;
        if (calendarEl && calendarEl._x_dataStack) {
            Alpine.$data(calendarEl).clearSelection();
        }
        this.dates = {};
        this.isModalOpen = false;
        $wire.clearDates();
        $dispatch('availability-search-cleared');
    },
}));
</script>
@endscript