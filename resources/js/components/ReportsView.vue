<template>
    <div class="card">
        <div class="flex my-2">
            <div class="date-container input-group">
                <div class="input-group-prepend flex items-center">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-4 h-4"><rect width="22" height="20" x=".5" y="3.501" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" rx="1" ry="1"></rect><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" d="M3.5 1.501v3m4-3v3m4-3v3m4-3v3m4-3v3m-7 3.999h3v4h0-4 0v-3a1 1 0 0 1 1-1zm3 0h3a1 1 0 0 1 1 1v3h0-4 0v-4h0zm-4 4.001h4v4h-4zm4 0h4v4h-4zm-4 4h4v4h-4z"></path><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" d="M15.5 16.5h4v3a1 1 0 0 1-1 1h-3 0v-4h0zm-11-4h3v4h0-4 0v-3a1 1 0 0 1 1-1zm3 .001h4v4h-4zm-4 3.999h4v4h0-3a1 1 0 0 1-1-1v-3h0zm4 .001h4v4h-4z"></path></svg>
                </div>
                <v-date-picker
                    v-model="date"
                    :model-config="modelConfig"
                    :popover="{ visibility: 'click' }"
                    :masks="{ input: 'YYYY-MM-DD' }"
                    :mode="'range'"
                    :columns="$screens({ default: 1, lg: 2 })"
                    >
                        <input
                            slot-scope="{ inputProps, inputEvents }"
                            class="input-text border border-grey-50 border-l-0"
                            style="min-width: 240px"
                            v-bind="inputProps"
                            v-on="inputEvents" />
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
