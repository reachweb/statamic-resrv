<template>
    <element-container @resized="containerWidth = $event.width">
        <div class="w-full h-full text-center my-4 text-gray-700 dark:text-dark-100 text-lg" v-if="newItem">
            {{ __('You need to save this entry before you can add cutoff rules.') }}
        </div>
        <div class="statamic-resrv-cutoff relative" v-else>
            <div class="flex items-center py-1 my-4 border-b border-t dark:border-gray-500">
                <span class="font-bold mr-4">{{ __('Enable Cutoff Rules') }}</span>    
                <toggle v-model="enabled" @input="toggleCutoff" />
            </div>
            
            <div v-if="enabled" class="space-y-6">
                <!-- Default Settings -->
                <div class="card p-4">
                    <h3 class="text-lg font-semibold mb-4">{{ __('Default Settings') }}</h3>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium mb-2">{{ __('Default Starting Time') }}</label>
                            <time-fieldtype 
                                v-model="settings.default_starting_time"
                                :config="{ format: 'H:mm' }"
                            />
                            <p class="text-xs text-gray-500 mt-1">{{ __('When does your activity/service typically start?') }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-2">{{ __('Default Cutoff Hours') }}</label>
                            <text-fieldtype 
                                v-model="settings.default_cutoff_hours"
                                :config="{ type: 'number', min: 0, max: 72 }"
                            />
                            <p class="text-xs text-gray-500 mt-1">{{ __('Hours before starting time to stop accepting bookings') }}</p>
                        </div>
                    </div>
                </div>
                
                <!-- Seasonal Schedules -->
                <div class="card p-4">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold">{{ __('Seasonal Schedules') }}</h3>
                        <button 
                            type="button" 
                            class="btn btn-sm"
                            @click="addSchedule"
                        >
                            {{ __('Add Schedule') }}
                        </button>
                    </div>
                    
                    <p class="text-sm text-gray-600 mb-4">
                        {{ __('Configure different starting times and cutoff periods for specific date ranges (e.g., summer vs winter schedules).') }}
                    </p>
                    
                    <div v-if="settings.seasonal_schedules && settings.seasonal_schedules.length > 0" class="space-y-4">
                        <div 
                            v-for="(schedule, index) in settings.seasonal_schedules" 
                            :key="index"
                            class="border rounded-lg p-4 bg-gray-50"
                        >
                            <div class="flex items-center justify-between mb-3">
                                <input 
                                    v-model="schedule.name"
                                    type="text" 
                                    placeholder="Schedule Name"
                                    class="font-medium text-lg border-0 bg-transparent p-0"
                                />
                                <button 
                                    type="button"
                                    class="text-red-500 hover:text-red-700"
                                    @click="removeSchedule(index)"
                                >
                                    {{ __('Remove') }}
                                </button>
                            </div>
                            
                            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                                <div>
                                    <label class="block text-sm font-medium mb-1">{{ __('Start Date') }}</label>
                                    <date-fieldtype 
                                        v-model="schedule.date_start"
                                        :config="{ mode: 'single' }"
                                    />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium mb-1">{{ __('End Date') }}</label>
                                    <date-fieldtype 
                                        v-model="schedule.date_end"
                                        :config="{ mode: 'single' }"
                                    />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium mb-1">{{ __('Starting Time') }}</label>
                                    <time-fieldtype 
                                        v-model="schedule.starting_time"
                                        :config="{ format: 'H:mm' }"
                                    />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium mb-1">{{ __('Cutoff Hours') }}</label>
                                    <text-fieldtype 
                                        v-model="schedule.cutoff_hours"
                                        :config="{ type: 'number', min: 0, max: 72 }"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div v-else class="text-center py-8 text-gray-500">
                        {{ __('No seasonal schedules configured. Use default settings for all dates.') }}
                    </div>
                </div>
                
                <!-- Preview -->
                <div class="card p-4 bg-blue-50 border-blue-200">
                    <h4 class="font-semibold text-blue-800 mb-2">{{ __('How it works') }}</h4>
                    <p class="text-sm text-blue-700">
                        {{ __('If someone tries to book for today and your activity starts at') }} 
                        <strong>{{ settings.default_starting_time || '16:00' }}</strong>
                        {{ __('with a') }} 
                        <strong>{{ settings.default_cutoff_hours || 3 }}</strong>
                        {{ __('hour cutoff, they must book before') }}
                        <strong>{{ getCutoffTime() }}</strong>.
                        {{ __('Otherwise, they\'ll see a message asking them to book for tomorrow onwards.') }}
                    </p>
                </div>
            </div>
        </div>
    </element-container>
</template>

<script>
import dayjs from 'dayjs'

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
                seasonal_schedules: []
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
            this.$emit('input', this.enabled ? this.meta.parent : 'disabled')
        }
    },

    methods: {
        loadExistingSettings() {
            if (this.meta.existing_rules) {
                this.settings = { ...this.settings, ...this.meta.existing_rules }
                this.enabled = this.settings.enable_cutoff || false
            }
        },

        toggleCutoff() {
            this.settings.enable_cutoff = this.enabled
            this.updateFieldValue()
        },

        addSchedule() {
            if (!this.settings.seasonal_schedules) {
                this.settings.seasonal_schedules = []
            }
            
            this.settings.seasonal_schedules.push({
                name: 'New Schedule',
                date_start: '',
                date_end: '',
                starting_time: this.settings.default_starting_time,
                cutoff_hours: this.settings.default_cutoff_hours
            })
            
            this.updateFieldValue()
        },

        removeSchedule(index) {
            this.settings.seasonal_schedules.splice(index, 1)
            this.updateFieldValue()
        },

        getCutoffTime() {
            const startTime = this.settings.default_starting_time || '16:00'
            const cutoffHours = this.settings.default_cutoff_hours || 3
            
            const today = dayjs().format('YYYY-MM-DD')
            const startDateTime = dayjs(`${today} ${startTime}`)
            const cutoffTime = startDateTime.subtract(cutoffHours, 'hour')
            
            return cutoffTime.format('HH:mm')
        },

        updateFieldValue() {
            const value = this.enabled ? this.settings : 'disabled'
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
