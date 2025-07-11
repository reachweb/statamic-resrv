<template>
    <element-container @resized="containerWidth = $event.width">
        <div class="w-full h-full text-center my-4 text-gray-700 dark:text-dark-100 text-lg" v-if="newItem">
            {{ __('You need to save this entry before you can add cutoff rules.') }}
        </div>
        <div class="statamic-resrv-cutoff relative" v-else>
            <div class="flex items-center py-1 my-4 border-b border-t dark:border-gray-500">
                <span class="font-bold mr-4">{{ __('Enable Cutoff Rules') }}</span>    
                <toggle-input v-model="enabled" @input="toggleCutoff" />
            </div>
            
            <div v-if="enabled" class="space-y-6">
                <div class="bg-gray-300 border border-gray-400 rounded-md p-3">
                    <div class="flex items-center">
                        <div class="flex-shrink-0 flex items-center">
                            <svg class="h-5 w-5 text-gray-800" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                            </svg>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm text-gray-800">
                                {{ __('Cutoff times are checked against server time') }}: <strong>{{ getServerTime() }}</strong>
                                <span class="text-xs block mt-1">{{ __('Server timezone') }}: {{ getServerTimezone() }}</span>
                            </p>
                        </div>
                    </div>
                </div>

                <div>
                    <h3 class="text-lg font-semibold mb-4">{{ __('Default Settings') }}</h3>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium mb-2">{{ __('Default Starting Time') }}</label>
                            <input 
                                v-model="settings.default_starting_time"
                                type="time"
                                class="input-text"
                            />
                            <p class="text-xs text-gray-800 dark:text-dark-150 mt-1">{{ __('When does your activity/service typically start?') }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-2">{{ __('Default Cutoff Hours') }}</label>
                            <input 
                                v-model="settings.default_cutoff_hours"
                                type="number"
                                min="0"
                                max="240"
                                class="input-text"
                            />
                            <p class="text-xs text-gray-800 dark:text-dark-150 mt-1">{{ __('Hours before starting time to stop accepting bookings') }}</p>
                        </div>
                    </div>
                </div>
                
                <div class="pt-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold">{{ __('Schedules') }}</h3>
                        <button 
                            type="button" 
                            class="btn btn-sm"
                            @click="addSchedule"
                        >
                            {{ __('Add Schedule') }}
                        </button>
                    </div>
                    
                    <p class="text-sm text-gray-800 dark:text-dark-150 dark:text-gray-400 mb-4">
                        {{ __('Configure different starting times and cutoff periods for specific date ranges.') }}
                    </p>
                    
                    <div v-if="settings.schedules && settings.schedules.length > 0" class="space-y-4">
                        <div 
                            v-for="(schedule, index) in settings.schedules" 
                            :key="index"
                            class="border rounded-lg p-4 bg-gray-50 dark:bg-dark-600 dark:border-dark-500"
                        >
                            <div class="flex items-center justify-end mb-3">
                                <button 
                                    type="button"
                                    class="btn-close group"
                                    @click="removeSchedule(index)"
                                >
                                    <svg viewBox="0 0 16 16" class="w-4 h-4 group-hover:text-red">
                                        <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" d="M1 3h14M9.5 1h-3a1 1 0 0 0-1 1v1h5V2a1 1 0 0 0-1-1zm-3 10.5v-5m3 5v-5m3.077 7.583a1 1 0 0 1-.997.917H4.42a1 1 0 0 1-.996-.917L2.5 3h11l-.923 11.083z"></path>
                                    </svg>
                                </button>
                            </div>
                            
                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                                <div class="lg:col-span-2">
                                    <label class="block text-sm font-medium mb-2 text-gray-800 dark:text-dark-150">{{ __('Date Range') }}</label>
                                    <div class="date-container input-group w-full">
                                        <v-date-picker
                                            v-model="schedule.dateRange"
                                            :popover="{ visibility: 'click' }"
                                            :masks="{ input: 'YYYY-MM-DD' }"
                                            :mode="'date'"
                                            :columns="$screens({ default: 1, lg: 2 })"
                                            is-range
                                            @input="updateScheduleDates(schedule, index)"
                                        >
                                            <template v-slot="{ inputValue, inputEvents }">
                                                <div class="w-full flex items-center">
                                                    <div class="input-group">
                                                        <div class="input-group-prepend flex items-center">
                                                            <svg-icon name="light/calendar" class="w-4 h-4" />
                                                        </div>
                                                        <div class="input-text border-l-0">
                                                            <input
                                                                class="input-text-minimal p-0 bg-transparent leading-none"
                                                                :value="inputValue.start"
                                                                v-on="inputEvents.start"
                                                                placeholder="Start Date"
                                                            />
                                                        </div>
                                                    </div>
                                                    <div class="icon icon-arrow-right my-sm mx-1 text-gray-600" />
                                                    <div class="input-group">
                                                        <div class="input-group-prepend flex items-center">
                                                            <svg-icon name="light/calendar" class="w-4 h-4" />
                                                        </div>
                                                        <div class="input-text border-l-0">
                                                            <input
                                                                class="input-text-minimal p-0 bg-transparent leading-none"
                                                                :value="inputValue.end"
                                                                v-on="inputEvents.end"
                                                                placeholder="End Date"
                                                            />
                                                        </div>
                                                    </div>
                                                </div>
                                            </template>
                                        </v-date-picker>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium mb-1 text-gray-800 dark:text-dark-150">{{ __('Starting Time') }}</label>
                                    <input 
                                        v-model="schedule.starting_time"
                                        type="time"
                                        class="input-text"
    
                                    />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium mb-1 text-gray-800 dark:text-dark-150">{{ __('Cutoff Hours') }}</label>
                                    <input 
                                        v-model="schedule.cutoff_hours"
                                        type="number"
                                        min="0"
                                        max="240"
                                        class="input-text"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div v-else class="text-center py-8 text-gray-700 dark:text-gray-400">
                        {{ __('No schedules configured. Use default settings for all dates.') }}
                    </div>
                </div>
            </div>
        </div>
    </element-container>
</template>

<script>
export default {
    mixins: [Fieldtype],

    data() {
        return {
            containerWidth: null,
            enabled: false,
            settings: {
                enable_cutoff: false,
                default_starting_time: '16:00',
                default_cutoff_hours: 3,
                schedules: []
            }
        }
    },

    computed: {
        newItem() {
            return this.meta.parent === 'Collection'
        }
    },

    mounted() {
        this.loadExistingSettings()
        if (!this.newItem) {
            this.updateFieldValue()
        }
    },

    methods: {
        loadExistingSettings() {
            if (this.value && typeof this.value === 'object') {
                this.settings = { ...this.settings, ...this.value }
                this.enabled = this.settings.enable_cutoff || false
                
                // Set up date ranges for existing schedules
                if (this.settings.schedules) {
                    this.settings.schedules.forEach(schedule => {
                        if (schedule.date_start && schedule.date_end) {
                            schedule.dateRange = {
                                start: new Date(schedule.date_start),
                                end: new Date(schedule.date_end)
                            }
                        }
                    })
                }
            } else {
                this.enabled = false
            }
        },

        toggleCutoff() {
            this.settings.enable_cutoff = this.enabled
            this.updateFieldValue()
        },

        addSchedule() {
            if (!this.settings.schedules) {
                this.settings.schedules = []
            }
            
            this.settings.schedules.push({
                date_start: '',
                date_end: '',
                dateRange: null,
                starting_time: this.settings.default_starting_time,
                cutoff_hours: this.settings.default_cutoff_hours
            })
            
            this.updateFieldValue()
        },

        updateScheduleDates(schedule, index) {
            if (schedule.dateRange && schedule.dateRange.start && schedule.dateRange.end) {
                schedule.date_start = this.formatDate(schedule.dateRange.start)
                schedule.date_end = this.formatDate(schedule.dateRange.end)
                this.updateFieldValue()
            }
        },

        formatDate(date) {
            if (!date) return ''
            const d = new Date(date)
            return d.getFullYear() + '-' + 
                   String(d.getMonth() + 1).padStart(2, '0') + '-' + 
                   String(d.getDate()).padStart(2, '0')
        },

        getServerTime() {
            return this.meta.server_time
        },

        getServerTimezone() {
            return this.meta.server_timezone
        },

        removeSchedule(index) {
            this.settings.schedules.splice(index, 1)
            this.updateFieldValue()
        },

        updateFieldValue() {
            // Clean up the data before saving - remove dateRange objects
            const cleanSettings = { ...this.settings }
            if (cleanSettings.schedules) {
                cleanSettings.schedules = cleanSettings.schedules.map(schedule => {
                    const { dateRange, ...cleanSchedule } = schedule
                    return cleanSchedule
                })
            }
            
            const value = this.enabled ? cleanSettings : null
            this.$emit('input', value)
        }
    },

    watch: {
        settings: {
            handler() {
                if (this.enabled) {
                    this.updateFieldValue()
                }
            },
            deep: true
        }
    }
}
</script>
