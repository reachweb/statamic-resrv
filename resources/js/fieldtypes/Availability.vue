<template>
    <element-container @resized="containerWidth = $event.width">
    <div class="w-full h-full text-center my-4 text-gray-700 dark:text-dark-100 text-lg" v-if="newItem">
        {{ __('You need to save this entry before you can add availability information.') }}
    </div>
    <div class="statamic-resrv-availability relative" v-else>
        <div class="flex items-center py-1 my-4 border-b border-t dark:border-gray-500">
            <span class="font-bold mr-4">{{ __('Enable reservations') }}</span>    
            <toggle v-model="enabled" @input="changeAvailability" :parent="this.meta.parent"></toggle>
        </div>
        <template v-if="isAdvanced">
        <div class="w-full h-full relative mb-3">
            <v-select :placeholder="__('Select property')" v-model="property" :options="propertiesOptions" />
        </div>
        </template>
        <div class="w-full h-full relative">
            <Loader v-if="!availabilityLoaded && !isAdvanced" />
            <div class="w-full my-3" v-if="!isAdvanced || property">
                <div class="w-full flex justify-end">
                    <button class="btn-flat text-sm" @click="showModal = 'massavailability'">{{ __('Bulk edit') }}</button>
                </div>
            </div>
            <div ref="calendar"></div>
        </div>
        <availability-modal
            v-if="showModal == 'availability'"
            :dates="selectedDates"
            :parent-id="this.meta.parent"
            :property="this.property"
            @cancel="toggleModal"
            @saved="availabilitySaved"
        >
        </availability-modal> 
        <mass-availability-modal
            v-if="showModal == 'massavailability'"
            :parent-id="this.meta.parent"
            :property="this.property"
            :propertiesOptions="this.propertiesOptions"
            @cancel="toggleModal"
            @saved="availabilitySaved"
        >
        </mass-availability-modal>
    </div>
    </element-container>

</template>

<script>
import { Calendar } from '@fullcalendar/core'
import dayGridPlugin from '@fullcalendar/daygrid'
import interactionPlugin from '@fullcalendar/interaction'
import AvailabilityModal from '../components/AvailabilityModal.vue'
import MassAvailabilityModal from '../components/MassAvailabilityModal.vue'
import Toggle from '../components/Toggle.vue'
import Loader from '../components/Loader.vue'
import dayjs from 'dayjs'
import axios from 'axios'

export default {

    mixins: [Fieldtype],

    data() {
        return {
            enabled: (this.value ? this.value : 'disabled'),
            containerWidth: null,
            showModal: false,
            selectedDates: false,
            calendar: '',
            calendarOptions: {
                plugins: [ dayGridPlugin, interactionPlugin ],
                selectable: true,
                initialView: 'dayGridMonth',
                select: this.handleSelect,
                dayCellContent: this.renderDay,
                aspectRatio: 0.85,
                fixedWeekCount: false
            },
            availability: '',
            availabilityLoaded: false,
            property: null
        }
    },

    components: {
        AvailabilityModal,
        MassAvailabilityModal,
        Loader,
        Toggle
    },

    computed: {
        newItem() {
            if (this.meta.parent == 'Collection') {
                return true
            }
            return false
        },
        isAdvanced() {
            if (_.isObject(this.meta.advanced_availability)) {
                return true
            }
            return false
        },
        propertiesOptions() {
            let options = [];
            if (_.isObject(this.meta.advanced_availability)) {
                _.forEach(this.meta.advanced_availability, (label, slug) => options.push({label: label, code: slug}))
            }
            return options;
        }
    },

    mounted() {
        this.calendar = new Calendar(this.$refs.calendar, this.calendarOptions)
        if (! this.newItem) {
            this.$emit('input', this.enabled)
        }
        if (! this.newItem && ! this.isAdvanced) {
            this.getAvailability()            
            this.calendar.render()
        }
    },

    created() {
        this.$events.$on('tab-switched', this.renderAgain);
    },

    updated() {
        if (! this.newItem) {
            this.$emit('input', this.enabled)
        }
    },

    watch: {
        property() {
            if (this.property !== null) {
                this.getAvailability()
            } else {
                this.clearAvailability()
                this.calendar.destroy()
            }
            this.renderAgain()
        }
    },

    methods: {
        handleSelect(date) {
            this.selectedDates = date
            this.toggleModal('availability')
        },
        toggleModal(modal) {
            if (! this.showModal) {
                this.showModal = modal
            } else {
                this.showModal = false
            }            
        },
        toggleAvailability() {
            this.availabilityLoaded = ! this.availabilityLoaded
        },
        renderDay(arg) {
            let arrayOfDomNodes = []
            let day = dayjs(arg.date).format('YYYY-MM-DD')
            const defaultClasses = ['p-2', 'text-xs', 'text-white', 'bg-green-700'];
            
            // Day label
            let dayLabel = document.createElement('div')
            dayLabel.classList.add('mt-1', 'mb-1')
            dayLabel.innerHTML = arg.dayNumberText
            arrayOfDomNodes.push(dayLabel)

            if (!this.availability) {
                return { domNodes: arrayOfDomNodes }
            }

            // Availability
            if (this.hasAvailable(day)) {
                let avail = document.createElement('div')     
                if (this.hasAvailable(day) > 0) {
                    avail.classList.add(...defaultClasses, 'bg-green-700')
                }
                avail.innerHTML = '# '+this.hasAvailable(day)
                arrayOfDomNodes.push(avail)
            }    
               
            // Price            
            if (this.hasPrice(day)) {
                let price = document.createElement('div')     
                if (this.hasPrice(day) > 0) {
                    price.classList.add(...defaultClasses, 'bg-gray-700')
                }
                price.innerHTML = this.meta.currency_symbol+' '+this.hasPrice(day)
                arrayOfDomNodes.push(price)
            }           

            return { domNodes: arrayOfDomNodes }
        },
        renderAgain() {
            window.dispatchEvent(new Event('resize'))
        },
        hasAvailable(day) {
            if (day in this.availability) {
                if (this.availability[day].available) {
                    return this.availability[day].available
                }
            }
            return false
        },
        hasPrice(day) {
            if (day in this.availability) {
                if (this.availability[day].price) {
                    return this.availability[day].price
                }                
            }
            return false
        },
        availabilitySaved() {
            this.toggleAvailability()
            this.toggleModal()   
            this.getAvailability()
            this.renderAgain()
        },
        getAvailability() {
            let url = '/cp/resrv/availability/'+this.meta.parent
            if (this.property) {
                url += '/'+this.property.code
            }
            axios.get(url)
            .then(response => {
                this.availability = response.data
                this.calendar.render()
                this.toggleAvailability()
            })
            .catch(error => {
                this.$toast.error('Cannot retrieve availability')
            })
        },
        clearAvailability() {
            this.availability = ''
            this.calendar.render()
            this.toggleAvailability()
        },
        changeAvailability() {
            if (this.enabled == 'disabled') {
                this.$emit('input', 'disabled')
                      
            } else {
                this.$emit('input', this.meta.parent)
            }
        }            
    }
}
</script>
