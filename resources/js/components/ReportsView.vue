<template>
    <div>
        <Header :title="__('Reports')" icon="chart-monitoring-indicator" />

        <Card class="mb-6">
            <div class="flex flex-wrap items-end gap-4">
                <div class="min-w-[220px]">
                    <Field :label="__('Filter by')">
                        <Select v-model="dateField" :options="dateFieldOptions" :clearable="false" />
                    </Field>
                </div>
                <div class="min-w-[320px]">
                    <Field :label="__('Date range')">
                        <DateRangePicker v-model="dateRange" granularity="day" />
                    </Field>
                </div>
            </div>
        </Card>

        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
            <Card class="text-center">
                <div class="text-sm text-gray-600 dark:text-gray-400 mb-2">{{ __('Reservations') }}</div>
                <div class="text-3xl font-semibold text-gray-900 dark:text-gray-100">{{ report.total_reservations ?? 0 }}</div>
            </Card>
            <Card class="text-center">
                <div class="text-sm text-gray-600 dark:text-gray-400 mb-2">{{ __('Revenue') }}</div>
                <div class="text-3xl font-semibold text-gray-900 dark:text-gray-100">{{ currency }} {{ report.total_revenue ?? 0 }}</div>
            </Card>
            <Card class="text-center">
                <div class="text-sm text-gray-600 dark:text-gray-400 mb-2">{{ __('Average reservation value') }}</div>
                <div class="text-3xl font-semibold text-gray-900 dark:text-gray-100">{{ currency }} {{ report.avg_revenue ?? 0 }}</div>
            </Card>
            <Card class="text-center">
                <div class="text-sm text-gray-600 dark:text-gray-400 mb-2">{{ __('Outstanding payments') }}</div>
                <div class="text-3xl font-semibold text-gray-900 dark:text-gray-100">{{ currency }} {{ report.outstanding?.amount ?? 0 }}</div>
                <div class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                    {{ __n(':count reservation awaiting payment|:count reservations awaiting payment', outstandingCount, { count: outstandingCount }) }}
                </div>
                <a
                    v-if="outstandingCount > 0"
                    :href="outstandingUrl"
                    class="mt-2 inline-block text-sm text-blue-600 underline hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
                >
                    {{ __('View these reservations') }}
                </a>
            </Card>
        </div>

        <Panel :heading="__('Best sellers')" class="mb-6">
            <ReportsItemsTable
                v-if="report.top_seller_items"
                :items="report.top_seller_items"
                :table-columns="'items'"
                :currency="currency"
            />
        </Panel>

        <Panel v-if="report.top_seller_extras" :heading="__('Top selling extras')" class="mb-6">
            <ReportsItemsTable
                :items="report.top_seller_extras"
                :table-columns="'other'"
                :currency="currency"
            />
        </Panel>

        <Panel v-if="report.affiliate_sales" :heading="__('Affiliates')" class="mb-6">
            <ReportsItemsTable
                :items="report.affiliate_sales"
                :table-columns="'affiliates'"
                :currency="currency"
            />
        </Panel>

        <Panel v-if="report.dynamic_pricing_applications" :heading="__('Dynamic pricing')" class="mb-6">
            <ReportsItemsTable
                :items="report.dynamic_pricing_applications"
                :table-columns="'dynamic'"
                :currency="currency"
            />
        </Panel>

        <Panel v-if="report.top_seller_starting_locations" :heading="__('Top starting locations')" class="mb-6">
            <ReportsItemsTable
                :items="report.top_seller_starting_locations"
                :table-columns="'other'"
                :currency="currency"
            />
        </Panel>
    </div>
</template>

<script setup>
import { Card, DateRangePicker, Field, Header, Panel, Select } from '@statamic/cms/ui';
import { router } from '@statamic/cms/inertia';
import { computed, ref, watch } from 'vue';
import ReportsItemsTable from './ReportsItemsTable.vue';
import { useDateRangeModel } from '../composables/useDateRangeModel.js';

const props = defineProps({
    report: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    currency: { type: String, default: '' },
    reservationsUrl: { type: String, default: null },
});

const dateStart = ref(props.filters.start ?? '');
const dateEnd = ref(props.filters.end ?? '');
const dateField = ref(props.filters.dateField ?? 'date_start');

const dateRange = useDateRangeModel(
    () => dateStart.value,
    () => dateEnd.value,
    (v) => (dateStart.value = v ?? ''),
    (v) => (dateEnd.value = v ?? ''),
);

const outstandingCount = computed(() => Number(props.report.outstanding?.count ?? 0));

// Opens the Index pre-filtered like the tile: the URL filters replace the default status filter.
// range_value carries both the v6 shape and the nested `date` shape FiltersByDate reads.
const outstandingUrl = computed(() => {
    const base = props.reservationsUrl ?? cp_url('resrv/reservations');
    const range = { start: props.filters.start ?? dateStart.value, end: props.filters.end ?? dateEnd.value };
    const filters = {
        reservation_status: { status: ['awaiting_payment'] },
        [props.filters.dateField ?? dateField.value]: { operator: 'between', range_value: { ...range, date: range } },
    };
    return `${base}?filters=${encodeURIComponent(utf8btoa(JSON.stringify(filters)))}`;
});

// Same encoding as the Listing's utf8atob() decoder.
function utf8btoa(value) {
    return btoa(encodeURIComponent(value).replace(/%([0-9A-F]{2})/g, (_, code) => String.fromCharCode(parseInt(code, 16))));
}

const dateFieldOptions = [
    { value: 'date_start', label: __('Reservation date') },
    { value: 'created_at', label: __('Date created') },
];

watch([dateStart, dateEnd, dateField], () => {
    if (!dateStart.value || !dateEnd.value) {
        return;
    }
    router.reload({
        only: ['report', 'filters'],
        data: {
            start: dateStart.value,
            end: dateEnd.value,
            date_field: dateField.value,
        },
        preserveState: true,
        preserveScroll: true,
    });
});
</script>
