<template>
    <div class="card">
        <div class="flex my-2">
            <div class="date-container input-group max-w-xl">
                <v-date-picker
                    v-model="date"
                    :model-config="modelConfig"
                    :popover="{ visibility: 'click' }"
                    :masks="{ input: 'YYYY-MM-DD' }"
                    :mode="'date'"
                    :columns="$screens({ default: 1, lg: 2 })"
                    is-range
                    >
                    <template v-slot="{ inputValue, inputEvents }">
                        <div class="w-full flex items-center">
                        <div class="input-group">
                            <div class="input-group-prepend flex items-center">
                                <svg-icon name="calendar" class="w-4 h-4" />
                            </div>
                            <div class="input-text border border-grey-50 border-l-0" :class="{ 'read-only': isReadOnly }">
                                <input
                                    class="input-text-minimal p-0 bg-transparent leading-none"
                                    :value="inputValue.start"
                                    v-on="inputEvents.start"
                                />
                            </div>
                        </div>
                        <div class="icon icon-arrow-right my-sm mx-1 text-grey-60" />
                        <div class="input-group">
                            <div class="input-group-prepend flex items-center">
                                <svg-icon name="calendar" class="w-4 h-4" />
                            </div>
                            <div class="input-text border border-grey-50 border-l-0" :class="{ 'read-only': isReadOnly }">
                                <input
                                    class="input-text-minimal p-0 bg-transparent leading-none"
                                    :value="inputValue.end"
                                    v-on="inputEvents.end"
                                />
                            </div>
                        </div>
                    </div>
                    </template>
                </v-date-picker>
            </div>
        </div>
        <div class="flex flex-wrap items-center text-center border-t mt-4 pt-4">
            <div class="w-full lg:w-1/3">
                <div class="mb-2">{{ __('Reservations') }}</div>
                <div class="text-2xl">{{ reportData.total_confirmed_reservations }}</div>
            </div>
            <div class="w-full lg:w-1/3">
                <div class="mb-2">{{ __('Revenue') }}</div>
                <div class="text-2xl">{{ currency }} {{ reportData.total_revenue }}</div>
            </div>
            <div class="w-full lg:w-1/3">
                <div class="mb-2">{{ __('Average reservation value') }}</div>
                <div class="text-2xl">{{ currency }} {{ reportData.avg_revenue }}</div>
            </div>
        </div>
        <div class="border-t mt-4 pt-4">
            <div class="text-2xl font-bold mb-2 ml-1">{{ __('Best sellers') }}</div>
            <reports-items-table
                v-if="reportDataLoaded"
                :items="reportData.top_seller_items"
                :table-columns="'items'"
                :currency="currency"
            >
            </reports-items-table>
        </div>
        <div class="border-t mt-4 pt-4" v-if="reportDataLoaded">
            <template v-if="reportData.top_seller_extras"> 
                <div class="text-2xl font-bold mb-2 ml-1">{{ __('Top selling extras') }}</div>
                <reports-items-table
                    v-if="reportDataLoaded"
                    :items="reportData.top_seller_extras"
                    :table-columns="'other'"
                    :currency="currency"
                >
                </reports-items-table>
            </template>
        </div>
        <div class="border-t mt-4 pt-4" v-if="reportDataLoaded">
            <template v-if="reportData.top_seller_starting_locations"> 
                <div class="text-2xl font-bold mb-2 ml-1">{{ __('Top starting locations') }}</div>
                <reports-items-table
                    v-if="reportDataLoaded"
                    :items="reportData.top_seller_starting_locations"
                    :table-columns="'other'"
                    :currency="currency"
                >
                </reports-items-table>
            </template>
        </div>        
    </div>
</template>
<script>
import axios from 'axios'
import ReportsItemsTable from './ReportsItemsTable.vue'

export default ({
    props: {
        reportsUrl: '',
        currency: ''
    },

    data() {
        return {
            reportData: '',           
            reportDataLoaded: false,
            date: {
                start: Vue.moment().subtract(7, 'days').toDate(),
                end: Vue.moment().toDate()
            },
        }
    },

    mounted() {
        this.getReports()        
    },

    watch: {
        date() {
            this.getReports()
        }
    },

    components: {
        ReportsItemsTable
    },

    methods: {
        getReports() {
            axios.get(this.reportsUrl+"?start="+Vue.moment(this.date.start).format('YYYY-MM-DD')+"&end="+Vue.moment(this.date.end).format('YYYY-MM-DD'))
            .then(response => {
                this.reportData = response.data
                this.reportDataLoaded = true       
            })
            .catch(error => {
                this.$toast.error('Cannot retrieve report data')
            })
        },
    }


})
</script>
