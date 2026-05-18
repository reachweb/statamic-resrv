<template>
    <Stack
        :open="true"
        :title="isEditing ? __('Edit dynamic pricing') : __('Add dynamic pricing')"
        icon="money-cashier-price-tag"
        size="half"
        @closed="onClosed"
    >
        <template #header-actions>
            <Button :text="__('Save')" variant="primary" :disabled="disableSave" @click="save" />
        </template>
        <template #default="{ close }">
            <Card>
                <Field v-bind="fieldProps('title', __('Title'))">
                    <Input v-model="submit.title" />
                </Field>

                <div class="grid grid-cols-1 xl:grid-cols-3 gap-x-4">
                    <Field v-bind="fieldProps('amount', __('Amount'), __('Amount or percentage without the % character.'))">
                        <Input v-model="submit.amount" />
                    </Field>
                    <Field v-bind="fieldProps('amount_operation', __('Operation'), __('Select if the base price will be decreased or increased.'))">
                        <Select v-model="submit.amount_operation" :options="amountOperationOptions" />
                    </Field>
                    <Field v-bind="fieldProps('amount_type', __('Type'), __('Percentage or fixed price.'))">
                        <Select v-model="submit.amount_type" :options="availableAmountTypes" />
                    </Field>
                </div>

                <div class="grid grid-cols-1 xl:grid-cols-2 gap-x-4">
                    <Field v-bind="fieldProps('date_include', __('Date condition'), __('Add a date condition.'))">
                        <Select
                            v-model="submit.date_include"
                            :options="dateConditionOptions"
                            :clearable="true"
                            @update:modelValue="removeDate"
                        />
                    </Field>
                    <Field :label="__('Date range')" :instructions="__('Select the range of the date condition.')" :errors="dateRangeErrors">
                        <DateRangePicker v-model="dateRange" granularity="day" />
                    </Field>
                </div>

                <div class="grid grid-cols-1 xl:grid-cols-3 gap-x-4">
                    <Field v-bind="fieldProps('condition_type', __('Reservation condition'), __('Apply the dynamic pricing when...'))">
                        <Select v-model="submit.condition_type" :options="conditionTypeOptions" />
                    </Field>
                    <Field v-bind="fieldProps('condition_comparison', __('Comparison'), __('Select the comparison operator'))">
                        <Select v-model="submit.condition_comparison" :options="conditionComparisonOptions" />
                    </Field>
                    <Field v-bind="fieldProps('condition_value', __('Value'), __('The value to compare to (days or price).'))">
                        <Input v-model="submit.condition_value" />
                    </Field>
                </div>

                <div class="grid grid-cols-1 2xl:grid-cols-2 gap-x-4">
                    <Field :label="__('Entries')" :instructions="__('Select the entries that this dynamic pricing applies to')" :errors="errors.entries">
                        <template #actions>
                            <Button size="xs" variant="ghost" :text="__('Select all')" @click="selectAllEntries" />
                            <span class="text-xs text-gray-400">|</span>
                            <Button size="xs" variant="ghost" :text="__('Clear')" @click="clearAllEntries" />
                        </template>
                        <Combobox
                            v-if="entriesLoaded"
                            v-model="submit.entries"
                            multiple
                            :close-on-select="false"
                            :options="entries"
                            option-label="title"
                            option-value="item_id"
                            :searchable="true"
                        />
                    </Field>
                    <Field :label="__('Extras')" :instructions="__('Select the extras that this dynamic pricing applies to')" :errors="errors.extras">
                        <template #actions>
                            <Button size="xs" variant="ghost" :text="__('Select all')" @click="selectAllExtras" />
                            <span class="text-xs text-gray-400">|</span>
                            <Button size="xs" variant="ghost" :text="__('Clear')" @click="clearAllExtras" />
                        </template>
                        <Combobox
                            v-if="extrasLoaded"
                            v-model="submit.extras"
                            multiple
                            :close-on-select="false"
                            :options="extras"
                            option-label="name"
                            option-value="id"
                            :searchable="true"
                        />
                    </Field>
                </div>

                <div class="grid grid-cols-1 2xl:grid-cols-2 gap-x-4">
                    <Field v-bind="fieldProps('coupon', __('Coupon'), __('Dynamic pricing applied only if coupon is applied during checkout. Coupons get applied even if another policy is set as overriding.'))">
                        <Input v-model="submit.coupon" />
                    </Field>
                    <Field v-bind="fieldProps('expire_at', __('Expire at'), __('Select a date / time that this dynamic pricing will expire.'))">
                        <Input v-model="submit.expire_at" type="datetime-local" />
                    </Field>
                </div>

                <Field :label="__('Overrides all other dynamic pricing policies')">
                    <Switch v-model="submit.overrides_all" />
                </Field>
            </Card>
        </template>
    </Stack>
</template>

<script setup>
import { Button, Card, Combobox, DateRangePicker, Field, Input, Select, Stack, Switch } from '@statamic/cms/ui';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import axios from 'axios';
import { useDateRangeModel } from '../composables/useDateRangeModel.js';
import { useFormHandler } from '../composables/useFormHandler.js';
import { useToast } from '../composables/useToast.js';

const props = defineProps({
    data: { type: Object, required: true },
    timezone: { type: String, required: true },
    openPanel: { type: Boolean, default: false },
});

const emit = defineEmits(['closed', 'saved']);
const toast = useToast();

const submit = reactive({
    entries: [],
    extras: [],
});
const entries = ref([]);
const entriesLoaded = ref(false);
const extras = ref([]);
const extrasLoaded = ref(false);

const dateRange = useDateRangeModel(
    () => submit.date_start,
    () => submit.date_end,
    (v) => (submit.date_start = v ?? ''),
    (v) => (submit.date_end = v ?? ''),
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
    if (['minimum', 'maximum'].includes(submit.amount_operation)) {
        return allAmountTypes.filter((type) => type.value === 'fixed');
    }
    return allAmountTypes;
});

const isEditing = computed(() => 'id' in props.data);
const method = computed(() => (isEditing.value ? 'patch' : 'post'));
const postUrl = computed(() =>
    isEditing.value
        ? '/cp/resrv/dynamicpricing/' + props.data.id
        : '/cp/resrv/dynamicpricing',
);

const { disableSave, errors, save } = useFormHandler({
    submit,
    postUrl,
    method,
    successMessage: 'Dynamic pricing successfully saved',
    emit,
});

const dateRangeErrors = computed(() => {
    const out = [];
    if (errors.value?.date_start) out.push(...errors.value.date_start);
    if (errors.value?.date_end) out.push(...errors.value.date_end);
    return out.length ? out : null;
});

function fieldProps(key, label, instructions = null) {
    return {
        label,
        instructions,
        errors: errors.value?.[key],
    };
}

watch(() => props.data, () => createSubmit(), { deep: true });

watch(() => submit.amount_operation, (newValue) => {
    if (['minimum', 'maximum'].includes(newValue)) {
        submit.amount_type = 'fixed';
    }
});

onMounted(() => {
    createSubmit();
    getEntries();
    getExtras();
});

function onClosed() {
    Object.keys(submit).forEach((key) => delete submit[key]);
    submit.entries = [];
    submit.extras = [];
    emit('closed');
}

function createSubmit() {
    Object.keys(submit).forEach((key) => delete submit[key]);
    submit.entries = [];
    submit.extras = [];
    Object.entries(props.data).forEach(([name, value]) => {
        submit[name] = value;
    });
}

function getEntries() {
    axios.get('/cp/resrv/utility/entries')
        .then((response) => {
            entries.value = response.data;
            entriesLoaded.value = true;
        })
        .catch(() => {
            toast.error('Cannot retrieve the entries');
        });
}

function getExtras() {
    axios.get('/cp/resrv/extra')
        .then((response) => {
            extras.value = response.data;
            extrasLoaded.value = true;
        })
        .catch(() => {
            toast.error('Cannot retrieve the extras');
        });
}

function selectAllExtras() {
    submit.extras = extras.value.map((item) => item.id);
}

function selectAllEntries() {
    submit.entries = entries.value.map((item) => item.item_id);
}

function clearAllExtras() {
    submit.extras = [];
}

function clearAllEntries() {
    submit.entries = [];
}

function removeDate(value) {
    if (value === null || value === undefined || value === '') {
        submit.date_start = '';
        submit.date_end = '';
    }
}
</script>
