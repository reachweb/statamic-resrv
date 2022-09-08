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
                    <span class="font-medium cursor-pointer" v-html="dynamic.title" @click="editPricing(dynamic)"></span>
                </div>
                <div>
                    <dropdown-list>
                        <dropdown-item :text="__('Edit')" @click="editPricing(dynamic)" />
                        <dropdown-item :text="__('Delete')" @click="confirmDelete(dynamic)" />         
                    </dropdown-list>                  
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
                extras: '',
                coupon: '',
                expire_at: '',
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