<template>
    <element-container @resized="containerWidth = $event.width">
    <div class="w-full h-full text-center my-4 text-gray-700 text-lg" v-if="newItem">
        You need to save this entry before you can add availability information.
    </div>
    <div class="statamic-resrv-availability relative" v-else>
        <div class="flex items-center py-1 my-4 border-b border-t">
            <span class="font-bold mr-4">Enable reservations</span>    
            <toggle v-model="enabled" @input="changeAvailability" :parent="this.meta.parent"></toggle>
        </div>
        
        <div class="w-full h-full relative">
            <Loader v-if="!availabilityLoaded" />
            <div ref="calendar"></div>
        </div>
        <availability-modal
            v-if="showModal"
            :dates="selectedDates"
            :parent-id="this.meta.parent"
            @cancel="toggleModal"
            @saved="availabilitySaved"
        >
        </availability-modal>
    </div>
    </element-container>

</template>

<script>
import { Calendar } from '@fullcalendar/core'
import dayGridPlugin from '@fullcalendar/daygrid'
import interactionPlugin from '@fullcalendar/interaction'
import AvailabilityModal from '../components/AvailabilityModal.vue'
import Toggle from '../components/Toggle.vue'
import Loader from '../components/Loader.vue'
import dayjs from 'dayjs'
import axios from 'axios'

export default {

    mixins: [Fieldtype],

    data() {
        return {
            enabled: (this.value ? this.value : false),
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
                aspectRatio: 1
            },
            availability: '',
            availabilityLoaded: false
        }
    },

    components: {
        AvailabilityModal,
        Loader,
        Toggle
    },

    computed: {
        newItem() {
            if (this.meta.parent == 'Collection') {
                return true
            }
            return false
        }
    },

    mounted() {
        if (! this.newItem) {
            this.getAvailability()
            this.calendar = new Calendar(this.$refs.calendar, this.calendarOptions)
            this.calendar.render()
        }        
    },

    updated() {
        if (! this.newItem) {
            this.$emit('input', this.enabled)
        }
    },

    methods: {
        handleSelect(date) {
            this.selectedDates = date
            this.toggleModal()
        },
        toggleModal() {
            this.showModal = !this.showModal
        },
        toggleAvailability() {
            this.availabilityLoaded = !this.availabilityLoaded
        },
        renderDay(arg) {
            let arrayOfDomNodes = []
            let day = dayjs(arg.date).format('YYYY-MM-DD')
            const defaultClasses = ['p-1', 'text-xs', 'text-white', 'bg-green-500'];
            
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
                    avail.classList.add(...defaultClasses, 'bg-green-500')
                } else {
                    avail.classList.add(...defaultClasses, 'bg-yellow-400')
                }
                avail.innerHTML = '# '+this.hasAvailable(day)
                arrayOfDomNodes.push(avail)
            }    
               
            // Price            
            if (this.hasPrice(day)) {
                let price = document.createElement('div')     
                if (this.hasPrice(day) > 0) {
                    price.classList.add(...defaultClasses, 'bg-gray-600')
                } else {
                    price.classList.add(...defaultClasses, 'bg-yellow-400')
                }
                price.innerHTML = '€ '+this.hasPrice(day)
                arrayOfDomNodes.push(price)
            }           

            return { domNodes: arrayOfDomNodes }
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
        },
        getAvailability() {
            axios.get('/cp/resrv/availability/'+this.meta.parent)
            .then(response => {
                this.availability = response.data
                this.calendar.render()
                this.toggleAvailability()
            })
            .catch(error => {
                this.$toast.error('Cannot retrieve availability')
            })
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
