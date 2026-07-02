<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import axios from 'axios';
import { Head } from '@statamic/cms/inertia';
import { Badge, Button, Card, DateRangePicker, Field, Header, Input, Select } from '@statamic/cms/ui';
import { useToast } from '../../composables/useToast.js';
import { useDateRangeModel } from '../../composables/useDateRangeModel.js';

const props = defineProps({
    enabled: { type: Boolean, default: false },
    availabilityUrl: { type: String, required: true },
    reservationsUrl: { type: String, required: true },
    entriesUrl: { type: String, required: true },
    availabilityReasons: { type: Array, default: () => [] },
    reservationReasons: { type: Array, default: () => [] },
});

const toast = useToast();

const tab = ref('availability');
const loading = ref(false);
const rows = ref([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const page = ref(1);

const entries = ref([]);
const expandedBatches = ref(new Set());

const filters = ref({
    statamic_id: null,
    reason: null,
    reference: '',
    date_start: null,
    date_end: null,
});

const dateRange = useDateRangeModel(
    () => filters.value.date_start,
    () => filters.value.date_end,
    (value) => (filters.value.date_start = value),
    (value) => (filters.value.date_end = value),
);

const entryOptions = computed(() =>
    entries.value.map((entry) => ({ value: entry.item_id, label: entry.title })),
);

const reasonOptions = computed(() =>
    tab.value === 'availability' ? props.availabilityReasons : props.reservationReasons,
);

const dateFilterLabel = computed(() =>
    tab.value === 'availability' ? __('Availability date') : __('Logged between'),
);

const availabilityGroups = computed(() => {
    const groups = new Map();
    rows.value.forEach((row) => {
        if (! groups.has(row.batch)) {
            groups.set(row.batch, []);
        }
        groups.get(row.batch).push(row);
    });

    return Array.from(groups.entries()).map(([batch, batchRows]) => ({
        batch,
        rows: batchRows,
        first: batchRows[0],
    }));
});

const load = async () => {
    loading.value = true;

    const params = {
        page: page.value,
        ...(filters.value.reason ? { reason: filters.value.reason } : {}),
        ...(filters.value.date_start ? { date_start: filters.value.date_start } : {}),
        ...(filters.value.date_end ? { date_end: filters.value.date_end } : {}),
    };

    if (tab.value === 'availability' && filters.value.statamic_id) {
        params.statamic_id = filters.value.statamic_id;
    }
    if (tab.value === 'reservations' && filters.value.reference) {
        params.reference = filters.value.reference;
    }

    try {
        const url = tab.value === 'availability' ? props.availabilityUrl : props.reservationsUrl;
        const response = await axios.get(url, { params });
        rows.value = response.data.data;
        meta.value = {
            current_page: response.data.current_page,
            last_page: response.data.last_page,
            total: response.data.total,
        };
    } catch (error) {
        toast.error(error?.response?.data?.message ?? __('Something went wrong'));
    } finally {
        loading.value = false;
    }
};

const loadEntries = async () => {
    try {
        const response = await axios.get(props.entriesUrl);
        entries.value = response.data;
    } catch (error) {
        entries.value = [];
    }
};

const switchTab = (newTab) => {
    if (tab.value === newTab) return;
    tab.value = newTab;
    filters.value.reason = null;
    page.value = 1;
    load();
};

const toggleBatch = (batch) => {
    const expanded = new Set(expandedBatches.value);
    if (expanded.has(batch)) {
        expanded.delete(batch);
    } else {
        expanded.add(batch);
    }
    expandedBatches.value = expanded;
};

const previousPage = () => {
    if (meta.value.current_page > 1) {
        page.value = meta.value.current_page - 1;
        load();
    }
};

const nextPage = () => {
    if (meta.value.current_page < meta.value.last_page) {
        page.value = meta.value.current_page + 1;
        load();
    }
};

const formatValue = (value) => {
    if (value === null || value === undefined) {
        return '—';
    }
    return Number(value) % 1 === 0 ? String(Number(value)) : String(value);
};

const contextSummary = (context) => {
    if (! context) return '';
    return Object.entries(context)
        .map(([key, value]) => `${key}: ${value}`)
        .join(', ');
};

const actionBadgeColor = (action) => {
    const map = { create: 'green', update: 'blue', delete: 'red' };
    return map[action] ?? 'default';
};

const statusBadgeColor = (status) => {
    const map = {
        confirmed: 'green',
        partner: 'green',
        pending: 'blue',
        refunded: 'yellow',
        expired: 'red',
    };
    return map[status] ?? 'default';
};

watch(
    () => [filters.value.statamic_id, filters.value.reason, filters.value.date_start, filters.value.date_end],
    () => {
        page.value = 1;
        load();
    },
);

let referenceTimeout = null;
watch(
    () => filters.value.reference,
    () => {
        clearTimeout(referenceTimeout);
        referenceTimeout = setTimeout(() => {
            page.value = 1;
            load();
        }, 400);
    },
);

onMounted(() => {
    load();
    loadEntries();
});
</script>

<template>
    <div class="max-w-page mx-auto">
        <Head :title="__('Activity Log')" />

        <Header :title="__('Activity Log')" icon="history" />

        <Card v-if="! enabled" class="mb-6">
            <p class="text-sm text-gray-700 dark:text-gray-300">
                {{ __('Activity logging is disabled — new changes are not being recorded. Enable it in the Resrv settings.') }}
            </p>
        </Card>

        <div class="flex items-center gap-2 mb-4">
            <Button
                :text="__('Availability')"
                :variant="tab === 'availability' ? 'primary' : 'default'"
                @click="switchTab('availability')"
            />
            <Button
                :text="__('Reservations')"
                :variant="tab === 'reservations' ? 'primary' : 'default'"
                @click="switchTab('reservations')"
            />
        </div>

        <Card class="mb-6">
            <div class="flex flex-wrap items-end gap-4">
                <div v-if="tab === 'availability'" class="min-w-[220px]">
                    <Field :label="__('Entry')">
                        <Select v-model="filters.statamic_id" :options="entryOptions" clearable />
                    </Field>
                </div>
                <div v-if="tab === 'reservations'" class="min-w-[220px]">
                    <Field :label="__('Reference')">
                        <Input v-model="filters.reference" :placeholder="__('Search by reference')" />
                    </Field>
                </div>
                <div class="min-w-[220px]">
                    <Field :label="__('Reason')">
                        <Select v-model="filters.reason" :options="reasonOptions" clearable />
                    </Field>
                </div>
                <div class="min-w-[320px]">
                    <Field :label="dateFilterLabel">
                        <DateRangePicker v-model="dateRange" granularity="day" />
                    </Field>
                </div>
            </div>
        </Card>

        <Card inset class="overflow-x-auto">
            <table v-if="tab === 'availability'" class="w-full text-sm rounded-xl overflow-hidden">
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr class="border-b border-gray-200 dark:border-gray-700/80">
                        <th class="text-left px-4 py-3 font-medium text-xs uppercase tracking-wide text-gray-600 dark:text-gray-300">{{ __('Change') }}</th>
                        <th class="text-left px-4 py-3 font-medium text-xs uppercase tracking-wide text-gray-600 dark:text-gray-300">{{ __('Entry') }}</th>
                        <th class="text-left px-4 py-3 font-medium text-xs uppercase tracking-wide text-gray-600 dark:text-gray-300">{{ __('Actor') }}</th>
                        <th class="text-left px-4 py-3 font-medium text-xs uppercase tracking-wide text-gray-600 dark:text-gray-300">{{ __('Logged at') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="! loading && rows.length === 0">
                        <td colspan="4" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">
                            {{ __('No activity recorded') }}
                        </td>
                    </tr>
                    <template v-for="group in availabilityGroups" :key="group.batch">
                        <tr
                            class="border-b border-gray-200 dark:border-gray-700/80 bg-gray-50/50 dark:bg-gray-800/50 cursor-pointer select-none"
                            @click="toggleBatch(group.batch)"
                        >
                            <td class="px-4 py-3 text-gray-900 dark:text-gray-200">
                                <span aria-hidden="true" class="inline-block w-4 text-gray-400">
                                    {{ expandedBatches.has(group.batch) ? '▾' : '▸' }}
                                </span>
                                <span class="font-medium">{{ group.first.reason_label }}</span>
                                <span class="text-gray-500 dark:text-gray-400 ml-2">
                                    {{ group.rows.length }} {{ group.rows.length === 1 ? __('change') : __('changes') }}
                                </span>
                                <span v-if="group.first.reservation_id" class="text-gray-500 dark:text-gray-400 ml-2">
                                    {{ __('Reservation') }} #{{ group.first.reservation_id }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-gray-900 dark:text-gray-200">{{ group.first.entry_title }}</td>
                            <td class="px-4 py-3 text-gray-900 dark:text-gray-200">{{ group.first.actor_name ?? '—' }}</td>
                            <td class="px-4 py-3 text-gray-900 dark:text-gray-200">{{ group.first.created_at }}</td>
                        </tr>
                        <tr
                            v-for="row in (expandedBatches.has(group.batch) ? group.rows : [])"
                            :key="row.id"
                            class="border-b border-gray-100 dark:border-gray-700/40"
                        >
                            <td colspan="4" class="px-4 py-2 ps-12">
                                <div class="flex flex-wrap items-center gap-3 text-gray-700 dark:text-gray-300">
                                    <span class="font-mono">{{ row.date }}</span>
                                    <Badge :color="actionBadgeColor(row.action)" size="sm">{{ row.action }}</Badge>
                                    <span>{{ row.field }}</span>
                                    <span class="font-mono">{{ formatValue(row.old_value) }} → {{ formatValue(row.new_value) }}</span>
                                    <span v-if="row.rate_title" class="text-gray-500 dark:text-gray-400">{{ row.rate_title }}</span>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>

            <table v-else class="w-full text-sm rounded-xl overflow-hidden">
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr class="border-b border-gray-200 dark:border-gray-700/80">
                        <th class="text-left px-4 py-3 font-medium text-xs uppercase tracking-wide text-gray-600 dark:text-gray-300">{{ __('Reference') }}</th>
                        <th class="text-left px-4 py-3 font-medium text-xs uppercase tracking-wide text-gray-600 dark:text-gray-300">{{ __('Status') }}</th>
                        <th class="text-left px-4 py-3 font-medium text-xs uppercase tracking-wide text-gray-600 dark:text-gray-300">{{ __('Reason') }}</th>
                        <th class="text-left px-4 py-3 font-medium text-xs uppercase tracking-wide text-gray-600 dark:text-gray-300">{{ __('Details') }}</th>
                        <th class="text-left px-4 py-3 font-medium text-xs uppercase tracking-wide text-gray-600 dark:text-gray-300">{{ __('Actor') }}</th>
                        <th class="text-left px-4 py-3 font-medium text-xs uppercase tracking-wide text-gray-600 dark:text-gray-300">{{ __('Logged at') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="! loading && rows.length === 0">
                        <td colspan="6" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">
                            {{ __('No activity recorded') }}
                        </td>
                    </tr>
                    <tr
                        v-for="row in rows"
                        :key="row.id"
                        class="border-b last:border-b-0 border-gray-200 dark:border-gray-700/80"
                    >
                        <td class="px-4 py-3 text-gray-900 dark:text-gray-200 font-mono">{{ row.reference }}</td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center gap-1">
                                <Badge v-if="row.status_from && row.status_from !== row.status_to" :color="statusBadgeColor(row.status_from)" size="sm">{{ row.status_from }}</Badge>
                                <span v-if="row.status_from && row.status_from !== row.status_to" aria-hidden="true" class="text-gray-400">→</span>
                                <Badge :color="statusBadgeColor(row.status_to)" size="sm">{{ row.status_to }}</Badge>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-gray-900 dark:text-gray-200">{{ row.reason_label }}</td>
                        <td class="px-4 py-3 text-gray-500 dark:text-gray-400 text-xs">{{ contextSummary(row.context) }}</td>
                        <td class="px-4 py-3 text-gray-900 dark:text-gray-200">{{ row.actor_name ?? '—' }}</td>
                        <td class="px-4 py-3 text-gray-900 dark:text-gray-200">{{ row.created_at }}</td>
                    </tr>
                </tbody>
            </table>
        </Card>

        <div class="flex items-center justify-between mt-4">
            <span class="text-sm text-gray-500 dark:text-gray-400">
                {{ meta.total }} {{ meta.total === 1 ? __('entry') : __('entries') }}
            </span>
            <div class="flex items-center gap-2">
                <Button
                    :text="__('Previous')"
                    size="sm"
                    :disabled="loading || meta.current_page <= 1"
                    @click="previousPage"
                />
                <span class="text-sm text-gray-500 dark:text-gray-400">
                    {{ meta.current_page }} / {{ meta.last_page }}
                </span>
                <Button
                    :text="__('Next')"
                    size="sm"
                    :disabled="loading || meta.current_page >= meta.last_page"
                    @click="nextPage"
                />
            </div>
        </div>
    </div>
</template>
