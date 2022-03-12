<template>
    <modal name="availability-modal">
        <div class="availability-modal flex flex-col h-full">
            <div class="text-lg font-medium p-2 pb-0">
                {{ __('Change availability') }}
                <span class="block mt-1 text-md" v-if="property"><span class="font-light">For:</span> {{ property.label }}</span>
                <span class="block mt-1 font-light text-sm">From {{ date_start }} to {{ date_end }}</span>
            </div>
            <div class="flex-1 px-2 py-3 text-grey">
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
        dates: {
            type: Object,
            required: true
        },
        parentId: {
            type: String,
            required: true
        },
        property: {
            type: Object,
            required: false
        }
    },

    data() {
        return {
            available: null,
            price: null,
            successMessage: 'Availability successfully saved',
            postUrl: (this.property ? '/cp/resrv/advancedavailability' :'/cp/resrv/availability'),
            method: 'post'
        }
    },

    computed: {
        date_start() {
            return dayjs(this.dates.start).format('YYYY-MM-DD')
        },
        date_end() {
            // We need to subtract here because FullCaledar uses day+1 for end date
            return dayjs(this.dates.end).subtract(1, 'day').format('YYYY-MM-DD')
        },
        submit() {
            let fields = {}
            fields.date_start = this.date_start
            fields.date_end = this.date_end
            fields.statamic_id = this.parentId
            fields.price = this.price
            fields.available = this.available
            if (this.property) {
                fields.advanced = this.property.code
            }
            return fields
        }
    },

    mixins: [ FormHandler ],
  
}
</script>
