<template>
    <modal name="availability-modal" :overflow="false">
        <div class="availability-modal flex flex-col h-full">
            <div class="text-lg font-medium p-2 pb-0">
                {{ __('Change availability') }}
            </div>
            <div v-if="property" class="px-2 mt-2">
                <v-select multiple :placeholder="__('Select property')" v-model="selectedProperty" :options="propertiesOptions" />
                <div class="flex w-full justify-end pt-1">
                    <button class="text-grey hover:text-grey-90" @click="selectedProperty = propertiesOptions">{{ __('Select all') }}</button>
                </div>
            </div>
            <div class="px-2 mt-2 mb-1">
                <span class="block mb-2 text-md">{{ __('Select dates') }}</span>
                <div class="date-container input-group w-full">
                    <div class="input-group-prepend flex items-center">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-4 h-4"><rect width="22" height="20" x=".5" y="3.501" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" rx="1" ry="1"></rect><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" d="M3.5 1.501v3m4-3v3m4-3v3m4-3v3m4-3v3m-7 3.999h3v4h0-4 0v-3a1 1 0 0 1 1-1zm3 0h3a1 1 0 0 1 1 1v3h0-4 0v-4h0zm-4 4.001h4v4h-4zm4 0h4v4h-4zm-4 4h4v4h-4z"></path><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" d="M15.5 16.5h4v3a1 1 0 0 1-1 1h-3 0v-4h0zm-11-4h3v4h0-4 0v-3a1 1 0 0 1 1-1zm3 .001h4v4h-4zm-4 3.999h4v4h0-3a1 1 0 0 1-1-1v-3h0zm4 .001h4v4h-4z"></path></svg>
                    </div>
                    <v-date-picker
                        v-model="dates"
                        :model-config="modelConfig"
                        :popover="{ visibility: 'click' }"
                        :masks="{ input: 'YYYY-MM-DD' }"
                        :mode="'range'"
                        :columns="$screens({ default: 1, lg: 2 })"
                        >
                            <input
                                slot-scope="{ inputProps, inputEvents }"
                                class="input-text border border-grey-50 border-l-0"
                                style="min-width: 100%"
                                v-bind="inputProps"
                                v-on="inputEvents" />
                    </v-date-picker>
                </div>
                <div v-if="errors.date_start || errors.date_end" class="w-full mt-1 text-sm text-red-400">
                    {{ __('Date range is required') }}
                </div>  
            </div>
            <div class="flex-1 px-2 mb-3 text-grey">
                <div class="flex flex-wrap items-center space-x-4">
                    <div class="flex-1">
                        <div class="mb-1 text-sm font-bold">
                            <label for="available">Available</label>
                        </div>
                        <div class="w-full">
                            <input class="w-full border border-gray-700 rounded p-1" name="available" type="text" v-model="available">
                        </div>
                        <div v-if="errors.available" class="w-full mt-1 text-sm text-red-400">
                            {{ errors.available[0] }}
                        </div>                    
                    </div>
                    <div class="flex-1">
                        <div class="mb-1 text-sm font-bold">
                            <label for="available">Price</label>
                        </div>
                        <div class="w-full">
                            <input class="w-full border border-gray-700 rounded p-1" name="price" type="text" v-model="price">
                        </div>
                        <div v-if="errors.price" class="w-full mt-1 text-sm text-red-400">
                            {{ errors.price[0] }}
                        </div>                     
                    </div>
                </div>
            </div>
            <div class="p-2 bg-grey-20 border-t flex items-center justify-end text-sm">
                <button class="text-grey hover:text-grey-90" v-html="__('Cancel')" @click="$emit('cancel')"></button>
                <button 
                    class="ml-2 text-white bg-blue-500 rounded font-bold px-2 py-1" 
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
            successMessage: 'Availability successfully saved',
            postUrl: (this.property ? '/cp/resrv/advancedavailability' :'/cp/resrv/availability'),
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
            if (this.property) {
                fields.advanced = _.isArray(this.selectedProperty) ? this.selectedProperty : [_.find(this.propertiesOptions, ['code', this.selectedProperty.code])]
            }
            return fields
        }
    },

    mixins: [ FormHandler ],
  
}
</script>
