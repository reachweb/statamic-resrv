<template>
    <div>
        <div class="w-full flex justify-end mb-4">
            <button class="btn-primary" @click="addPricing">
                {{ __('Add Dynamic Pricing') }}
            </button>
        </div>

        <div class="card p-0">
            <div class="flex flex-wrap items-center gap-3 px-4 py-5">
                <div class="flex-1 min-w-[220px]">
                    <data-list-search
                        ref="search"
                        v-model="searchQuery"
                        :placeholder="__('Search by title')"
                    />
                </div>
                <div class="min-w-[170px]">
                    <v-select
                        v-model="filters.operation"
                        :options="operationOptions"
                        :reduce="o => o.code"
                        :placeholder="__('Any operation')"
                    />
                </div>
                <div class="min-w-[200px]">
                    <v-select
                        v-model="filters.dates_active"
                        :options="datesActiveOptions"
                        :reduce="o => o.code"
                        :placeholder="__('Any dates')"
                    />
                </div>
                <div class="min-w-[210px]">
                    <v-select
                        v-model="filters.condition"
                        :options="conditionOptions"
                        :reduce="o => o.code"
                        :placeholder="__('Any condition')"
                    />
                </div>
                <button v-if="hasActiveFilters" class="text-sm text-blue-600 hover:underline" @click="resetFilters">
                    {{ __('Reset all') }}
                </button>
            </div>

            <p v-if="isFiltered" class="text-xs text-gray-600 dark:text-dark-175 px-4 pb-4">
                {{ __('Drag to reorder is disabled while filters are active. Use "Move to position" or reset filters.') }}
            </p>

            <div class="relative" v-if="dynamicPricingLoaded">
                <div
                    v-if="loading"
                    class="absolute inset-0 z-10 flex items-center justify-center bg-white/60 dark:bg-dark-600/60 rounded-b-md"
                >
                    <loading-graphic />
                </div>
                <div v-if="dynamicPricings.length === 0" class="p-8 text-center text-gray-500">
                    {{ __('No dynamic pricing found') }}
                </div>
                <vue-draggable
                    v-else
                    class="p-5 space-y-3"
                    v-model="dynamicPricings"
                    :disabled="isFiltered"
                    @start="drag=true"
                    @end="drag=false"
                    @change="onDragChange"
                >
                    <div
                        v-for="dynamic in dynamicPricings"
                        :key="dynamic.id"
                        class="w-full flex flex-wrap items-center justify-between p-3 shadow-sm rounded-md border transition-colors
                        bg-gray-100 dark:border-dark-900 dark:bg-dark-550 dark:shadow-dark-sm"
                        :class="{ 'cursor-move': !isFiltered }"
                    >
                        <div class="flex items-center space-x-2">
                            <span class="text-xs font-mono px-2 py-0.5 rounded bg-gray-300 dark:bg-dark-700 dark:text-dark-150" :title="__('Order')">#{{ dynamic.order }}</span>
                            <span class="font-medium cursor-pointer" v-html="dynamic.title" @click="editPricing(dynamic)"></span>
                            <span class="text-xs p-1 bg-gray-600 dark:text-dark-150 text-white rounded" v-if="dynamic.overrides_all">{{ __('OVERRIDING') }}</span>
                        </div>
                        <div>
                            <dropdown-list>
                                <dropdown-item :text="__('Edit')" @click="editPricing(dynamic)" />
                                <dropdown-item :text="__('Move to position…')" @click="openMoveDialog(dynamic, 'position')" />
                                <dropdown-item :text="__('Move to page…')" @click="openMoveDialog(dynamic, 'page')" />
                                <dropdown-item :text="__('Delete')" @click="confirmDelete(dynamic)" />
                            </dropdown-list>
                        </div>
                    </div>
                </vue-draggable>
            </div>
            <div v-else class="p-8 text-center">
                <loading-graphic />
            </div>
        </div>

        <data-list-pagination
            v-if="dynamicPricingLoaded && total > 0"
            class="mt-3"
            :resource-meta="paginationMeta"
            :per-page="perPage"
            @page-selected="selectPage"
            @per-page-changed="changePerPage"
        />

        <dynamic-pricing-panel
            v-if="showPanel"
            :data="dynamic"
            :timezone="timezone"
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
        <confirmation-modal
            v-if="moveDialog.open"
            :title="moveDialog.mode === 'position' ? __('Move to position') : __('Move to page')"
            :buttonText="__('Move')"
            @confirm="submitMove"
            @cancel="closeMoveDialog"
        >
            <div class="mb-2">
                <span v-if="moveDialog.mode === 'position'">
                    {{ __('Enter the absolute order number (1 is the top of the list).') }}
                </span>
                <span v-else>
                    {{ __('Enter the page number. The item will land at the top of that page.') }}
                </span>
            </div>
            <input type="number" min="1" v-model.number="moveDialog.value" class="input-text" />
            <p v-if="moveDialog.mode === 'page'" class="text-xs text-gray-600 dark:text-dark-175 mt-2">
                {{ __('Resolves to position') }}: {{ resolvedMoveOrder }}
            </p>
        </confirmation-modal>
    </div>
</template>
<script>
import axios from 'axios'
import DynamicPricingPanel from './DynamicPricingPanel.vue'
import VueDraggable from 'vuedraggable'

export default {

    props: {
        timezone: {
            type: String,
            required: true,
            default: 'UTC'
        }
    },

    data() {
        return {
            showPanel: false,
            dynamicPricings: [],
            dynamicPricingLoaded: false,
            loading: false,
            deleteId: false,
            dynamic: '',
            drag: false,
            searchQuery: '',
            filters: {
                operation: '',
                dates_active: '',
                condition: '',
            },
            currentPage: 1,
            perPage: 25,
            total: 0,
            lastPage: 1,
            searchDebounce: null,
            resetting: false,
            moveDialog: {
                open: false,
                mode: null,
                item: null,
                value: 1,
            },
            operationOptions: [
                { code: 'increase', label: 'Increase' },
                { code: 'decrease', label: 'Decrease' },
                { code: 'minimum', label: 'Minimum' },
                { code: 'maximum', label: 'Maximum' },
            ],
            datesActiveOptions: [
                { code: 'active', label: 'Currently active' },
                { code: 'upcoming', label: 'Upcoming' },
                { code: 'expired', label: 'Expired' },
                { code: 'always', label: 'Always-on (no dates)' },
            ],
            conditionOptions: [
                { code: 'reservation_duration', label: 'Reservation duration' },
                { code: 'reservation_price', label: 'Reservation price' },
                { code: 'days_to_reservation', label: 'Days to reservation' },
                { code: 'none', label: 'No condition' },
            ],
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
                overrides_all: false,
            }
        }
    },

    components: {
        DynamicPricingPanel,
        VueDraggable
    },

    computed: {
        hasActiveFilters() {
            return !!this.searchQuery || !!this.filters.operation || !!this.filters.dates_active || !!this.filters.condition
        },
        isFiltered() {
            return this.hasActiveFilters
        },
        paginationMeta() {
            return {
                current_page: this.currentPage,
                last_page: this.lastPage,
                per_page: this.perPage,
                total: this.total,
                from: this.total === 0 ? 0 : (this.currentPage - 1) * this.perPage + 1,
                to: Math.min(this.currentPage * this.perPage, this.total),
            }
        },
        resolvedMoveOrder() {
            if (this.moveDialog.mode !== 'page') return null
            const page = Math.min(Math.max(1, parseInt(this.moveDialog.value) || 1), this.lastPage)
            return (page - 1) * this.perPage + 1
        },
    },

    watch: {
        searchQuery() {
            if (this.resetting) return
            clearTimeout(this.searchDebounce)
            this.searchDebounce = setTimeout(() => {
                this.currentPage = 1
                this.fetchPricings()
            }, 300)
        },
        'filters.operation'() { if (this.resetting) return; this.currentPage = 1; this.fetchPricings() },
        'filters.dates_active'() { if (this.resetting) return; this.currentPage = 1; this.fetchPricings() },
        'filters.condition'() { if (this.resetting) return; this.currentPage = 1; this.fetchPricings() },
    },

    mounted() {
        this.fetchPricings()
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
            this.fetchPricings()
        },
        fetchPricings() {
            const params = { page: this.currentPage, per_page: this.perPage }
            if (this.searchQuery) params.search = this.searchQuery
            if (this.filters.operation) params.operation = this.filters.operation
            if (this.filters.dates_active) params.dates_active = this.filters.dates_active
            if (this.filters.condition) params.condition = this.filters.condition

            this.loading = true
            return axios.get('/cp/resrv/dynamicpricing/index', { params })
                .then(response => {
                    const lastPage = response.data.last_page || 1
                    const currentPage = response.data.current_page || 1
                    if (currentPage > lastPage && (response.data.total || 0) > 0) {
                        this.currentPage = lastPage
                        return this.fetchPricings()
                    }
                    this.dynamicPricings = response.data.data || []
                    this.total = response.data.total || 0
                    this.lastPage = lastPage
                    this.currentPage = currentPage
                    this.dynamicPricingLoaded = true
                })
                .catch(() => {
                    this.$toast.error('Cannot retrieve dynamic pricing')
                })
                .then(() => {
                    this.loading = false
                })
        },
        resetFilters() {
            this.resetting = true
            clearTimeout(this.searchDebounce)
            this.searchQuery = ''
            this.filters.operation = ''
            this.filters.dates_active = ''
            this.filters.condition = ''
            this.$nextTick(() => {
                this.resetting = false
                this.currentPage = 1
                this.fetchPricings()
            })
        },
        selectPage(page) {
            this.currentPage = page
            this.fetchPricings()
        },
        changePerPage(perPage) {
            this.perPage = perPage
            this.currentPage = 1
            this.fetchPricings()
        },
        confirmDelete(dynamic) {
            this.deleteId = dynamic.id
        },
        deleteDynamic() {
            axios.delete('/cp/resrv/dynamicpricing', {data: {'id': this.deleteId}})
                .then(() => {
                    this.$toast.success('Dynamic pricing deleted')
                    this.deleteId = false
                    this.fetchPricings()
                })
                .catch(() => {
                    this.$toast.error('Cannot delete dynamic pricing')
                })
        },
        onDragChange(event) {
            if (!event.moved) return
            const { newIndex, oldIndex } = event.moved
            const item = event.moved.element
            let neighbour
            if (newIndex < oldIndex) {
                neighbour = this.dynamicPricings[newIndex + 1]
            } else if (newIndex > oldIndex) {
                neighbour = this.dynamicPricings[newIndex - 1]
            } else {
                return
            }
            if (!neighbour) return
            this.patchOrder(item.id, neighbour.order)
        },
        patchOrder(id, order) {
            axios.patch('/cp/resrv/dynamicpricing/order', { id, order })
                .then(() => {
                    this.$toast.success('Dynamic pricing order changed')
                    this.fetchPricings()
                })
                .catch(() => { this.$toast.error('Dynamic pricing ordering failed') })
        },
        openMoveDialog(item, mode) {
            this.moveDialog = { open: true, mode, item, value: mode === 'page' ? this.currentPage : item.order }
        },
        closeMoveDialog() {
            this.moveDialog = { open: false, mode: null, item: null, value: 1 }
        },
        submitMove() {
            let value = Math.max(1, parseInt(this.moveDialog.value) || 1)
            if (this.moveDialog.mode === 'page') {
                value = Math.min(value, this.lastPage)
            }
            const order = this.moveDialog.mode === 'page'
                ? (value - 1) * this.perPage + 1
                : value
            const id = this.moveDialog.item.id
            this.closeMoveDialog()
            this.patchOrder(id, order)
        },
    }
}
</script>
