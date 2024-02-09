<template>
    <div>
    <div class="w-full flex justify-end mb-4">
        <button class="btn-primary" @click="addLocation">
            Add Location
        </button>
    </div>
    <div class="w-full h-full" v-if="locationsLoaded">
        <vue-draggable class="mt-4 space-y-2" v-model="locations" @start="drag=true" @end="drag=false" @change="order">
            <div
                v-for="location in locations"
                :key="location.id"
                class="w-full flex items-center justify-between p-3 shadow rounded-md transition-colors bg-gray-100"                  
            >
                <div class="flex items-center space-x-2">
                    <div class="little-dot" :class="location.published == true ? 'bg-green-600' : 'bg-gray-400'"></div>
                    <span class="font-medium cursor-pointer" v-html="location.name" @click="editLocation(location)"></span>
                    <span v-if="location.extra_charge">€ {{ location.extra_charge }}</span>
                </div>
                <div class="space-x-2">
                    <dropdown-list>
                        <dropdown-item :text="__('Edit')" @click="editLocation(location)" />
                        <dropdown-item :text="__('Delete')" @click="confirmDelete(location)" />         
                    </dropdown-list>                    
                </div>
            </div>
        </vue-draggable>
    </div>
    <locations-panel            
        v-if="showPanel"
        :data="location"
        @closed="togglePanel"
        @saved="locationSaved"
    >
    </locations-panel>
    <confirmation-modal
        v-if="deleteId"
        title="Delete location"
        :danger="true"
        @confirm="deleteLocation"
        @cancel="deleteId = false"
    >
        Are you sure you want to delete this location? <strong>This cannot be undone.</strong>
    </confirmation-modal>
    </div>
</template>
<script>
import axios from 'axios'
import LocationsPanel from './LocationsPanel.vue'
import VueDraggable from 'vuedraggable'

export default {
    props: {
        insideEntry: {
            type: Boolean,
            default: false
        },
        parent: {
            type: String,
            required: false
        }
    },

    data() {
        return {
            showPanel: false,
            locations: '',           
            locationsLoaded: false,
            allowEntryExtraEdit: true,
            deleteId: false,
            locations: '',
            drag: false,
            emptyLocation: {
                name: '',
                slug: '',
                extra_charge: '',
                published : 1
            }
        }
    },

    components: {
        LocationsPanel,
        VueDraggable
    },


    mounted() {
        this.getAllLocations()        
    },

    methods: {
        togglePanel() {
            this.showPanel = !this.showPanel
        },
        toggleEntryExtraEditing() {
            this.allowEntryExtraEdit = !this.allowEntryExtraEdit
        },
        addLocation() {
            this.location = this.emptyLocation
            this.togglePanel()
        },
        editLocation(location) {
            this.location = location
            this.togglePanel()
        },
        locationSaved() {
            this.togglePanel()
            this.getAllLocations()
        },
        getAllLocations() {
            axios.get('/cp/resrv/location')
            .then(response => {
                this.locations = response.data
                this.locationsLoaded = true             
            })
            .catch(error => {
                this.$toast.error('Cannot retrieve extras')
            })
        },
        confirmDelete(location) {
            this.deleteId = location.id
        },
        deleteLocation() {
            axios.delete('/cp/resrv/location', {data: {'id': this.deleteId}})
                .then(response => {
                    this.$toast.success('Location deleted')
                    this.deleteId = false
                    this.getAllLocations()
                })
                .catch(error => {
                    this.$toast.error('Cannot delete location')
                })
        },
        order(event){
            let item = event.moved.element
            let order = event.moved.newIndex + 1
            axios.patch('/cp/resrv/location/order', {id: item.id, order: order})
                .then(() => {
                    this.$toast.success('Locations order changed')
                    this.getAllLocations()
                })
                .catch(() => {this.$toast.error('Locations ordering failed')})
        }          
    }
}
</script>