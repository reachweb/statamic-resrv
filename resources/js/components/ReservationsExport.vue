<template>
    <div>
        <Header :title="__('Export Reservations')" icon="arrow-down" />
        <Card>
            <div class="space-y-6">
                <Field :label="__('Reservation date range')">
                    <div class="max-w-md">
                        <DateRangePicker v-model="dateRange" granularity="day" />
                    </div>
                </Field>

                <Field :label="__('Status')">
                    <CheckboxGroup v-model="selectedStatuses" inline>
                        <Checkbox
                            v-for="status in statuses"
                            :key="status"
                            :value="status"
                        >
                            <span class="capitalize">{{ status }}</span>
                        </Checkbox>
                    </CheckboxGroup>
                    <Checkbox
                        v-model="withCustomerData"
                        :label="__('Only include reservations with customer details')"
                        class="mt-3"
                    />
                </Field>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-4 gap-y-6">
                    <Field :label="__('Item')">
                        <Combobox
                            v-model="selectedEntry"
                            :options="entries"
                            option-label="title"
                            option-value="item_id"
                            :clearable="true"
                            :searchable="true"
                            :placeholder="__('All items')"
                        />
                    </Field>
                    <Field v-if="affiliates.length > 0" :label="__('Affiliate')">
                        <Combobox
                            v-model="selectedAffiliate"
                            :options="affiliates"
                            option-label="name"
                            option-value="id"
                            :clearable="true"
                            :searchable="true"
                            :placeholder="__('All affiliates')"
                        />
                    </Field>
                    <Field v-if="affiliates.length > 0" :label="__('Commission status')">
                        <Combobox
                            v-model="selectedCommissionStatus"
                            :options="[
                                { label: __('All'), value: 'all' },
                                { label: __('Active'), value: 'active' },
                                { label: __('Cancelled'), value: 'cancelled' },
                            ]"
                            option-label="label"
                            option-value="value"
                            :placeholder="__('All commissions')"
                        />
                    </Field>
                </div>

                <Field :label="__('Fields to export')">
                    <CheckboxGroup v-model="selectedFields">
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            <div v-for="(group, groupName) in fieldsByGroup" :key="groupName" class="space-y-1">
                                <div class="flex items-center gap-2 mb-2">
                                    <label class="text-xs uppercase tracking-wide font-semibold text-gray-500 dark:text-gray-400">{{ groupName }}</label>
                                    <Button
                                        size="xs"
                                        variant="ghost"
                                        :text="allGroupSelected(groupName) ? __('None') : __('All')"
                                        @click="toggleGroup(groupName)"
                                    />
                                </div>
                                <Checkbox
                                    v-for="field in group"
                                    :key="field.key"
                                    :value="field.key"
                                    :label="field.label"
                                />
                            </div>
                        </div>
                    </CheckboxGroup>
                </Field>

                <div class="flex flex-wrap items-center gap-4 pt-2 border-t border-gray-200 dark:border-gray-700/80">
                    <div class="text-sm text-gray-700 dark:text-gray-300 flex-1">
                        <template v-if="countLoading">{{ __('Counting…') }}</template>
                        <template v-else-if="countError">{{ __('Could not count reservations') }}</template>
                        <template v-else>
                            <strong class="text-gray-900 dark:text-gray-100">{{ count }}</strong> {{ __('reservations match') }}
                        </template>
                    </div>
                    <Button
                        :text="__('Download CSV')"
                        variant="primary"
                        icon="arrow-down"
                        :disabled="!canDownload"
                        @click="download"
                    />
                </div>
            </div>
        </Card>
    </div>
</template>

<script setup>
import { Button, Card, Checkbox, CheckboxGroup, Combobox, DateRangePicker, Field, Header } from '@statamic/cms/ui';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import axios from 'axios';
import dayjs from 'dayjs';
import { useDateRangeModel } from '../composables/useDateRangeModel.js';
import { useToast } from '../composables/useToast.js';

const STORAGE_KEY = 'resrv-export-selected-fields';

const props = defineProps({
    countUrl: { type: String, required: true },
    downloadUrl: { type: String, required: true },
    fields: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    entries: { type: Array, default: () => [] },
    affiliates: { type: Array, default: () => [] },
});

const toast = useToast();

const dateStart = ref(dayjs().subtract(30, 'days').format('YYYY-MM-DD'));
const dateEnd = ref(dayjs().format('YYYY-MM-DD'));
const dateRange = useDateRangeModel(
    () => dateStart.value,
    () => dateEnd.value,
    (v) => (dateStart.value = v ?? ''),
    (v) => (dateEnd.value = v ?? ''),
);
const selectedStatuses = ref(props.statuses.filter((s) => ['confirmed', 'partner'].includes(s)));
const selectedEntry = ref(null);
const selectedAffiliate = ref(null);
const selectedCommissionStatus = ref('all');
const withCustomerData = ref(false);
const selectedFields = ref(loadSelectedFields());
const count = ref(0);
const countLoading = ref(false);
const countError = ref(false);
const downloading = ref(false);
let countDebounce = null;

const fieldsByGroup = computed(() =>
    props.fields.reduce((groups, field) => {
        (groups[field.group] = groups[field.group] || []).push(field);
        return groups;
    }, {}),
);

const canDownload = computed(() =>
    !countLoading.value
    && !downloading.value
    && count.value > 0
    && dateStart.value !== ''
    && dateEnd.value !== ''
    && selectedFields.value.length > 0
    && selectedStatuses.value.length > 0,
);

watch([dateStart, dateEnd, selectedStatuses, selectedEntry, selectedAffiliate, selectedCommissionStatus, withCustomerData], () => scheduleCount());

watch(selectedFields, (value) => {
    try {
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(value));
    } catch (e) {
        /* ignore */
    }
}, { deep: true });

onMounted(() => fetchCount());
onBeforeUnmount(() => clearTimeout(countDebounce));

function loadSelectedFields() {
    try {
        const stored = window.localStorage.getItem(STORAGE_KEY);
        if (stored) {
            const parsed = JSON.parse(stored);
            if (Array.isArray(parsed)) {
                const valid = new Set(props.fields.map((f) => f.key));
                return parsed.filter((k) => valid.has(k));
            }
        }
    } catch (e) {
        console.warn('Failed to load saved export fields:', e);
    }
    return props.fields.filter((f) => f.default).map((f) => f.key);
}

function scheduleCount() {
    clearTimeout(countDebounce);
    countDebounce = setTimeout(() => fetchCount(), 250);
}

function fetchCount() {
    if (selectedStatuses.value.length === 0 || !dateStart.value || !dateEnd.value) {
        count.value = 0;
        countLoading.value = false;
        countError.value = false;
        return;
    }
    countLoading.value = true;
    countError.value = false;
    axios.get(props.countUrl, { params: buildBody() })
        .then((response) => {
            count.value = response.data.count;
            countLoading.value = false;
        })
        .catch(() => {
            countLoading.value = false;
            countError.value = true;
            toast.error(__('Cannot retrieve reservation count'));
        });
}

function download() {
    if (!canDownload.value) return;
    downloading.value = true;
    axios.post(props.downloadUrl, { ...buildBody(), fields: selectedFields.value }, { responseType: 'blob' })
        .then((response) => {
            const filename = response.headers['content-disposition']?.match(/filename="?([^";]+)"?/)?.[1];
            const url = URL.createObjectURL(response.data);
            const link = document.createElement('a');
            link.href = url;
            link.download = filename ?? 'reservations.csv';
            link.click();
            URL.revokeObjectURL(url);
        })
        .catch(() => toast.error(__('Could not download the export')))
        .finally(() => (downloading.value = false));
}

function buildBody() {
    const body = {
        start: dateStart.value,
        end: dateEnd.value,
        statuses: selectedStatuses.value,
    };
    if (selectedEntry.value) body.item_id = selectedEntry.value;
    if (selectedAffiliate.value) body.affiliate_id = selectedAffiliate.value;
    if (selectedCommissionStatus.value && selectedCommissionStatus.value !== 'all') body.commission_status = selectedCommissionStatus.value;
    if (withCustomerData.value) body.with_customer_data = 1;
    return body;
}

function allGroupSelected(groupName) {
    const groupKeys = fieldsByGroup.value[groupName].map((f) => f.key);
    return groupKeys.every((k) => selectedFields.value.includes(k));
}

function toggleGroup(groupName) {
    const groupKeys = fieldsByGroup.value[groupName].map((f) => f.key);
    if (allGroupSelected(groupName)) {
        selectedFields.value = selectedFields.value.filter((k) => !groupKeys.includes(k));
    } else {
        const set = new Set(selectedFields.value);
        groupKeys.forEach((k) => set.add(k));
        selectedFields.value = [...set];
    }
}
</script>
