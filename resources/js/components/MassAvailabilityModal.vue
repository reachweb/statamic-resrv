<template>
    <modal name="availability-modal">
        <div class="availability-modal flex flex-col h-full">
            <div class="text-lg font-semibold px-5 py-3 bg-gray-200 dark:bg-dark-500 rounded-t-lg border-b dark:border-dark-900">
                {{ __('Change availability') }}
            </div>
            <div v-if="property" class="px-4 mt-4">
                <v-select 
                    multiple
                    :close-on-select="false"
                    :placeholder="__('Select property')" 
                    v-model="selectedProperty" 
                    :options="propertiesOptions" 
                />
                <div class="flex w-full justify-end pt-2">
                    <button class="text-grey hover:text-grey-90" @click="selectedProperty = propertiesOptions">{{ __('Select all') }}</button>
                </div>
            </div>
            <div class="px-4 mt-2 my-4">
                <span class="block mb-2 text-sm">{{ __('Select dates') }}</span>
                <div class="date-container input-group w-full">
                    <v-date-picker
                        v-model="dates"
                        :model-config="modelConfig"
                        :popover="{ visibility: 'click' }"
                        :masks="{ input: 'YYYY-MM-DD' }"
                        :mode="'date'"
                        :columns="$screens({ default: 1, lg: 2 })"
                        is-range
                        >
                        <template v-slot="{ inputValue, inputEvents }">
                            <div class="w-full flex items-center">
                            <div class="input-group">
                                <div class="input-group-prepend flex items-center">
                                    <svg-icon name="light/calendar" class="w-4 h-4" />
                                </div>
                                <div class="input-text border-l-0" :class="{ 'read-only': isReadOnly }">
                                    <input
                                        class="input-text-minimal p-0 bg-transparent leading-none"
                                        :value="inputValue.start"
                                        v-on="inputEvents.start"
                                    />
                                </div>
                            </div>
                            <div class="icon icon-arrow-right my-sm mx-1 text-gray-600" />
                            <div class="input-group">
                                <div class="input-group-prepend flex items-center">
                                    <svg-icon name="light/calendar" class="w-4 h-4" />
                                </div>
                                <div class="input-text border-l-0" :class="{ 'read-only': isReadOnly }">
                                    <input
                                        class="input-text-minimal p-0 bg-transparent leading-none"
                                        :value="inputValue.end"
                                        v-on="inputEvents.end"
                                    />
                                </div>
                            </div>
                        </div>
                        </template>
                    </v-date-picker>
                </div>
                <div v-if="errors.date_start || errors.date_end" class="w-full mt-1 text-sm text-red-400">
                    {{ __('Date range is required') }}
                </div>  
            </div>
            <div class="flex-1 px-4 mb-4">
                <div class="flex flex-wrap items-center space-x-4">
                    <div class="flex-1">
                        <div class="mb-1 text-sm">
                            <label for="available">{{ __('Available') }}</label>
                        </div>
                        <div class="w-full">
                            <input class="input-text" name="available" type="text" v-model="available">
                        </div>
                        <div v-if="errors.available" class="w-full mt-1 text-sm text-red-400">
                            {{ errors.available[0] }}
                        </div>                    
                    </div>
                    <div class="flex-1">
                        <div class="mb-1 text-sm">
                            <label for="available">{{ __('Price') }}</label>
                        </div>
                        <div class="w-full">
                            <input class="input-text" name="price" type="text" v-model="price">
                        </div>
                        <div v-if="errors.price" class="w-full mt-1 text-sm text-red-400">
                            {{ errors.price[0] }}
                        </div>                     
                    </div>
                </div>
            </div>
            <div class="flex-1 px-4 mb-4">
                <span class="block text-sm mb-2">{{ __('Only apply to specific days of the week (optional)') }}</span>
                <div class="flex flex-wrap space-x-4">
                    <div v-for="(day, index) in weekdays" :key="index">
                        <label class="flex items-center space-x-1">
                            <input type="checkbox" v-model="onlyDays" :value="index">
                            <span>{{ day }}</span>
                        </label>
                    </div>
                </div>
            </div>            
            <div class="p-4 bg-gray-200 dark:bg-dark-500 border-t rounded-b-lg flex items-center justify-between dark:border-dark-900">
                <button class="text-gray-700 hover:text-gray-900 dark:text-dark-100 dark:hover:text-dark-175" v-html="__('Cancel')" @click="$emit('cancel')"></button>
                <button 
                    class="ml-4 text-white bg-blue-500 rounded font-bold px-6 py-2" 
                    v-html="__('Save')" 
                    @click="save"
                    :disabled="disableSave"
                >
                </button>
            </div>
        </div>        
    </modal>
</template>

<script>
import FormHandler from '../mixins/FormHandler.vue'
import dayjs from 'dayjs'

export default {

    props: {
        parentId: {
            type: String,
            required: true
        },
        property: {
            type: Object,
            required: false
        },
        propertiesOptions: {
            type: Object,
            required: false
        }
    },

    data() {
        return {
            dates: {
                start: '',
                end: ''
            },
            available: null,
            price: null,
            onlyDays: [],
            weekdays: ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],
            successMessage: 'Availability successfully saved',
            postUrl: '/cp/resrv/availability',
            method: 'post',
            selectedProperty: (this.property ? this.property : null)
        }
    },

    computed: {
        submit() {
            let fields = {}
            fields.date_start = this.dates ? dayjs(this.dates.start).format('YYYY-MM-DD') : ''
            fields.date_end = this.dates ? dayjs(this.dates.end).format('YYYY-MM-DD') : ''
            fields.statamic_id = this.parentId
            fields.price = this.price
            fields.available = this.available
            if (this.onlyDays.length > 0) {
                fields.onlyDays = this.onlyDays
            }
            if (this.property) {
                fields.advanced = _.isArray(this.selectedProperty) ? this.selectedProperty : [_.findWhere(this.propertiesOptions, {'code': this.selectedProperty.code})]
            }
            return fields
        }
    },

    mixins: [ FormHandler ],
  
}
</script>

<style>
.vm--modal {
    overflow: visible !important;
}
.v-select .vs__selected-options {
    flex-wrap: wrap;
}
.v-select:not(.vs--single) .vs__selected {
    margin-bottom: 0.25rem;
}
</style>
