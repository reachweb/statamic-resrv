<template>
    <div>
    <div class="w-full mb-4 xl:w-1/2">
        <div class="mb-1 text-sm">
            <label class="font-semibold">Collection</label>
        </div>
        <v-select
            v-model="selectedCollection"
            :options="collections"
            label="title"
            :reduce="c => c.handle"
            :clearable="false"
            @input="collectionChanged"
            placeholder="Select a collection..."
        />
    </div>
    <div class="w-full h-full" v-if="dataLoaded && selectedCollection">
        <vue-draggable class="mt-4 space-y-2" v-model="rates" @start="drag=true" @end="drag=false" @change="order">
            <div
                v-for="rate in rates"
                :key="rate.id"
                class="w-full flex flex-wrap items-center justify-between p-3 shadow-sm rounded-md border transition-colors
                bg-gray-100 dark:border-dark-900 dark:bg-dark-550 dark:shadow-dark-sm"
            >
                <div class="flex items-center space-x-2">
                    <div class="little-dot" :class="rate.published == true ? 'bg-green-600' : 'bg-gray-400'"></div>
                    <span class="font-medium cursor-pointer" v-html="rate.title" @click="edit(rate)"></span>
                    <span v-if="rate.apply_to_all" class="text-xs text-gray-500 dark:text-dark-100 bg-gray-200 dark:bg-dark-400 px-1.5 py-0.5 rounded">All entries</span>
                </div>
                <div class="flex items-center space-x-2">
                    <span v-if="rate.pricing_type === 'relative'" class="text-xs text-gray-500 dark:text-dark-100 bg-gray-200 dark:bg-dark-400 px-1.5 py-0.5 rounded uppercase">Relative</span>
                    <span v-if="rate.availability_type === 'shared'" class="text-xs text-gray-500 dark:text-dark-100 bg-gray-200 dark:bg-dark-400 px-1.5 py-0.5 rounded uppercase">Shared</span>
                    <dropdown-list>
                        <dropdown-item :text="__('Edit')" @click="edit(rate)" />
                        <dropdown-item :text="__('Delete')" @click="confirmDelete(rate)" />
                    </dropdown-list>
                </div>
            </div>
        </vue-draggable>
    </div>
    <div class="w-full mt-4" v-if="selectedCollection">
        <button class="btn-primary" @click="add" v-html="__('Add rate')"></button>
    </div>
    <rate-panel
        v-if="showPanel"
        :data="rate"
        :all-rates="rates"
        :collections="collections"
        :selected-collection="selectedCollection"
        @closed="togglePanel"
        @saved="dataSaved"
    >
    </rate-panel>
    <confirmation-modal
        v-if="deleteId"
        title="Delete rate"
        :danger="true"
        @confirm="deleteRate"
        @cancel="deleteId = false"
    >
        Are you sure you want to delete this rate? <strong>This cannot be undone.</strong>
    </confirmation-modal>
    </div>
</template>
<script>
import axios from 'axios'
import RatePanel from './RatePanel.vue'
import VueDraggable from 'vuedraggable'

export default {
    data() {
        return {
            showPanel: false,
            rates: '',
            collections: [],
            selectedCollection: null,
            dataLoaded: false,
            deleteId: false,
            drag: false,
            rate: '',
        }
    },

    components: {
        RatePanel,
        VueDraggable
    },

    mounted() {
        this.getCollections()
    },

    methods: {
        togglePanel() {
            this.showPanel = !this.showPanel
        },
        add() {
            this.rate = {
                collection: this.selectedCollection,
                apply_to_all: true,
                entries: [],
                title: '',
                slug: '',
                description: '',
                pricing_type: 'independent',
                base_rate_id: null,
                modifier_type: null,
                modifier_operation: null,
                modifier_amount: null,
                availability_type: 'independent',
                max_available: null,
                date_start: null,
                date_end: null,
                min_days_before: null,
                min_stay: null,
                max_stay: null,
                refundable: true,
                published: true,
            }
            this.togglePanel()
        },
        edit(rate) {
            this.rate = rate
            this.togglePanel()
        },
        dataSaved() {
            this.togglePanel()
            this.getRates()
        },
        getCollections() {
            axios.get('/cp/resrv/rates/collections')
            .then(response => {
                this.collections = response.data
                if (this.collections.length > 0) {
                    this.selectedCollection = this.collections[0].handle
                    this.getRates()
                }
            })
            .catch(error => {
                this.$toast.error('Cannot retrieve collections')
            })
        },
        collectionChanged() {
            if (this.selectedCollection) {
                this.getRates()
            }
        },
        getRates() {
            axios.get('/cp/resrv/rates/index', { params: { collection: this.selectedCollection } })
            .then(response => {
                this.rates = response.data
                this.dataLoaded = true
            })
            .catch(error => {
                this.$toast.error('Cannot retrieve rates')
            })
        },
        confirmDelete(rate) {
            this.deleteId = rate.id
        },
        deleteRate() {
            axios.delete('/cp/resrv/rate/' + this.deleteId)
                .then(response => {
                    this.$toast.success('Rate deleted')
                    this.deleteId = false
                    this.getRates()
                })
                .catch(error => {
                    if (error.response && error.response.status === 422) {
                        this.$toast.error(error.response.data.message)
                    } else {
                        this.$toast.error('Cannot delete rate')
                    }
                    this.deleteId = false
                })
        },
        order(event) {
            let orderData = this.rates.map((rate, index) => ({
                id: rate.id,
                order: index + 1
            }))
            axios.post('/cp/resrv/rate/order', orderData)
                .then(() => {
                    this.$toast.success('Rates order changed')
                    this.getRates()
                })
                .catch(() => { this.$toast.error('Rates ordering failed') })
        }
    }
}
</script>
