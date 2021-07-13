<template>
    <div>
    <div class="w-full h-full" v-if="fixedPricingLoaded">
        <div class="mt-4 space-y-1">
            <div
                v-for="pricing in fixedPricings"
                :key="pricing.id"
                class="w-full flex items-center justify-between px-3 py-1 shadow rounded-md transition-colors bg-white"
            >
                <div class="flex items-center space-x-2 cursor-pointer" v-if="pricing.days != 0" @click="editFixedPricing(pricing)">
                    <span>{{ __('Days:') }}</span>
                    <span class="font-medium" v-html="pricing.days"></span>
                    <span>{{ pricing.price }}</span>
                </div>
                <div class="flex items-center space-x-2" v-else>
                    <span class="font-medium">{{ __('Extra day:') }}</span>
                    <span>{{ pricing.price }}</span>
                </div>
                <div>
                    <dropdown-list>
                        <dropdown-item :text="__('Edit')" @click="editFixedPricing(pricing)" />
                        <dropdown-item :text="__('Delete')" @click="confirmDelete(pricing)" />         
                    </dropdown-list>
                </div>
            </div>
        </div>
    </div>
    <div class="w-full mt-4 flex space-x-2">
        <button class="btn-primary" @click="addFixedPricing">
            {{ __('Add fixed pricing') }}
        </button>
        <button class="btn" @click="addFixedExtraPricing" v-if="! hasExtraDayPricing()">
            {{ __('Add extra day price') }}
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
            },
            extraFixedPricing: {
                days: '0',                
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
        addFixedExtraPricing() {
            this.fixedpricing = this.extraFixedPricing
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
        hasExtraDayPricing() {
            return _.some(this.fixedPricings, ['days', '0'])
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