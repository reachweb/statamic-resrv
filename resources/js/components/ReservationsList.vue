<template>
    <div>
        <div v-if="initializing" class="card loading">
            <loading-graphic />
        </div>

        <data-list
            v-if="! initializing"
            :columns="columns"
            :rows="items"
            :sort="false"
            :sort-column="sortColumn"
            :sort-direction="sortDirection"
        >
            <div slot-scope="{ hasSelections }">
                <div class="card overflow-hidden p-0 relative">
                    <div class="flex flex-wrap items-center justify-between px-2 pb-2 text-sm border-b">
                        <data-list-filter-presets
                            ref="presets"
                            :active-preset="activePreset"
                            :active-preset-payload="activePresetPayload"
                            :active-filters="activeFilters"
                            :has-active-filters="hasActiveFilters"
                            :preferences-prefix="preferencesPrefix"
                            :search-query="searchQuery"
                            @selected="selectPreset"
                            @reset="filtersReset"
                        />

                        <data-list-search class="h-8 mt-2 min-w-[240px] w-full" ref="search" v-model="searchQuery" :placeholder="searchPlaceholder" />

                        <div class="flex space-x-2 mt-2">
                            <button class="btn btn-sm ml-2" v-text="__('Reset')" v-show="isDirty" @click="$refs.presets.refreshPreset()" />
                            <button class="btn btn-sm ml-2" v-text="__('Save')" v-show="isDirty" @click="$refs.presets.savePreset()" />
                            <data-list-column-picker :preferences-key="preferencesKey('columns')" />
                        </div>
                    </div>

                    <div>
                        <data-list-filters
                            ref="filters"
                            :filters="filters"
                            :active-preset="activePreset"
                            :active-preset-payload="activePresetPayload"
                            :active-filters="activeFilters"
                            :active-filter-badges="activeFilterBadges"
                            :active-count="activeFilterCount"
                            :search-query="searchQuery"
                            :is-searching="true"
                            :saves-presets="true"
                            :preferences-prefix="preferencesPrefix"
                            @changed="filterChanged"
                            @saved="$refs.presets.setPreset($event)"
                            @deleted="$refs.presets.refreshPresets()"
                        />
                    </div>

                    <div v-show="items.length === 0" class="p-6 text-center text-gray-500" v-text="__('No results')" />

                    <div class="overflow-x-auto overflow-y-hidden">
                        <data-list-table
                            v-if="items.length"
                            :loading="loading"
                            :allow-bulk-actions="false"
                            :allow-column-picker="true"
                            :column-preferences-key="preferencesKey('columns')"
                            @sorted="sorted"
                        >
                            <template slot="cell-status" slot-scope="{ row: reservation }">
                                <a :href="showUrl(reservation)" :class="badgeClass(reservation.status)" class="inline-block min-w-[100px] text-center p-1 text-white text-xs bg-green-800">
                                    {{ reservation.status.toUpperCase() }}
                                </a>                        
                            </template>
                            <template slot="cell-entry" slot-scope="{ row: reservation }">
                                <a :href="reservation.entry.permalink" target="_blank">{{ reservation.entry.title }}</a>
                            </template>
                            <template slot="cell-location_start" slot-scope="{ row: reservation }" v-if="reservation.location_start">
                                {{ reservation.location_start.name }}
                            </template>
                            <template slot="cell-location_end" slot-scope="{ row: reservation }" v-if="reservation.location_start">
                                {{ reservation.location_end.name }}
                            </template>
                            <template slot="cell-customer" slot-scope="{ row: reservation }">
                                <a :href="'mailto:'+customerEmail(reservation.customer)">{{ customerEmail(reservation.customer) }}</a>
                            </template>
                            <template slot="actions" slot-scope="{ row: reservation }">
                                <dropdown-list>
                                    <dropdown-item :text="__('View')" :redirect="showUrl(reservation)" />                                
                                    <dropdown-item :text="__('Refund')" @click="refundConfirm(reservation)" />                                
                                </dropdown-list>
                            </template>
                        </data-list-table>
                    </div>
                </div>

                <data-list-pagination
                    class="mt-3"
                    :resource-meta="meta"
                    :per-page="perPage"
                    @page-selected="selectPage"
                    @per-page-changed="changePerPage"
                />
            </div>
        </data-list>
        <confirmation-modal
            v-if="refundId"
            title="Refund and cancel reservation"
            :danger="true"
            @confirm="refund"
            @cancel="refundId = false"
        >
            Are you sure you want to refund this reservation? <strong>This cannot be undone.</strong><br>
            All charges will be refunded and the customer will be notified.
        </confirmation-modal>
    </div>

</template>

<script>
import axios from 'axios'

export default {
    mixins: [Listing],

    props: {
        reservationsUrl: '',
        showRoute: '',
        refundRoute: ''   
    },

    data() {
        return {
            rows: this.initialRows,
            requestUrl: this.reservationsUrl,
            preferencesPrefix: 'resrv.reservations',
            refundId: false
        }
    },

    methods: {
        showUrl(reservation) {
            return this.showRoute.replace('RESRVURL', reservation.id)
        },
        badgeClass(status) {
            if (status == 'confirmed') {
                return 'bg-green-800'
            } else if (status == 'partner') {
                return 'bg-green-600'
            } else if (status == 'refunded') {
                return 'bg-yellow-800'
            } else if (status == 'expired') {
                return 'bg-red-800'
            } else {
                return 'bg-gray-800'
            }
        },
        refundConfirm(reservation) {
            this.refundId = reservation.id
        },
        customerEmail(customer) {
            if (_.has(customer, 'email')) {
                return customer.email
            }
            return ''
        },
        refund() {
            axios.patch(this.refundRoute, {id: this.refundId})
            .then(response => {
                this.$toast.success('Reservation refunded')
                this.refundId = false
                this.request()        
            })
            .catch(error => {
                this.$toast.error(error.response.data.error)
            })
        },
    },
}
</script>
