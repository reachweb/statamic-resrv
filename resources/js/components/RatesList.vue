<template>
    <div>
    <div class="w-full h-full" v-if="dataLoaded">
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
                </div>
                <div class="flex items-center space-x-2">
                    <span class="text-gray-700 dark:text-dark-100 text-sm uppercase" v-html="rate.pricing_type"></span>
                    <span class="text-gray-700 dark:text-dark-100 text-sm uppercase" v-html="rate.availability_type"></span>
                    <dropdown-list>
                        <dropdown-item :text="__('Edit')" @click="edit(rate)" />
                        <dropdown-item :text="__('Delete')" @click="confirmDelete(rate)" />
                    </dropdown-list>
                </div>
            </div>
        </vue-draggable>
    </div>
    <div class="w-full mt-4">
        <button class="btn-primary" @click="add" v-html="__('Add rate')"></button>
    </div>
    <rates-panel
        v-if="showPanel"
        :data="rate"
        :all-rates="rates"
        @closed="togglePanel"
        @saved="dataSaved"
    >
    </rates-panel>
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
    props: {
        parent: {
            type: String,
            required: false
        }
    },

    data() {
        return {
            showPanel: false,
            rates: '',
            dataLoaded: false,
            deleteId: false,
            drag: false,
            rate: '',
            emptyRate: {
                statamic_id: this.parent,
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
        }
    },

    components: {
        RatePanel,
        VueDraggable
    },

    mounted() {
        this.getRates()
    },

    methods: {
        togglePanel() {
            this.showPanel = !this.showPanel
        },
        add() {
            this.rate = this.emptyRate
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
        getRates() {
            axios.get('/cp/resrv/rate/' + this.parent)
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
