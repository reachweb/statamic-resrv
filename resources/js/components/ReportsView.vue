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

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <Card class="text-center">
                <div class="text-sm text-gray-600 dark:text-gray-400 mb-2">{{ __('Reservations') }}</div>
                <div class="text-3xl font-semibold text-gray-900 dark:text-gray-100">{{ reportData.total_confirmed_reservations ?? 0 }}</div>
            </Card>
            <Card class="text-center">
                <div class="text-sm text-gray-600 dark:text-gray-400 mb-2">{{ __('Revenue') }}</div>
                <div class="text-3xl font-semibold text-gray-900 dark:text-gray-100">{{ currency }} {{ reportData.total_revenue ?? 0 }}</div>
            </Card>
            <Card class="text-center">
                <div class="text-sm text-gray-600 dark:text-gray-400 mb-2">{{ __('Average reservation value') }}</div>
                <div class="text-3xl font-semibold text-gray-900 dark:text-gray-100">{{ currency }} {{ reportData.avg_revenue ?? 0 }}</div>
            </Card>
        </div>

        <Panel :heading="__('Best sellers')" class="mb-6">
            <ReportsItemsTable
                v-if="reportDataLoaded"
                :items="reportData.top_seller_items"
                :table-columns="'items'"
                :currency="currency"
            />
        </Panel>

        <Panel v-if="reportDataLoaded && reportData.top_seller_extras" :heading="__('Top selling extras')" class="mb-6">
            <ReportsItemsTable
                :items="reportData.top_seller_extras"
                :table-columns="'other'"
                :currency="currency"
            />
        </Panel>

        <Panel v-if="reportDataLoaded && reportData.top_seller_starting_locations" :heading="__('Top starting locations')" class="mb-6">
            <ReportsItemsTable
                :items="reportData.top_seller_starting_locations"
                :table-columns="'other'"
                :currency="currency"
            />
        </Panel>
    </div>
</template>

<script setup>
import { Card, DateRangePicker, Field, Header, Panel, Select } from '@statamic/cms/ui';
import { onMounted, ref, watch } from 'vue';
import axios from 'axios';
import dayjs from 'dayjs';
import ReportsItemsTable from './ReportsItemsTable.vue';
import { useDateRangeModel } from '../composables/useDateRangeModel.js';
import { useToast } from '../composables/useToast.js';

const props = defineProps({
    reportsUrl: { type: String, default: '' },
    currency: { type: String, default: '' },
});

const toast = useToast();

const reportData = ref({});
const reportDataLoaded = ref(false);
const dateStart = ref(dayjs().subtract(7, 'days').format('YYYY-MM-DD'));
const dateEnd = ref(dayjs().format('YYYY-MM-DD'));
const dateRange = useDateRangeModel(
    () => dateStart.value,
    () => dateEnd.value,
    (v) => (dateStart.value = v ?? ''),
    (v) => (dateEnd.value = v ?? ''),
);
const dateField = ref('date_start');
const dateFieldOptions = [
    { value: 'date_start', label: __('Reservation date') },
    { value: 'created_at', label: __('Date created') },
];

watch([dateStart, dateEnd, dateField], () => getReports());

onMounted(() => getReports());

function getReports() {
    if (!dateStart.value || !dateEnd.value) {
        return;
    }
    axios.get(`${props.reportsUrl}?start=${dateStart.value}&end=${dateEnd.value}&date_field=${dateField.value}`)
        .then((response) => {
            reportData.value = response.data;
            reportDataLoaded.value = true;
        })
        .catch(() => {
            toast.error('Cannot retrieve report data');
        });
}
</script>
