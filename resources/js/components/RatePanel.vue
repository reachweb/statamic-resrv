<template>
    <Stack
        :open="true"
        :title="isEditing ? __('Edit rate') : __('Add rate')"
        icon="money-cashier-price-tag"
        @closed="onClosed"
    >
        <template #header-actions>
            <Button :text="__('Save')" variant="primary" :disabled="disableSave" @click="save" />
        </template>
        <template #default>
            <Panel :heading="__('General')">
                <Card>
                    <div class="space-y-6">
                        <Field :label="__('Collection')" :instructions="__('The collection this rate applies to.')" :errors="errors.collection">
                            <Select
                                v-model="submit.collection"
                                :options="collectionOptions"
                                :clearable="false"
                                :disabled="isEditing"
                            />
                        </Field>
                        <Field :label="__('Apply to all entries in collection')">
                            <Switch v-model="submit.apply_to_all" />
                        </Field>
                        <Field v-if="!submit.apply_to_all" :label="__('Entries')" :instructions="__('Select the entries this rate should apply to.')" :errors="errors.entries">
                            <EntriesStackPicker
                                v-if="entriesLoaded"
                                v-model="submit.entries"
                                :options="collectionEntries"
                                option-label="title"
                                option-value="id"
                                :stack-title="__('Select entries')"
                            >
                                <template #actions>
                                    <Button size="xs" variant="ghost" :text="__('Select all')" @click="selectAllEntries" />
                                    <span class="text-xs text-gray-400">|</span>
                                    <Button size="xs" variant="ghost" :text="__('Remove all')" @click="removeAllEntries" />
                                </template>
                            </EntriesStackPicker>
                        </Field>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-x-4 gap-y-6">
                            <Field :label="__('Title')" :errors="errors.title">
                                <Input v-model="submit.title" @input="slugify" />
                            </Field>
                            <Field :label="__('Slug')" :errors="errors.slug">
                                <Input v-model="submit.slug" @input="onSlugInput" />
                            </Field>
                        </div>
                        <Field :label="__('Description')" :errors="errors.description">
                            <Textarea v-model="submit.description" />
                        </Field>
                    </div>
                </Card>
            </Panel>

            <Panel :heading="__('Pricing')">
                <Card>
                    <div class="space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-x-4 gap-y-6">
                            <Field :label="__('Pricing type')" :instructions="__('Independent rates have their own pricing. Relative rates derive pricing from a base rate.')" :errors="errors.pricing_type">
                                <Select v-model="submit.pricing_type" :options="pricingTypes" />
                            </Field>
                            <Field v-if="needsBaseRate" :label="__('Base rate')" :instructions="baseRateDescription" :errors="errors.base_rate_id">
                                <Select v-model="submit.base_rate_id" :options="availableBaseRates" />
                            </Field>
                        </div>
                        <div v-if="submit.pricing_type === 'relative'" class="grid grid-cols-1 md:grid-cols-3 gap-x-4 gap-y-6">
                            <Field :label="__('Modifier type')" :instructions="__('Percentage or fixed amount.')" :errors="errors.modifier_type">
                                <Select v-model="submit.modifier_type" :options="modifierTypes" />
                            </Field>
                            <Field :label="__('Modifier operation')" :instructions="__('Increase or decrease from base rate.')" :errors="errors.modifier_operation">
                                <Select v-model="submit.modifier_operation" :options="modifierOperations" />
                            </Field>
                            <Field :label="__('Modifier amount')" :instructions="__('Amount or percentage without the % character.')" :errors="errors.modifier_amount">
                                <Input v-model="submit.modifier_amount" />
                            </Field>
                        </div>
                    </div>
                </Card>
            </Panel>

            <Panel :heading="__('Availability')">
                <Card>
                    <div class="space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-x-4 gap-y-6">
                            <Field :label="__('Availability type')" :instructions="__('Independent rates have their own inventory. Shared rates share inventory with the base rate.')" :errors="errors.availability_type">
                                <Select v-model="submit.availability_type" :options="availabilityTypes" />
                            </Field>
                            <Field v-if="submit.availability_type === 'shared'" :label="__('Max available')" :instructions="__('Maximum number of units available for this rate.')" :errors="errors.max_available">
                                <Input v-model="submit.max_available" type="number" />
                            </Field>
                        </div>
                        <Field v-if="submit.availability_type === 'shared' && submit.pricing_type === 'independent'" :label="__('Require price override')" :instructions="__('When enabled, dates without an explicit price for this rate are unavailable. When disabled, the base rate\'s price is used as a fallback.')">
                            <Switch v-model="submit.require_price_override" />
                        </Field>
                    </div>
                </Card>
            </Panel>

            <Panel :heading="__('Restrictions')">
                <Card>
                    <div class="space-y-6">
                        <Field :label="__('Date range')" :instructions="__('Rate is available within this date range.')" :errors="dateRangeErrors">
                            <DateRangePicker v-model="dateRange" granularity="day" />
                        </Field>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-x-4 gap-y-6">
                            <Field :label="__('Min days before')" :instructions="__('Minimum advance booking days.')" :errors="errors.min_days_before">
                                <Input v-model="submit.min_days_before" type="number" />
                            </Field>
                            <Field :label="__('Max days before')" :instructions="__('Maximum advance booking days.')" :errors="errors.max_days_before">
                                <Input v-model="submit.max_days_before" type="number" />
                            </Field>
                            <Field :label="__('Min stay')" :instructions="__('Minimum number of nights.')" :errors="errors.min_stay">
                                <Input v-model="submit.min_stay" type="number" />
                            </Field>
                            <Field :label="__('Max stay')" :instructions="__('Maximum number of nights.')" :errors="errors.max_stay">
                                <Input v-model="submit.max_stay" type="number" />
                            </Field>
                        </div>
                    </div>
                </Card>
            </Panel>

            <Panel :heading="__('Settings')">
                <Card>
                    <div class="space-y-6">
                        <Field :label="__('Refundable')">
                            <Switch v-model="submit.refundable" />
                        </Field>
                        <Field :label="__('Published')">
                            <Switch v-model="submit.published" />
                        </Field>
                    </div>
                </Card>
            </Panel>
        </template>
    </Stack>
</template>

<script setup>
import { Button, Card, DateRangePicker, Field, Input, Panel, Select, Stack, Switch, Textarea } from '@statamic/cms/ui';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import axios from 'axios';
import EntriesStackPicker from './EntriesStackPicker.vue';
import { useDateRangeModel } from '../composables/useDateRangeModel.js';
import { useFormHandler } from '../composables/useFormHandler.js';
import { useSlugify } from '../composables/useSlugify.js';
import { useToast } from '../composables/useToast.js';

const props = defineProps({
    data: { type: Object, required: true },
    allRates: { type: Array, default: () => [] },
    collections: { type: Array, default: () => [] },
    selectedCollection: { type: String, default: null },
});

const emit = defineEmits(['closed', 'saved']);
const toast = useToast();
const { slugifyFrom, onSlugInput, reset: resetSlugify } = useSlugify();

const submit = reactive({ entries: [] });
const collectionEntries = ref([]);
const entriesLoaded = ref(false);

const dateRange = useDateRangeModel(
    () => submit.date_start,
    () => submit.date_end,
    (v) => (submit.date_start = v),
    (v) => (submit.date_end = v),
);

const pricingTypes = [
    { value: 'independent', label: 'Independent' },
    { value: 'relative', label: 'Relative' },
];
const modifierTypes = [
    { value: 'percent', label: 'Percent' },
    { value: 'fixed', label: 'Fixed' },
];
const modifierOperations = [
    { value: 'increase', label: 'Increase' },
    { value: 'decrease', label: 'Decrease' },
];
const availabilityTypes = [
    { value: 'independent', label: 'Independent' },
    { value: 'shared', label: 'Shared' },
];

const collectionOptions = computed(() =>
    props.collections.map((c) => ({ value: c.handle, label: c.title })),
);

const isEditing = computed(() => 'id' in props.data);
const method = computed(() => (isEditing.value ? 'patch' : 'post'));
const postUrl = computed(() =>
    isEditing.value ? '/cp/resrv/rate/' + props.data.id : '/cp/resrv/rate',
);

const availableBaseRates = computed(() =>
    props.allRates
        .filter((rate) => rate.id !== props.data.id)
        .map((rate) => ({ value: rate.id, label: rate.title })),
);

const needsBaseRate = computed(() =>
    submit.pricing_type === 'relative' || submit.availability_type === 'shared',
);

const baseRateDescription = computed(() => {
    if (submit.pricing_type === 'relative' && submit.availability_type === 'shared') {
        return __('Derive pricing and share inventory with this rate.');
    }
    if (submit.pricing_type === 'relative') {
        return __('The rate to derive pricing from.');
    }
    return __('The rate to share inventory with.');
});

const { disableSave, errors, save } = useFormHandler({
    submit,
    postUrl,
    method,
    successMessage: 'Rate successfully saved',
    emit,
});

const dateRangeErrors = computed(() => {
    const out = [];
    if (errors.value?.date_start) out.push(...errors.value.date_start);
    if (errors.value?.date_end) out.push(...errors.value.date_end);
    return out.length ? out : null;
});

watch(() => props.data, () => createSubmit(), { deep: true });

watch(() => submit.collection, (newVal) => {
    if (newVal) {
        getCollectionEntries(newVal);
    }
});

watch(() => submit.pricing_type, () => {
    if (!needsBaseRate.value) {
        submit.base_rate_id = null;
    }
});

watch(() => submit.availability_type, () => {
    if (!needsBaseRate.value) {
        submit.base_rate_id = null;
    }
});

watch(() => submit.apply_to_all, (newVal) => {
    if (newVal) {
        submit.entries = [];
    }
});

onMounted(() => createSubmit());

function onClosed() {
    Object.keys(submit).forEach((key) => delete submit[key]);
    submit.entries = [];
    emit('closed');
}

function createSubmit() {
    Object.keys(submit).forEach((key) => delete submit[key]);
    submit.entries = [];
    Object.entries(props.data).forEach(([name, value]) => {
        submit[name] = value;
    });
    if (!('entries' in submit) || submit.entries === undefined || submit.entries === null) {
        submit.entries = [];
    }
    submit.date_start = props.data.date_start || null;
    submit.date_end = props.data.date_end || null;
    resetSlugify(submit.slug);
    if (isEditing.value) {
        loadAssignedEntries();
    }
}

function slugify() {
    const next = slugifyFrom(submit.title);
    if (next !== undefined) {
        submit.slug = next;
    }
}

function getCollectionEntries(collection) {
    axios.get('/cp/resrv/rates/entries/' + collection)
        .then((response) => {
            collectionEntries.value = response.data;
            entriesLoaded.value = true;
        })
        .catch(() => {
            toast.error('Cannot retrieve entries');
        });
}

function loadAssignedEntries() {
    if (props.data.entries && props.data.entries.length > 0) {
        submit.entries = props.data.entries.map((e) => e.item_id || e.id);
    }
}

function selectAllEntries() {
    submit.entries = collectionEntries.value.map((e) => e.id);
}

function removeAllEntries() {
    submit.entries = [];
}
</script>
