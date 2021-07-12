<template>
    <div>
    <div class="w-full h-full" v-if="dynamicPricingLoaded">
        <vue-draggable class="mt-4 space-y-1" v-model="dynamicPricings" @start="drag=true" @end="drag=false" @change="order">
            <div
                v-for="dynamic in dynamicPricings"
                :key="dynamic.id"
                class="w-full flex items-center justify-between px-3 py-1 shadow rounded-md transition-colors bg-white"                  
            >
                <div class="flex items-center space-x-2">
                    <span class="font-medium" v-html="dynamic.title"></span>
                </div>
                <div class="space-x-2">
                    <span class="cursor-pointer text-red-800" @click="confirmDelete(dynamic)">
                        <svg class="stroke-current text-red-800" xmlns="http://www.w3.org/2000/svg" version="1.1" xmlns:xlink="http://www.w3.org/1999/xlink" xmlns:svgjs="http://svgjs.com/svgjs" viewBox="0 0 18 18" width="18" height="18">
                            <g transform="matrix(0.75,0,0,0.75,0,0)">
                                <path d="M0.5 6.507L23.5 6.507" fill="none"  stroke-linecap="round" stroke-linejoin="round"></path>                                    
                                <path d="M20.5,6.5v15a2,2,0,0,1-2,2H5.5a2,2,0,0,1-2-2V6.5" fill="none"  stroke-linecap="round" stroke-linejoin="round"></path>
                                <path d="M2.5,6.5v-1a2,2,0,0,1,2-2h15a2,2,0,0,1,2,2v1" fill="none"  stroke-linecap="round" stroke-linejoin="round"></path>
                                <path d="M9,3.5a3,3,0,0,1,6,0" fill="none"  stroke-linecap="round" stroke-linejoin="round"></path>
                                <path d="M12 10L12 19.5" fill="none"  stroke-linecap="round" stroke-linejoin="round"></path>
                                <path d="M16.5 10L16.5 19.5" fill="none"  stroke-linecap="round" stroke-linejoin="round"></path>
                                <path d="M7.5 10L7.5 19.5" fill="none"  stroke-linecap="round" stroke-linejoin="round"></path>
                            </g>
                        </svg>
                    </span>
                    <span class="cursor-pointer" @click="editPricing(dynamic)">
                        <svg class="stroke-current" xmlns="http://www.w3.org/2000/svg" version="1.1" xmlns:xlink="http://www.w3.org/1999/xlink" xmlns:svgjs="http://svgjs.com/svgjs" viewBox="0 0 18 18" width="18" height="18">
                            <g transform="matrix(0.75,0,0,0.75,0,0)">
                                <path d="M7 21.5L0.5 23.5 2.5 17 15.33 4.169 19.83 8.669 7 21.5z" fill="none" stroke-linecap="round" stroke-linejoin="round"></path>
                                <path d="M15.33,4.169l3.086-3.086a2.007,2.007,0,0,1,2.828,0l1.672,1.672a2,2,0,0,1,0,2.828L19.83,8.669" fill="none" stroke-linecap="round" stroke-linejoin="round"></path>
                                <path d="M17.58 6.419L6 18" fill="none" stroke-linecap="round" stroke-linejoin="round"></path>
                                <path d="M2.5 17L3.5 18 6 18 6 20.5 7 21.5" fill="none" stroke-linecap="round" stroke-linejoin="round"></path>
                                <path d="M1.5 20.5L3.5 22.5" fill="none" stroke-linecap="round" stroke-linejoin="round"></path>
                                <path d="M16.83 2.669L21.33 7.169" fill="none" stroke-linecap="round" stroke-linejoin="round"></path>
                            </g>
                        </svg>
                    </span>
                </div>
            </div>
        </vue-draggable>
    </div>
    <div class="w-full mt-4">
        <button class="btn-primary" @click="addPricing">
            Add Dynamic Pricing
        </button>
    </div>
    <dynamic-pricing-panel            
        v-if="showPanel"
        :data="dynamic"
        @closed="togglePanel"
        @saved="dynamicSaved"
    >
    </dynamic-pricing-panel>
    <confirmation-modal
        v-if="deleteId"
        title="Delete dynamic pricing"
        :danger="true"
        @confirm="deleteDynamic"
        @cancel="deleteId = false"
    >
        Are you sure you want to delete this dynamic pricing? <strong>This cannot be undone.</strong>
    </confirmation-modal>
    </div>
</template>
<script>
import axios from 'axios'
import DynamicPricingPanel from './DynamicPricingPanel.vue'
import VueDraggable from 'vuedraggable'

export default {

    data() {
        return {
            showPanel: false,
            dynamicPricings: '',           
            dynamicPricingLoaded: false,
            deleteId: false,
            dynamic: '',
            drag: false,
            emptyDynamic: {
                title: '',
                amount: '',
                amount_type: '',
                amount_operation: '',
                date_start: '',
                date_end: '',
                date_include: '',
                condition_type: '',
                condition_comparison : '',
                condition_value : '',
                entries: '',
                extras: ''
            }
        }
    },

    components: {
        DynamicPricingPanel,
        VueDraggable
    },


    mounted() {
        this.getAllDynamicPricing()        
    },

    methods: {
        togglePanel() {
            this.showPanel = !this.showPanel
        },
        addPricing() {
            this.dynamic = this.emptyDynamic
            this.togglePanel()
        },
        editPricing(dynamic) {
            this.dynamic = dynamic
            this.togglePanel()
        },
        dynamicSaved() {
            this.togglePanel()
            this.getAllDynamicPricing()
        },
        getAllDynamicPricing() {
            axios.get('/cp/resrv/dynamicpricing/index')
            .then(response => {
                this.dynamicPricings = response.data
                this.dynamicPricingLoaded = true             
            })
            .catch(error => {
                this.$toast.error('Cannot retrieve dynamic pricing')
            })
        },
        confirmDelete(dynamic) {
            this.deleteId = dynamic.id
        },
        deleteDynamic() {
            axios.delete('/cp/resrv/dynamicpricing', {data: {'id': this.deleteId}})
                .then(response => {
                    this.$toast.success('Dynamic pricing deleted')
                    this.deleteId = false
                    this.getAllDynamicPricing()
                })
                .catch(error => {
                    this.$toast.error('Cannot delete dynamic pricing')
                })
        },
        order(event){
            let item = event.moved.element
            let order = event.moved.newIndex + 1
            axios.patch('/cp/resrv/dynamicpricing/order', {id: item.id, order: order})
                .then(() => {
                    this.$toast.success('Dynamic pricing order changed')
                    this.getAllDynamicPricing()
                })
                .catch(() => {this.$toast.error('Dynamic pricing ordering failed')})
        }          
    }
}
</script>