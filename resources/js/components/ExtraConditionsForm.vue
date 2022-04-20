<template>
    <div>
        <div v-for="(condition, index) in conditionsForm" :key="index">
            <div class="flex items-center py-2 my-2 border-b">
                <div class="w-full xl:w-1/4">
                    <div class="mb-1 text-sm">
                        {{ __('Operation') }}
                    </div>
                    <div class="w-full">
                        <v-select v-model="conditionsForm[index].operation" :options="operation" :reduce="operation => operation.value" />
                    </div>
                </div>
                <div class="w-full xl:w-1/4 ml-2">
                    <div class="mb-1 text-sm">
                        {{ __('Type') }}
                    </div>
                    <div class="w-full">
                        <v-select v-model="conditionsForm[index].type" :options="type" :reduce="type => type.value" @input="typeSelected(index)" />
                    </div>
                </div>
                <div class="w-full xl:w-1/2 ml-2 mr-6" v-if="typeIsDate(index)">
                    <div class="flex items-center">
                        <div class="ml-2">
                            <div class="mb-1 text-sm">
                                {{ __('Date start') }}
                            </div>
                            <div class="date-container input-group w-full">
                                <v-date-picker
                                    v-model="conditionsForm[index].date_start"
                                    :popover="{ visibility: 'click' }"
                                    :masks="{ input: 'YYYY-MM-DD' }"
                                    :mode="'date'"
                                    :columns="$screens({ default: 1, lg: 2 })"
                                > 
                                    <template v-slot="{ inputValue, inputEvents }">
                                        <div class="input-group">
                                            <div class="input-group-prepend flex items-center">
                                                <svg-icon name="calendar" class="w-4 h-4" />
                                            </div>
                                            <div class="input-text border border-grey-50 border-l-0">
                                                <input
                                                    class="input-text-minimal p-0 bg-transparent leading-none"
                                                    :value="inputValue"
                                                    v-on="inputEvents"
                                                />
                                            </div>
                                        </div>
                                    </template>                 
                                </v-date-picker>
                            </div>
                        </div>
                        <div class="ml-2">
                            <div class="mb-1 text-sm">
                                {{ __('Date end') }}       
                            </div>
                            <div class="date-container input-group w-full">
                                <v-date-picker
                                    v-model="conditionsForm[index].date_end"
                                    :popover="{ visibility: 'click' }"
                                    :masks="{ input: 'YYYY-MM-DD' }"
                                    :mode="'date'"
                                    :columns="$screens({ default: 1, lg: 2 })"
                                >
                                    <template v-slot="{ inputValue, inputEvents }">
                                        <div class="input-group">
                                            <div class="input-group-prepend flex items-center">
                                                <svg-icon name="calendar" class="w-4 h-4" />
                                            </div>
                                            <div class="input-text border border-grey-50 border-l-0">
                                                <input
                                                    class="input-text-minimal p-0 bg-transparent leading-none"
                                                    :value="inputValue"
                                                    v-on="inputEvents"
                                                />
                                            </div>
                                        </div>
                                    </template>
                                </v-date-picker>
                            </div>
                        </div>                    
                    </div>
                </div>
                <div class="w-full xl:w-1/2 ml-2 mr-6" v-if="typeIsTime(index)">
                    <div class="flex items-center">
                        <div class="ml-2">
                            <div class="mb-1 text-sm">
                                {{ __('Time start') }}
                            </div>
                            <div class="time-fieldtype">
                                <time-fieldtype v-model="conditionsForm[index].time_start"></time-fieldtype>
                            </div>
                        </div>
                        <div class="ml-4">
                            <div class="mb-1 text-sm">
                                {{ __('Time end') }}
                            </div>
                            <div class="time-fieldtype">
                                <time-fieldtype v-model="conditionsForm[index].time_end"></time-fieldtype>
                            </div>
                        </div>
                    </div>                    
                </div>
                <div class="w-full xl:w-1/2 ml-2 mr-6" v-if="typeIsValue(index)">
                    <div class="flex items-center">
                        <div class="ml-2 min-w-lg">
                            <div class="mb-1 text-sm">
                                {{ __('Comparison') }}
                            </div>
                            <div class="w-full">
                                <v-select v-model="conditionsForm[index].comparison" :options="comparison" :reduce="type => type.value" />
                            </div>
                        </div>
                        <div class="ml-4">
                            <div class="mb-1 text-sm">
                                {{ __('Value') }}
                            </div>
                            <div class="w-full">
                                <input class="w-full border border-gray-700 rounded p-1" type="text" v-model="conditionsForm[index].value">
                            </div>
                        </div>
                    </div>
                </div>
            </div>                
        </div>
        <button 
            class="px-2 py-1 bg-gray-600 hover:bg-gray-800 transition-colors text-white rounded cursor-pointer"
            @click="add"
        >
            {{ __('Add') }}
        </button>
    </div>
</template>

<script>

import HasInputOptions from '../mixins/HasInputOptions.vue'


export default {
    props: {
        data: {
            type: Array,
            required: true,
        },
        errors: {
            type: Object,
            required: false
        }
    },

    computed: {
        operation() {
            return this.normalizeInputOptions({
                required: __('Required'),
                show: __('Show when'),
                hiden: __('Hide when'),
            });
        },
        type() {
            return this.normalizeInputOptions({
                always: __('Always'),
                pickup_time: __('Pickup time between'),
                dropoff_time: __('Drop off time between'),
                reservation_duration: __('Reservation duration'),
                reservation_dates: __('Reservation dates included'),
                extra_selected: __('Extra with slug'),
            });
        },
        comparison() {
            return this.normalizeInputOptions({
                '==': __('Equal to'),
                '!=': __('Not equal to'),
                '>': __('Greater than'),
                '<': __('Less than'),
                '>=': __('Greater or equal to'),
                '<=': __('Less or equal to'),
            });
        }
    },

    data() {
        return {
            conditionsForm: this.data ? this.data : [],
            dates: []
        }
    },


    mixins: [HasInputOptions],

    watch: {
        conditionsForm: {
            deep: true,
            handler(conditions) {
                let readyConditions = this.handleDates(conditions)
                this.$emit('updated', readyConditions)
            }
        }
    },

    created() {
        if (this.conditionsForm.length > 0) {
            let readyConditions = this.handleDates(this.conditionsForm)
            this.$emit('updated', readyConditions);
        }
    },

    methods: {
        add() {
            this.conditionsForm.push({
                operation: '',
                type: '',
                comparison: '',
                value: '',
                date_start: '',
                date_end: '',
                time_start: '',
                time_end: ''
            })
        },
        typeSelected(index) {
            this.clearValues(index)
        },
        typeIsDate(index) {
            if (this.conditionsForm[index].type == 'reservation_dates') {
                return true
            }
            return false
        },
        typeIsTime(index) {
            if (this.conditionsForm[index].type == 'pickup_time' || this.conditionsForm[index].type == 'dropoff_time') {
                return true
            }
            return false
        },
        typeIsValue(index) {
            if (this.conditionsForm[index].type == 'extra_selected' || this.conditionsForm[index].type == 'reservation_duration') {
                return true
            }
            return false
        },
        handleDates(conditions) {
            _.forEach(conditions, function(condition) {
                if (condition.type == 'reservation_dates') {
                    if (condition.date_start) {
                        condition.date_start = Vue.moment(condition.date_start).format('YYYY-MM-DD')
                    }
                    if (condition.date_end) {
                        condition.date_end = Vue.moment(condition.date_end).format('YYYY-MM-DD')
                    }
                }
            })
            return conditions
        },
        clearValues(index) {
            this.conditionsForm[index].value = ''
            this.conditionsForm[index].comparison = ''
            this.conditionsForm[index].date_start = ''
            this.conditionsForm[index].date_end = ''
            this.conditionsForm[index].time_start = ''
            this.conditionsForm[index].time_end = ''
        }
    }
}
</script>
