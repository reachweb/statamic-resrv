<template>
    <Stack
        :open="true"
        :title="isEditing ? __('Edit rate') : __('Add rate')"
        icon="money-cashier-price-tag"
        @closed="onClosed"
    >
        <template #header-actions>
            <Button :text="__('Save')" variant="primary" :disabled="form.processing" @click="save" />
        </template>
        <template #default>
            <Panel :heading="__('General')">
                <Card>
                    <div class="space-y-6">
                        <Field :label="__('Collection')" :instructions="__('The collection this rate applies to.')" :error="form.errors.collection">
                            <Select
                                v-model="form.collection"
                                :options="collectionOptions"
                                :clearable="false"
                                :disabled="isEditing"
                            />
                        </Field>
                        <Field :label="__('Apply to all entries in collection')">
                            <Switch v-model="form.apply_to_all" />
                        </Field>
                        <Field v-if="!form.apply_to_all" :label="__('Entries')" :instructions="__('Select the entries this rate should apply to.')" :error="form.errors.entries">
                            <EntriesStackPicker
                                v-if="entriesLoaded"
                                v-model="form.entries"
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
                            <Field :label="__('Title')" :error="form.errors.title">
                                <Input v-model="form.title" @input="slugify" />
                            </Field>
                            <Field :label="__('Slug')" :error="form.errors.slug">
                                <Input v-model="form.slug" @input="onSlugInput" />
                            </Field>
                        </div>
                        <Field :label="__('Description')" :error="form.errors.description">
                            <Textarea v-model="form.description" />
                        </Field>
                    </div>
                </Card>
            </Panel>

            <Panel :heading="__('Pricing')">
                <Card>
                    <div class="space-y-6">
                        <Field :label="__('Pricing type')" :instructions="__('Independent rates have their own pricing. Relative rates derive pricing from a base rate.')" :error="form.errors.pricing_type">
                            <Select v-model="form.pricing_type" :options="pricingTypes" />
                        </Field>
                        <div v-if="form.pricing_type === 'relative'" class="grid grid-cols-1 md:grid-cols-3 gap-x-4 gap-y-6">
                            <Field :label="__('Modifier type')" :instructions="__('Percentage or fixed amount.')" :error="form.errors.modifier_type">
                                <Select v-model="form.modifier_type" :options="modifierTypes" />
                            </Field>
                            <Field :label="__('Modifier operation')" :instructions="__('Increase or decrease from base rate.')" :error="form.errors.modifier_operation">
                                <Select v-model="form.modifier_operation" :options="modifierOperations" />
                            </Field>
                            <Field :label="__('Modifier amount')" :instructions="__('Amount or percentage without the % character.')" :error="form.errors.modifier_amount">
                                <Input v-model="form.modifier_amount" />
                            </Field>
                        </div>
                    </div>
                </Card>
            </Panel>

            <Panel :heading="__('Availability')">
                <Card>
                    <div class="space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-x-4 gap-y-6">
                            <Field :label="__('Availability type')" :instructions="__('Independent rates have their own inventory. Shared rates share inventory with the base rate.')" :error="form.errors.availability_type">
                                <Select v-model="form.availability_type" :options="availabilityTypes" />
                            </Field>
                            <Field v-if="form.availability_type === 'shared'" :label="__('Max available')" :instructions="__('Maximum number of units available for this rate.')" :error="form.errors.max_available">
                                <Input v-model="form.max_available" type="number" />
                            </Field>
                        </div>
                        <Field v-if="form.availability_type === 'shared' && form.pricing_type === 'independent'" :label="__('Require price override')" :instructions="__('When enabled, dates without an explicit price for this rate are unavailable. When disabled, the base rate\'s price is used as a fallback.')">
                            <Switch v-model="form.require_price_override" />
                        </Field>
                    </div>
                </Card>
            </Panel>

            <Panel v-if="needsBaseRate" :heading="__('Base rate')">
                <Card>
                    <div class="space-y-6">
                        <Alert variant="info">
                            <div>{{ baseRateExplanation }}</div>
                        </Alert>
                        <Field :label="__('Base rate')" :instructions="__('Only published, non-shared, non-relative rates in the same collection can be selected.')" :error="form.errors.base_rate_id">
                            <Select v-model="form.base_rate_id" :options="availableBaseRates" />
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
                            <Field :label="__('Min days before')" :instructions="__('Minimum advance booking days.')" :error="form.errors.min_days_before">
                                <Input v-model="form.min_days_before" type="number" />
                            </Field>
                            <Field :label="__('Max days before')" :instructions="__('Maximum advance booking days.')" :error="form.errors.max_days_before">
                                <Input v-model="form.max_days_before" type="number" />
                            </Field>
                            <Field :label="__('Min stay')" :instructions="__('Minimum number of nights.')" :error="form.errors.min_stay">
                                <Input v-model="form.min_stay" type="number" />
                            </Field>
                            <Field :label="__('Max stay')" :instructions="__('Maximum number of nights.')" :error="form.errors.max_stay">
                                <Input v-model="form.max_stay" type="number" />
                            </Field>
                        </div>
                    </div>
                </Card>
            </Panel>

            <Panel :heading="__('Settings')">
                <Card>
                    <div class="space-y-6">
                        <Field :label="__('Refundable')">
                            <Switch v-model="form.refundable" />
                        </Field>
                        <Field :label="__('Published')">
                            <Switch v-model="form.published" />
                        </Field>
                    </div>
                </Card>
            </Panel>
        </template>
    </Stack>
</template>

<script setup>
import { Alert, Button, Card, DateRangePicker, Field, Input, Panel, Select, Stack, Switch, Textarea } from '@statamic/cms/ui';
import { useForm } from '@statamic/cms/inertia';
import { computed, onMounted, ref, watch } from 'vue';
import axios from 'axios';
import EntriesStackPicker from './EntriesStackPicker.vue';
import { useDateRangeModel } from '../composables/useDateRangeModel.js';
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

const collectionEntries = ref([]);
const entriesLoaded = ref(false);

const form = useForm({
    collection: null,
    apply_to_all: true,
    entries: [],
    title: '',
    slug: '',
    description: '',
    pricing_type: 'independent',
    base_rate_id: null,
    modifier_type: null,
    modifier_operation: null,
    modifier_amount: null,
    availability_type: 'independent',
    require_price_override: false,
    max_available: null,
    date_start: null,
    date_end: null,
    min_days_before: null,
    max_days_before: null,
    min_stay: null,
    max_stay: null,
    refundable: true,
    published: true,
});

const dateRange = useDateRangeModel(
    () => form.date_start,
    () => form.date_end,
    (v) => (form.date_start = v),
    (v) => (form.date_end = v),
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

const isEditing = computed(() => 'id' in props.data && !!props.data.id);

const availableBaseRates = computed(() =>
    props.allRates
        .filter((rate) => rate.id !== props.data.id)
        .map((rate) => ({ value: rate.id, label: rate.title })),
);

const needsBaseRate = computed(() =>
    form.pricing_type === 'relative' || form.availability_type === 'shared',
);

const baseRateExplanation = computed(() => {
    const relative = form.pricing_type === 'relative';
    const shared = form.availability_type === 'shared';
    if (relative && shared) {
        return __('This rate derives its pricing from the selected base rate using the modifier configured in the Pricing section, and shares its inventory with the base rate.');
    }
    if (relative) {
        return __('This rate derives its pricing from the selected base rate using the modifier configured in the Pricing section. Inventory is managed independently.');
    }
    return __('This rate shares its inventory with the selected base rate. Pricing is managed independently — set this rate\'s own prices in the Availability calendar.');
});

const dateRangeErrors = computed(() => {
    const out = [];
    if (form.errors.date_start) out.push(form.errors.date_start);
    if (form.errors.date_end) out.push(form.errors.date_end);
    return out.length ? out : null;
});

watch(() => props.data, hydrateForm, { deep: true });

watch(() => form.collection, (newVal) => {
    if (newVal) {
        getCollectionEntries(newVal);
    }
});

watch(needsBaseRate, (needed) => {
    if (!needed) {
        form.base_rate_id = null;
    }
});

watch(() => form.apply_to_all, (newVal) => {
    if (newVal) {
        form.entries = [];
    }
});

onMounted(hydrateForm);

function hydrateForm() {
    const d = props.data;
    form.collection = d.collection ?? null;
    form.apply_to_all = d.apply_to_all ?? true;
    form.title = d.title ?? '';
    form.slug = d.slug ?? '';
    form.description = d.description ?? '';
    form.pricing_type = d.pricing_type ?? 'independent';
    form.base_rate_id = d.base_rate_id ?? null;
    form.modifier_type = d.modifier_type ?? null;
    form.modifier_operation = d.modifier_operation ?? null;
    form.modifier_amount = d.modifier_amount ?? null;
    form.availability_type = d.availability_type ?? 'independent';
    form.require_price_override = d.require_price_override ?? false;
    form.max_available = d.max_available ?? null;
    form.date_start = d.date_start ?? null;
    form.date_end = d.date_end ?? null;
    form.min_days_before = d.min_days_before ?? null;
    form.max_days_before = d.max_days_before ?? null;
    form.min_stay = d.min_stay ?? null;
    form.max_stay = d.max_stay ?? null;
    form.refundable = d.refundable ?? true;
    form.published = d.published ?? true;

    if (Array.isArray(d.entries) && d.entries.length > 0) {
        form.entries = d.entries.map((e) => e.item_id || e.id);
    } else {
        form.entries = [];
    }

    resetSlugify(form.slug);
    form.clearErrors();
}

function onClosed() {
    form.clearErrors();
    emit('closed');
}

function slugify() {
    const next = slugifyFrom(form.title);
    if (next !== undefined) {
        form.slug = next;
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

function selectAllEntries() {
    form.entries = collectionEntries.value.map((e) => e.id);
}

function removeAllEntries() {
    form.entries = [];
}

function save() {
    const url = isEditing.value
        ? '/cp/resrv/rate/' + props.data.id
        : '/cp/resrv/rate';
    const method = isEditing.value ? 'patch' : 'post';

    form[method](url, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            toast.success(__('Rate successfully saved'));
            emit('saved');
        },
        onError: (errors) => {
            if (!Object.keys(errors).length) {
                toast.error(__('Something went wrong. Please try again.'));
            }
        },
    });
}
</script>
