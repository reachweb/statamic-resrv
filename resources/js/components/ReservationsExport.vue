<template>
    <div class="card">
        <div class="flex flex-wrap items-center py-4 gap-3">
            <label class="text-sm font-semibold">{{ __('Reservation date range') }}</label>
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
                                    <svg-icon name="light/calendar" class="w-4 h-4" />
                                </div>
                                <div class="input-text border border-grey-50 border-l-0">
                                    <input
                                        class="input-text-minimal p-0 bg-transparent leading-none w-24 text-sm"
                                        :value="inputValue.start"
                                        v-on="inputEvents.start"
                                    />
                                </div>
                            </div>
                            <div class="icon icon-arrow-right my-sm mx-1 text-grey-60" />
                            <div class="input-group">
                                <div class="input-group-prepend flex items-center">
                                    <svg-icon name="light/calendar" class="w-4 h-4" />
                                </div>
                                <div class="input-text border border-grey-50 border-l-0">
                                    <input
                                        class="input-text-minimal p-0 bg-transparent leading-none w-24 text-sm"
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

        <div class="border-t mt-4 pt-4">
            <div class="text-sm font-semibold mb-2">{{ __('Status') }}</div>
            <div class="flex flex-wrap gap-3">
                <label
                    v-for="status in statuses"
                    :key="status"
                    class="inline-flex items-center gap-2 text-sm capitalize cursor-pointer"
                >
                    <input type="checkbox" :value="status" v-model="selectedStatuses" />
                    {{ status }}
                </label>
            </div>
        </div>

        <div class="border-t mt-4 pt-4 flex flex-wrap gap-6">
            <div class="flex-1 min-w-[240px]">
                <div class="text-sm font-semibold mb-2">{{ __('Item') }}</div>
                <v-select
                    v-model="selectedEntry"
                    :options="entries"
                    :reduce="option => option.item_id"
                    label="title"
                    :placeholder="__('All items')"
                />
            </div>
            <div class="flex-1 min-w-[240px]" v-if="affiliates.length > 0">
                <div class="text-sm font-semibold mb-2">{{ __('Affiliate') }}</div>
                <v-select
                    v-model="selectedAffiliate"
                    :options="affiliates"
                    :reduce="option => option.id"
                    label="name"
                    :placeholder="__('All affiliates')"
                />
            </div>
        </div>

        <div class="border-t mt-4 pt-4">
            <div class="text-sm font-semibold mb-2">{{ __('Fields to export') }}</div>
            <div class="flex flex-wrap gap-6">
                <div v-for="(group, groupName) in fieldsByGroup" :key="groupName" class="min-w-[200px]">
                    <div class="flex items-center gap-2 mb-2">
                        <label class="text-xs uppercase tracking-wide text-grey-70 font-bold">{{ groupName }}</label>
                        <button
                            type="button"
                            class="text-xs text-blue underline"
                            @click="toggleGroup(groupName)"
                        >
                            {{ allGroupSelected(groupName) ? __('None') : __('All') }}
                        </button>
                    </div>
                    <label
                        v-for="field in group"
                        :key="field.key"
                        class="flex items-center gap-2 text-sm cursor-pointer mb-1"
                    >
                        <input type="checkbox" :value="field.key" v-model="selectedFields" />
                        {{ field.label }}
                    </label>
                </div>
            </div>
        </div>

        <div class="border-t mt-4 pt-4 flex flex-wrap items-center gap-4">
            <div class="text-base">
                <template v-if="countLoading">{{ __('Counting…') }}</template>
                <template v-else-if="countError">{{ __('Could not count reservations') }}</template>
                <template v-else>
                    <strong>{{ count }}</strong> {{ __('reservations match') }}
                </template>
            </div>
            <button
                type="button"
                class="btn-primary"
                :disabled="!canDownload"
                @click="download"
            >
                {{ __('Download CSV') }}
            </button>
        </div>
    </div>
</template>

<script>
import axios from 'axios'

const STORAGE_KEY = 'resrv-export-selected-fields'

export default {
    props: {
        countUrl: { type: String, required: true },
        downloadUrl: { type: String, required: true },
        fields: { type: Array, default: () => [] },
        statuses: { type: Array, default: () => [] },
        entries: { type: Array, default: () => [] },
        affiliates: { type: Array, default: () => [] },
    },

    data() {
        return {
            date: {
                start: Vue.moment().subtract(30, 'days').toDate(),
                end: Vue.moment().toDate(),
            },
            modelConfig: {
                type: 'string',
                mask: 'YYYY-MM-DD',
            },
            selectedStatuses: [...this.statuses],
            selectedEntry: null,
            selectedAffiliate: null,
            selectedFields: this.loadSelectedFields(),
            count: 0,
            countLoading: false,
            countError: false,
            countDebounce: null,
        }
    },

    computed: {
        fieldsByGroup() {
            return this.fields.reduce((groups, field) => {
                groups[field.group] = groups[field.group] || []
                groups[field.group].push(field)
                return groups
            }, {})
        },
        canDownload() {
            return !this.countLoading && this.count > 0 && this.selectedFields.length > 0 && this.selectedStatuses.length > 0
        },
    },

    watch: {
        date() { this.scheduleCount() },
        selectedStatuses() { this.scheduleCount() },
        selectedEntry() { this.scheduleCount() },
        selectedAffiliate() { this.scheduleCount() },
        selectedFields(value) {
            try {
                window.localStorage.setItem(STORAGE_KEY, JSON.stringify(value))
            } catch (e) {}
        },
    },

    mounted() {
        this.fetchCount()
    },

    beforeDestroy() {
        clearTimeout(this.countDebounce)
    },

    methods: {
        loadSelectedFields() {
            try {
                const stored = window.localStorage.getItem(STORAGE_KEY)
                if (stored) {
                    const parsed = JSON.parse(stored)
                    if (Array.isArray(parsed)) {
                        const valid = new Set(this.fields.map(f => f.key))
                        return parsed.filter(k => valid.has(k))
                    }
                }
            } catch (e) {
                console.warn('Failed to load saved export fields:', e)
            }
            return this.fields.filter(f => f.default).map(f => f.key)
        },
        scheduleCount() {
            clearTimeout(this.countDebounce)
            this.countDebounce = setTimeout(() => this.fetchCount(), 250)
        },
        fetchCount() {
            if (this.selectedStatuses.length === 0) {
                this.count = 0
                this.countLoading = false
                this.countError = false
                return
            }
            this.countLoading = true
            this.countError = false
            axios.get(this.countUrl + '?' + this.buildParams().toString())
                .then(response => {
                    this.count = response.data.count
                    this.countLoading = false
                })
                .catch(() => {
                    this.countLoading = false
                    this.countError = true
                    this.$toast.error(this.__('Cannot retrieve reservation count'))
                })
        },
        download() {
            if (!this.canDownload) return
            const params = this.buildParams()
            this.selectedFields.forEach(f => params.append('fields[]', f))
            window.location = this.downloadUrl + '?' + params.toString()
        },
        buildParams() {
            const params = new URLSearchParams()
            params.append('start', Vue.moment(this.date.start).format('YYYY-MM-DD'))
            params.append('end', Vue.moment(this.date.end).format('YYYY-MM-DD'))
            this.selectedStatuses.forEach(s => params.append('statuses[]', s))
            if (this.selectedEntry) params.append('item_id', this.selectedEntry)
            if (this.selectedAffiliate) params.append('affiliate_id', this.selectedAffiliate)
            return params
        },
        allGroupSelected(groupName) {
            const groupKeys = this.fieldsByGroup[groupName].map(f => f.key)
            return groupKeys.every(k => this.selectedFields.includes(k))
        },
        toggleGroup(groupName) {
            const groupKeys = this.fieldsByGroup[groupName].map(f => f.key)
            if (this.allGroupSelected(groupName)) {
                this.selectedFields = this.selectedFields.filter(k => !groupKeys.includes(k))
            } else {
                const set = new Set(this.selectedFields)
                groupKeys.forEach(k => set.add(k))
                this.selectedFields = [...set]
            }
        },
    },
}
</script>
