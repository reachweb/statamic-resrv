<template>
    <div>
    <div class="w-full h-full" v-if="fixedPricingLoaded">
        <div class="mt-4 space-y-1">
            <div
                v-for="pricing in fixedPricings"
                :key="pricing.id"
                class="w-full flex items-center justify-between px-3 py-1 shadow rounded-md transition-colors bg-white"
            >
                <div class="flex items-center space-x-2">
                    <span>{{ __('Days:') }}</span>
                    <span class="font-medium" v-html="pricing.days"></span>
                    <span>{{ pricing.price }}</span>
                </div>
                <div class="space-x-2">
                     <span class="cursor-pointer text-red-800" @click="confirmDelete(pricing)">
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
                    <span class="cursor-pointer" @click="editFixedPricing(pricing)">
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
        </div>
    </div>
    <div class="w-full mt-4">
        <button class="btn-primary" @click="addFixedPricing">
            {{ __('Add fixed pricing') }}
        </button>
    </div>
    <fixed-pricing-panel            
        v-if="showPanel"
        :data="fixedpricing"
        @closed="togglePanel"
        @saved="fixedPricingSaved"
    >
    </fixed-pricing-panel>
    <confirmation-modal
        v-if="deleteId"
        :title="__('Delete')"
        :danger="true"
        @confirm="deleteFixedPricing"
        @cancel="deleteId = false"
    >
        {{ __('Are you sure you want to delete this item?') }} <strong>{{ __('This cannot be undone.') }}</strong>
    </confirmation-modal>
    </div>
</template>
<script>
import axios from 'axios'
import FixedPricingPanel from './FixedPricingPanel.vue'

export default {
    props: {
        parent: {
            type: String,
            required: true
        }
    },

    data() {
        return {
            containerWidth: null,
            showPanel: false,
            fixedPricings: '',
            fixedPricingLoaded: false,
            allowEntryExtraEdit: true,
            deleteId: false,
            fixedPricing: '',
            emptyFixedPricing: {
                days: '',                
                price: '',
                statamic_id: this.parent            
            }
        }
    },

    components: {
        FixedPricingPanel,
    },

    computed: {
        newItem() {
            if (this.parent == 'Collection') {
                return true
            }
            return false
        }
    },

    mounted() {
        this.getFixedPricing()        
    },

    updated() {
        if (! this.newItem) {
            this.$emit('input', this.parent)
        }
    },

    methods: {
        togglePanel() {
            this.showPanel = !this.showPanel
        },
        addFixedPricing() {
            this.fixedpricing = this.emptyFixedPricing
            this.togglePanel()
        },
        editFixedPricing(pricing) {
            this.fixedpricing = pricing
            this.togglePanel()
        },
        fixedPricingSaved() {
            this.togglePanel()
            this.getFixedPricing()
        },
        getFixedPricing() {
            axios.get('/cp/resrv/fixedpricing/'+this.parent)
            .then(response => {
                this.fixedPricings = response.data                
                this.fixedPricingLoaded = true               
            })
            .catch(error => {
                this.$toast.error('Cannot retrieve fixed pricing data')
            })
        },        
        confirmDelete(extra) {
            this.deleteId = extra.id
        },
        deleteFixedPricing() {
            axios.delete('/cp/resrv/fixedpricing', {data: {'id': this.deleteId}})
                .then(response => {
                    this.$toast.success('Fixed pricing deleted')
                    this.deleteId = false
                    this.getFixedPricing()
                })
                .catch(error => {
                    this.$toast.error('Cannot delete fixed pricing')
                })
        },          
    }
}
</script>