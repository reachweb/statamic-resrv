<template>
    <Stack
        :open="true"
        :title="isEditing ? __('Edit dynamic pricing') : __('Add dynamic pricing')"
        icon="money-cashier-price-tag"
        @closed="onClosed"
    >
        <template #header-actions>
            <Button :text="__('Save')" variant="primary" :disabled="form.processing" @click="save" />
        </template>
        <template #default>
            <Card>
                <div class="space-y-6">
                    <Field v-bind="fieldProps('title', __('Title'))">
                        <Input v-model="form.title" />
                    </Field>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-x-4 gap-y-6">
                        <Field v-bind="fieldProps('amount', __('Amount'), __('Amount or percentage without the % character.'))">
                            <Input v-model="form.amount" :input-attrs="{ inputmode: 'decimal' }" />
                        </Field>
                        <Field v-bind="fieldProps('amount_operation', __('Operation'), __('Select if the base price will be decreased or increased.'))">
                            <Select v-model="form.amount_operation" :options="amountOperationOptions" />
                        </Field>
                        <Field v-bind="fieldProps('amount_type', __('Type'), __('Percentage or fixed price.'))">
                            <Select v-model="form.amount_type" :options="availableAmountTypes" />
                        </Field>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-x-4 gap-y-6">
                        <Field v-bind="fieldProps('date_include', __('Date condition'), __('Add a date condition.'))">
                            <Select
                                v-model="form.date_include"
                                :options="dateConditionOptions"
                                :clearable="true"
                                @update:modelValue="removeDate"
                            />
                        </Field>
                        <Field :label="__('Date range')" :instructions="__('Select the range of the date condition.')" :errors="dateRangeErrors">
                            <DateRangePicker v-model="dateRange" granularity="day" />
                        </Field>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-x-4 gap-y-6">
                        <Field v-bind="fieldProps('condition_type', __('Reservation condition'), __('Apply the dynamic pricing when...'))">
                            <Select v-model="form.condition_type" :options="conditionTypeOptions" />
                        </Field>
                        <Field v-bind="fieldProps('condition_comparison', __('Comparison'), __('Select the comparison operator'))">
                            <Select v-model="form.condition_comparison" :options="conditionComparisonOptions" />
                        </Field>
                        <Field v-bind="fieldProps('condition_value', __('Value'), __('The value to compare to (days or price).'))">
                            <Input v-model="form.condition_value" :input-attrs="{ inputmode: 'decimal' }" />
                        </Field>
                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-x-4 gap-y-6">
                        <Field :label="__('Entries')" :instructions="__('Select the entries that this dynamic pricing applies to')" :error="form.errors.entries">
                            <EntriesStackPicker
                                v-if="entriesLoaded"
                                v-model="form.entries"
                                :options="entries"
                                option-label="title"
                                option-value="item_id"
                                :stack-title="__('Select entries')"
                            >
                                <template #actions>
                                    <Button size="xs" variant="ghost" :text="__('Select all')" @click="selectAllEntries" />
                                    <span class="text-xs text-gray-400">|</span>
                                    <Button size="xs" variant="ghost" :text="__('Clear')" @click="clearAllEntries" />
                                </template>
                            </EntriesStackPicker>
                        </Field>
                        <Field :label="__('Extras')" :instructions="__('Select the extras that this dynamic pricing applies to')" :error="form.errors.extras">
                            <template #actions>
                                <Button size="xs" variant="ghost" :text="__('Select all')" @click="selectAllExtras" />
                                <span class="text-xs text-gray-400">|</span>
                                <Button size="xs" variant="ghost" :text="__('Clear')" @click="clearAllExtras" />
                            </template>
                            <Combobox
                                v-if="extrasLoaded"
                                v-model="form.extras"
                                multiple
                                :close-on-select="false"
                                :options="extras"
                                option-label="name"
                                option-value="id"
                                :searchable="true"
                            />
                        </Field>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-x-4 gap-y-6">
                        <Field v-bind="fieldProps('coupon', __('Coupon'), __('Dynamic pricing applied only if coupon is applied during checkout. Coupons get applied even if another policy is set as overriding.'))">
                            <Input v-model="form.coupon" />
                        </Field>
                        <Field v-bind="fieldProps('expire_at', __('Expire at'), __('Select a date / time that this dynamic pricing will expire.'))">
                            <Input v-model="form.expire_at" type="datetime-local" />
                        </Field>
                    </div>

                    <Field :label="__('Overrides all other dynamic pricing policies')">
                        <Switch v-model="form.overrides_all" />
                    </Field>

                    <Field :label="__('Published')">
                        <Switch v-model="form.published" />
                    </Field>
                </div>
            </Card>
        </template>
    </Stack>
</template>

<script setup>
import { Button, Card, Combobox, DateRangePicker, Field, Input, Select, Stack, Switch } from '@statamic/cms/ui';
import { useForm } from '@statamic/cms/inertia';
import { computed, onMounted, ref, watch } from 'vue';
import axios from 'axios';
import EntriesStackPicker from './EntriesStackPicker.vue';
import { useDateRangeModel } from '../composables/useDateRangeModel.js';
import { useToast } from '../composables/useToast.js';

const props = defineProps({
    data: { type: Object, required: true },
    timezone: { type: String, required: true },
});

const emit = defineEmits(['closed', 'saved']);
const toast = useToast();

const entries = ref([]);
const entriesLoaded = ref(false);
const extras = ref([]);
const extrasLoaded = ref(false);

const form = useForm({
    title: '',
    amount: '',
    amount_type: '',
    amount_operation: '',
    date_start: '',
    date_end: '',
    date_include: '',
    condition_type: '',
    condition_comparison: '',
    condition_value: '',
    entries: [],
    extras: [],
    coupon: '',
    expire_at: '',
    overrides_all: false,
    published: true,
    order: null,
});

const dateRange = useDateRangeModel(
    () => form.date_start,
    () => form.date_end,
    (v) => (form.date_start = v ?? ''),
    (v) => (form.date_end = v ?? ''),
);

const amountOperationOptions = [
    { value: 'decrease', label: 'Decrease' },
    { value: 'increase', label: 'Increase' },
    { value: 'minimum', label: 'Minimum' },
    { value: 'maximum', label: 'Maximum' },
];
const allAmountTypes = [
    { value: 'percent', label: 'Percent' },
    { value: 'fixed', label: 'Fixed' },
];
const conditionTypeOptions = [
    { value: 'reservation_duration', label: 'The duration of the reservation is' },
    { value: 'reservation_price', label: 'The total price of the reservation is' },
    { value: 'days_to_reservation', label: 'Reservation start date compared to reservation made date' },
];
const conditionComparisonOptions = [
    { value: '==', label: 'Equal to' },
    { value: '!=', label: 'Not equal to' },
    { value: '>', label: 'Greater than' },
    { value: '<', label: 'Less than' },
    { value: '>=', label: 'Greater or equal to' },
    { value: '<=', label: 'Less or equal to' },
];
const dateConditionOptions = [
    { value: 'all', label: 'Reservation dates must be inside this date range' },
    { value: 'start', label: 'Reservation starting date must be inside this date range' },
    { value: 'most', label: 'Most of the reservation dates must be inside this date range' },
];

const availableAmountTypes = computed(() => {
    if (['minimum', 'maximum'].includes(form.amount_operation)) {
        return allAmountTypes.filter((type) => type.value === 'fixed');
    }
    return allAmountTypes;
});

const isEditing = computed(() => 'id' in props.data && !!props.data.id);

const dateRangeErrors = computed(() => {
    const out = [];
    if (form.errors.date_start) out.push(form.errors.date_start);
    if (form.errors.date_end) out.push(form.errors.date_end);
    return out.length ? out : null;
});

function fieldProps(key, label, instructions = null) {
    return {
        label,
        instructions,
        errors: form.errors[key],
    };
}

watch(() => props.data, hydrateForm, { deep: true });

watch(() => form.amount_operation, (newValue) => {
    if (['minimum', 'maximum'].includes(newValue)) {
        form.amount_type = 'fixed';
    }
});

onMounted(() => {
    hydrateForm();
    getEntries();
    getExtras();
});

function hydrateForm() {
    const d = props.data;
    form.title = d.title ?? '';
    form.amount = d.amount ?? '';
    form.amount_type = d.amount_type ?? '';
    form.amount_operation = d.amount_operation ?? '';
    form.date_start = d.date_start ?? '';
    form.date_end = d.date_end ?? '';
    form.date_include = d.date_include ?? '';
    form.condition_type = d.condition_type ?? '';
    form.condition_comparison = d.condition_comparison ?? '';
    form.condition_value = d.condition_value ?? '';
    form.entries = Array.isArray(d.entries)
        ? d.entries.map((e) => (typeof e === 'object' ? (e.item_id ?? e.id) : e))
        : [];
    form.extras = Array.isArray(d.extras)
        ? d.extras.map((e) => (typeof e === 'object' ? e.id : e))
        : [];
    form.coupon = d.coupon ?? '';
    form.expire_at = d.expire_at ?? '';
    form.overrides_all = d.overrides_all ?? false;
    form.published = d.published ?? true;
    form.order = d.order ?? null;
    form.clearErrors();
}

function onClosed() {
    form.clearErrors();
    emit('closed');
}

function getEntries() {
    axios.get(cp_url('resrv/utility/entries'))
        .then((response) => {
            entries.value = response.data;
            entriesLoaded.value = true;
        })
        .catch(() => {
            toast.error(__('Cannot retrieve the entries'));
        });
}

function getExtras() {
    axios.get(cp_url('resrv/extra'))
        .then((response) => {
            extras.value = response.data;
            extrasLoaded.value = true;
        })
        .catch(() => {
            toast.error(__('Cannot retrieve the extras'));
        });
}

function selectAllExtras() {
    form.extras = extras.value.map((item) => item.id);
}

function selectAllEntries() {
    form.entries = entries.value.map((item) => item.item_id);
}

function clearAllExtras() {
    form.extras = [];
}

function clearAllEntries() {
    form.entries = [];
}

function removeDate(value) {
    if (value === null || value === undefined || value === '') {
        form.date_start = '';
        form.date_end = '';
    }
}

function save() {
    const url = isEditing.value
        ? cp_url('resrv/dynamicpricing/' + props.data.id)
        : cp_url('resrv/dynamicpricing');
    const method = isEditing.value ? 'patch' : 'post';

    form[method](url, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            toast.success(__('Dynamic pricing successfully saved'));
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
