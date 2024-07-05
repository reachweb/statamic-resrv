<template>
    <div>
    <div class="w-full flex justify-end mb-4">
        <button class="btn-primary" @click="addAffiliate">
            {{ __('Add a new affiliate') }}
        </button>
    </div>
    <div class="w-full h-full" v-if="affiliatesLoaded">
        <div v-if="affiliates.length > 0">
            <div
                v-for="affiliate in affiliates"
                :key="affiliate.id"
                class="w-full flex flex-wrap items-center justify-between p-3 shadow-sm rounded-md border transition-colors 
                bg-gray-100 dark:border-dark-900 dark:bg-dark-550 dark:shadow-dark-sm"
                
            >
                <div class="flex items-center space-x-2">
                    <div class="little-dot" :class="greenDot(affiliate) ? 'bg-green-600' : 'bg-gray-400'"></div>
                    <span class="font-medium cursor-pointer" v-html="affiliate.name" @click="editAffiliate(affiliate)"></span>
                    <span class="ml-3 font-light">{{ affiliate.email }} <span class="ml-3 text-xs text-gray-700 dark:text-dark-100">{{ __('Fee:') }} {{ affiliate.fee }}%</span></span>
                </div>
                <div class="flex space-x-2">
                    <dropdown-list>
                        <dropdown-item :text="__('Edit')" @click="editAffiliate(affiliate)" />
                        <dropdown-item :text="__('Copy affiliate link')" @click="copyLink(affiliate)" />
                        <dropdown-item :text="__('Delete')" @click="confirmDelete(affiliate)" />         
                    </dropdown-list>
                </div>
            </div>
        </div>
        <div v-else>
            <div class="my-8 border shadow-sm bg-gray-100 dark:border-dark-300 dark:bg-dark-550 dark:shadow-dark-sm p-4
             text-center text-gray-700 dark:text-dark-100">
                {{ __('No affiliates found.') }}
            </div>
        </div>
    </div>
    <affiliates-panel            
        v-if="showPanel"
        :data="affiliate"
        @closed="togglePanel"
        @saved="affiliateSaved"
    >
    </affiliates-panel>
    <confirmation-modal
        v-if="deleteId"
        title="Delete affiliate"
        :danger="true"
        @confirm="deleteAffiliate"
        @cancel="deleteId = false"
    >
        Are you sure you want to delete this affiliate? <strong>This cannot be undone.</strong>
    </confirmation-modal>
    </div>
</template>
<script>
import axios from 'axios'
import AffiliatesPanel from './AffiliatesPanel.vue'

export default {
    data() {
        return {
            showPanel: false,
            affiliates: '',
            affiliatesLoaded: false,
            deleteId: false,
            affiliate: '',
            emptyAffiliate: {
                name: '',
                code: '',
                email: '',
                cookie_duration: '',
                fee : '',
                allow_skipping_payment: 0,
                send_reservation_email: 0,
                published : 1
            }
        }
    },

    components: {
        AffiliatesPanel,
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
        this.getAllAffiliates()        
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
        addAffiliate() {
            this.affiliate = this.emptyAffiliate
            this.togglePanel()
        },
        editAffiliate(affiliate) {
            this.affiliate = affiliate
            this.togglePanel()
        },
        copyLink(affiliate) {
            let link = window.location.origin + '/?afid=' + affiliate.code
            if (navigator.clipboard) {
                navigator.clipboard.writeText(link)
                this.$toast.success('Affiliate link copied to clipboard')
            } else {
                this.$toast.error('Failed to copy link. Are you using SSL?')
            }            
        },
        affiliateSaved() {
            this.togglePanel()
            this.getAllAffiliates()
        },
        greenDot(affiliate) {
            if (affiliate.published == true) {
                return true;
            }
            return false
        },
        getAllAffiliates() {
            axios.get('/cp/resrv/affiliate')
            .then(response => {
                this.affiliates = response.data
                this.affiliatesLoaded = true                
            })
            .catch(error => {
                this.$toast.error('Cannot retrieve affiliates')
            })
        },
        confirmDelete(affiliate) {
            this.deleteId = affiliate.id
        },
        deleteAffiliate() {
            axios.delete(`/cp/resrv/affiliate/${this.deleteId}`)
                .then(response => {
                    this.$toast.success('Affiliate deleted')
                    this.deleteId = false
                    this.getAllAffiliates()
                })
                .catch(error => {
                    this.$toast.error('Cannot delete affiliate')
                })
        },
    }
}
</script>