<template>
    <div>
        <div class="mb-4">
            {{ __('Select when to show, hide or make this extra required. When adding multiple conditions for an operation, all of them have to apply.') }}
        </div>
        <div v-for="(condition, index) in conditionsForm" :key="index">
            <div class="flex items-center py-4 my-2 border-b">
                <div class="min-w-[240px]">
                    <div class="mb-1 text-sm font-semibold">
                        {{ __('Operation') }}
                    </div>
                    <div class="w-full">
                        <v-select v-model="conditionsForm[index].operation" :options="operation" :reduce="operation => operation.value" />
                    </div>
                    <div v-if="errors['conditions.'+index+'.operation']" class="w-full mt-1 text-sm text-red-400">
                        {{ errors['conditions.'+index+'.operation'][0] }}
                    </div>  
                </div>
                <div class="min-w-[240px] ml-4">
                    <div class="mb-1 text-sm font-semibold">
                        {{ __('Type') }}
                    </div>
                    <div class="w-full">
                        <v-select v-model="conditionsForm[index].type" :options="type" :reduce="type => type.value" @input="typeSelected(index)" />
                    </div>
                    <div v-if="errors['conditions.'+index+'.type']" class="w-full mt-1 text-sm text-red-400">
                        {{ errors['conditions.'+index+'.type'][0] }}
                    </div>  
                </div>
                <div class="ml-4" v-if="typeIsDate(index)">
                    <div class="flex items-center">
                        <div class="ml-2">
                            <div class="mb-1 text-sm font-semibold">
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
                                                <svg-icon name="light/calendar" class="w-4 h-4" />
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
                            <div v-if="errors['conditions.'+index+'.date_start']" class="w-full mt-1 text-sm text-red-400">
                                {{ errors['conditions.'+index+'.date_start'][0] }}
                            </div>  
                        </div>
                        <div class="ml-2">
                            <div class="mb-1 text-sm font-semibold">
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
                                                <svg-icon name="light/calendar" class="w-4 h-4" />
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
                            <div v-if="errors['conditions.'+index+'.date_end']" class="w-full mt-1 text-sm text-red-400">
                                {{ errors['conditions.'+index+'.date_end'][0] }}
                            </div>  
                        </div>                    
                    </div>
                </div>
                <div class="ml-4 mr-6" v-if="typeIsTime(index)">
                    <div class="flex items-center">
                        <div class="ml-2">
                            <div class="mb-1 text-sm font-semibold">
                                {{ __('Time start') }}
                            </div>
                            <div class="time-fieldtype">
                                <time-fieldtype v-model="conditionsForm[index].time_start"></time-fieldtype>
                            </div>
                            <div v-if="errors['conditions.'+index+'.time_start']" class="w-full mt-1 text-sm text-red-400">
                                {{ errors['conditions.'+index+'.time_start'][0] }}
                            </div>  
                        </div>
                        <div class="ml-4">
                            <div class="mb-1 text-sm font-semibold">
                                {{ __('Time end') }}
                            </div>
                            <div class="time-fieldtype">
                                <time-fieldtype v-model="conditionsForm[index].time_end"></time-fieldtype>
                            </div>
                            <div v-if="errors['conditions.'+index+'.time_end']" class="w-full mt-1 text-sm text-red-400">
                                {{ errors['conditions.'+index+'.time_end'][0] }}
                            </div>  
                        </div>
                    </div>                    
                </div>
                <div class="ml-4" v-if="typeIsValue(index)">
                    <div class="flex items-center">
                        <div class="ml-2 min-w-lg">
                            <div class="mb-1 text-sm font-semibold">
                                {{ __('Comparison') }}
                            </div>
                            <div class="w-full">
                                <v-select class="min-w-40" v-model="conditionsForm[index].comparison" :options="comparison" :reduce="type => type.value" />
                            </div>
                            <div v-if="errors['conditions.'+index+'.comparison']" class="w-full mt-1 text-sm text-red-400">
                                {{ errors['conditions.'+index+'.comparison'][0] }}
                            </div>  
                        </div>
                        <div class="ml-4">
                            <div class="mb-1 text-sm font-semibold">
                                {{ __('Value') }}
                            </div>
                            <div class="w-full">
                                <input class="input-text" type="text" v-model="conditionsForm[index].value">
                            </div>
                            <div v-if="errors['conditions.'+index+'.value']" class="w-full mt-1 text-sm text-red-400">
                                {{ errors['conditions.'+index+'.value'][0] }}
                            </div>  
                        </div>
                    </div>
                </div>
                <div class="ml-4" v-if="typeIsExtra(index)">
                    <div class="flex items-center">
                        <div class="ml-2 min-w-lg">
                            <div class="mb-1 text-sm font-semibold">
                                {{ __('Extra') }}
                            </div>
                            <div class="w-full">
                                <v-select class="min-w-40" v-model="conditionsForm[index].value" :options="extrasWithoutCurrent" :reduce="type => type.value" />
                            </div>
                            <div v-if="errors['conditions.'+index+'.value']" class="w-full mt-1 text-sm text-red-400">
                                {{ errors['conditions.'+index+'.value'][0] }}
                            </div>  
                        </div>                        
                    </div>
                </div>
                <div class="ml-4" v-if="typeIsCategory(index)">
                    <div class="flex items-center">
                        <div class="ml-2 min-w-lg">
                            <div class="mb-1 text-sm font-semibold">
                                {{ __('Category') }}
                            </div>
                            <div class="w-full">
                                <v-select class="min-w-40" v-model="conditionsForm[index].value" :options="categoryOptions" :reduce="type => type.value" />
                            </div>
                            <div v-if="errors['conditions.'+index+'.value']" class="w-full mt-1 text-sm text-red-400">
                                {{ errors['conditions.'+index+'.value'][0] }}
                            </div>  
                        </div>                        
                    </div>
                </div>
                <div class="ml-2 mt-4">
                    <button class="btn-close group" @click="remove(index)">
                        <svg viewBox="0 0 16 16" class="w-4 h-4 group-hover:text-red"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" d="M1 3h14M9.5 1h-3a1 1 0 0 0-1 1v1h5V2a1 1 0 0 0-1-1zm-3 10.5v-5m3 5v-5m3.077 7.583a1 1 0 0 1-.997.917H4.42a1 1 0 0 1-.996-.917L2.5 3h11l-.923 11.083z"></path></svg>
                    </button>
                </div>
            </div>                
        </div>
        <button 
            class="btn-primary mt-4"
            @click="add"
        >
            {{ __('Add condition') }}
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
        extras: {
            type: Object,
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
                hidden: __('Hide when'),
            });
        },
        type() {
            return this.normalizeInputOptions({
                always: __('Always'),
                pickup_time: __('Pickup time between'),
                dropoff_time: __('Drop off time between'),
                reservation_duration: __('Reservation duration'),
                reservation_dates: __('Reservation dates included'),
                extra_selected: __('Extra is selected'),
                extra_not_selected: __('Extra is not selected'),
                extra_in_category_selected: __('Extra in category is selected'),
                no_extra_in_category_selected: __('No extra in category is selected'),
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
        },
        extrasWithoutCurrent() {
            let extras = _.reject(this.extras, (extra) => extra.id == this.data.id)
            return _.map(extras, (extra) => {
                return {
                    'value': extra.id, 
                    'label': extra.name
                }
            })
        },
        categoryOptions() {
            let categories = _.filter(this.extras, (extra) => extra.category_id !== null);
            let groupedCategories = _.groupBy(categories, 'category_id');

            return _.map(groupedCategories, (items) => {
                let category = items[0].category;
                return {
                    'value': parseInt(category.id),
                    'label': category ? category.name : 'Uncategorized'
                }
            });
        }
    },

    data() {
        return {
            conditionsForm: [],
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

    mounted() {
        if (this.data) {
            this.conditionsForm = this.data
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
        remove(index) {
            this.conditionsForm.splice(index, 1);
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
            if (this.conditionsForm[index].type == 'reservation_duration') {
                return true
            }
            return false
        },
        typeIsExtra(index) {
            if (this.conditionsForm[index].type == 'extra_selected' || this.conditionsForm[index].type == 'extra_not_selected') {
                return true
            }
            return false
        },
        typeIsCategory(index) {
            if (this.conditionsForm[index].type == 'extra_in_category_selected' || this.conditionsForm[index].type == 'no_extra_in_category_selected') {
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
