<template>
    <div>
    <modal name="availability-modal">
        <div class="availability-modal flex flex-col h-full">
            <div class="text-lg font-semibold px-5 py-3 bg-gray-200 dark:bg-dark-500 rounded-t-lg border-b dark:border-dark-900">
                {{ __('Change availability') }}
                <span class="block mt-1 text-md" v-if="property"><span class="font-light">For:</span> {{ property.label }}</span>
                <span class="block mt-1 font-light text-sm">From {{ date_start }} to {{ date_end }}</span>
            </div>
            <div class="flex-1 py-4 px-4 text-grey">
                <div class="flex flex-wrap items-center space-x-4">
                    <div class="flex-1">
                        <div class="mb-2 text-sm font-bold">
                            <label for="available">Available</label>
                        </div>
                        <div class="w-full">
                            <input class="input-text" name="available" type="text" v-model="available" @keyup="handleEnterKey">
                        </div>
                        <div v-if="errors.available" class="w-full mt-1 text-sm text-red-400">
                            {{ errors.available[0] }}
                        </div>                    
                    </div>
                    <div class="flex-1">
                        <div class="mb-2 text-sm font-bold">
                            <label for="available">Price</label>
                        </div>
                        <div class="w-full">
                            <input class="input-text" name="price" type="text" v-model="price" @keyup="handleEnterKey">
                        </div>
                        <div v-if="errors.price" class="w-full mt-1 text-sm text-red-400">
                            {{ errors.price[0] }}
                        </div>                     
                    </div>
                </div>
            </div>
            <div class="p-4 bg-gray-200 dark:bg-dark-500 border-t dark:border-dark-900 rounded-b-lg flex items-center justify-between">
                <div class="flex items-center">
                    <button 
                        class="text-white bg-red-400 rounded font-bold py-2 px-6" 
                        v-html="__('Delete')" 
                        @click="confirmDelete"
                        :disabled="disableDelete"
                    >
                    </button>
                </div>
                <div class="flex item-center justify-end">
                    <button class="text-gray-700 hover:text-gray-900 dark:text-dark-100 dark:hover:text-dark-175" v-html="__('Cancel')" @click="$emit('cancel')"></button>
                    <button 
                        class="ml-4 text-white bg-blue-500 rounded font-bold py-2 px-6" 
                        v-html="__('Save')" 
                        @click="save"
                        :disabled="disableSave"
                    >
                    </button>
                </div>
            </div>
        </div>        
    </modal>
    <confirmation-modal
        v-if="deleteId"
        :title="__('Delete availability')"
        :danger="true"
        @confirm="deleteAvailability"
        @cancel="deleteId = false"
    >
        {{ __('Are you sure you want to clear availability date for those dates?') }}
    </confirmation-modal>
    </div>    
</template>

<script>
import FormHandler from '../mixins/FormHandler.vue'
import axios from 'axios'
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
            postUrl: '/cp/resrv/availability',
            method: 'post',
            deleteId: null,
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
        disableDelete() {
            return false
        },   
        submit() {
            let fields = {}
            fields.date_start = this.date_start
            fields.date_end = this.date_end
            fields.statamic_id = this.parentId
            fields.price = this.price
            fields.available = this.available
            if (this.property) {
                fields.advanced = [this.property]
            }
            return fields
        }
    },

    mixins: [ FormHandler ],

    methods: {
        confirmDelete() {
            this.deleteId = this.parentId
        },
        deleteAvailability() {
            let deleteData = {}
            deleteData.statamic_id = this.deleteId
            deleteData.date_start = this.date_start
            deleteData.date_end = this.date_end
            if (this.property) {
                deleteData.advanced = [this.property]
            }
            axios.delete(this.postUrl, {data: deleteData})
                .then(response => {
                    this.$toast.success('Availability deleted')
                    this.deleteId = false
                    this.$emit('saved')
                })
                .catch(error => {
                    this.$toast.error('Cannot delete availability')
                })
        },
        handleEnterKey(event) {
            if (event.key === 'Enter') {
                this.save();
            }
        },
    }
  
}
</script>
