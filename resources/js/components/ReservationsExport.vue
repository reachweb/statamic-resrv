<template>
    <div>
        <Header :title="__('Export Reservations')" icon="arrow-down" />
        <Card class="space-y-6">
            <Field :label="__('Reservation date range')">
                <div class="grid grid-cols-2 gap-2 max-w-md">
                    <Input v-model="dateStart" type="date" :placeholder="__('Start date')" />
                    <Input v-model="dateEnd" type="date" :placeholder="__('End date')" />
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

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
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
        </Card>
    </div>
</template>

<script setup>
import { Button, Card, Checkbox, CheckboxGroup, Combobox, Field, Header, Input } from '@statamic/cms/ui';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import axios from 'axios';
import dayjs from 'dayjs';
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
const selectedStatuses = ref(props.statuses.filter((s) => ['confirmed', 'partner'].includes(s)));
const selectedEntry = ref(null);
const selectedAffiliate = ref(null);
const withCustomerData = ref(false);
const selectedFields = ref(loadSelectedFields());
const count = ref(0);
const countLoading = ref(false);
const countError = ref(false);
let countDebounce = null;

const fieldsByGroup = computed(() =>
    props.fields.reduce((groups, field) => {
        (groups[field.group] = groups[field.group] || []).push(field);
        return groups;
    }, {}),
);

const canDownload = computed(() =>
    !countLoading.value
    && count.value > 0
    && selectedFields.value.length > 0
    && selectedStatuses.value.length > 0,
);

watch([dateStart, dateEnd, selectedStatuses, selectedEntry, selectedAffiliate, withCustomerData], () => scheduleCount());

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
    if (selectedStatuses.value.length === 0) {
        count.value = 0;
        countLoading.value = false;
        countError.value = false;
        return;
    }
    countLoading.value = true;
    countError.value = false;
    axios.get(props.countUrl + '?' + buildParams().toString())
        .then((response) => {
            count.value = response.data.count;
            countLoading.value = false;
        })
        .catch(() => {
            countLoading.value = false;
            countError.value = true;
            toast.error('Cannot retrieve reservation count');
        });
}

function download() {
    if (!canDownload.value) return;
    const params = buildParams();
    selectedFields.value.forEach((f) => params.append('fields[]', f));
    window.location = props.downloadUrl + '?' + params.toString();
}

function buildParams() {
    const params = new URLSearchParams();
    params.append('start', dateStart.value);
    params.append('end', dateEnd.value);
    selectedStatuses.value.forEach((s) => params.append('statuses[]', s));
    if (selectedEntry.value) params.append('item_id', selectedEntry.value);
    if (selectedAffiliate.value) params.append('affiliate_id', selectedAffiliate.value);
    if (withCustomerData.value) params.append('with_customer_data', '1');
    return params;
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
