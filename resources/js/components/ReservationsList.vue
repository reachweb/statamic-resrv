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
                <div class="card p-0 relative">
                    <data-list-filter-presets
                        v-if="!reordering"
                        ref="presets"
                        :active-preset="activePreset"
                        :preferences-prefix="preferencesPrefix"
                        @selected="selectPreset"
                        @reset="filtersReset"
                    />
                    <div class="data-list-header">
                        <data-list-filters
                            :filters="filters"
                            :active-preset="activePreset"
                            :active-preset-payload="activePresetPayload"
                            :active-filters="activeFilters"
                            :active-filter-badges="activeFilterBadges"
                            :active-count="activeFilterCount"
                            :search-query="searchQuery"
                            :saves-presets="true"
                            :preferences-prefix="preferencesPrefix"
                            @filter-changed="filterChanged"
                            @search-changed="searchChanged"
                            @saved="$refs.presets.setPreset($event)"
                            @deleted="$refs.presets.refreshPresets()"
                            @restore-preset="$refs.presets.viewPreset($event)"
                            @reset="filtersReset"
                        />
                    </div>

                    <div v-show="items.length === 0" class="p-3 text-center text-grey-50" v-text="__('No results')" />

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
                        <template slot="cell-entry" slot-scope="{ row: reservation }">
                            <a :href="reservation.entry.permalink" target="_blank">{{ reservation.entry.title }}</a>
                        </template>
                        <template slot="cell-location_start" slot-scope="{ row: reservation }">
                            {{ reservation.location_start.name }}
                        </template>
                        <template slot="cell-location_end" slot-scope="{ row: reservation }">
                            {{ reservation.location_end.name }}
                        </template>
                        <template slot="cell-customer" slot-scope="{ row: reservation }">
                            <a :href="'mailto:'+reservation.customer.email" target="_blank">{{ reservation.customer.email }}</a>
                        </template>
                        <template slot="actions" slot-scope="{ row: reservation }">
                            <dropdown-list>
                                <dropdown-item :text="__('View')" :redirect="showUrl(reservation)" />                                
                            </dropdown-list>
                        </template>
                    </data-list-table>
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
    </div>

</template>

<script>

export default {
    mixins: [Listing],

    props: {
        reservationsUrl: '',
        showRoute: '',
    },

    data() {
        return {
            rows: this.initialRows,
            requestUrl: this.reservationsUrl,
            preferencesPrefix: 'resrv.reservations',
        }
    },

    methods: {
        showUrl(reservation) {
            return this.showRoute.replace('RESRVURL', reservation.id)
        },
        badgeClass(status) {
            if (status == 'confirmed') {
                return 'bg-green-800'
            } else if (status == 'refunded') {
                return 'bg-yellow-800'
            } else if (status == 'expired') {
                return 'bg-red-800'
            } else {
                return 'bg-gray-800'
            }
        }
    },
}
</script>
