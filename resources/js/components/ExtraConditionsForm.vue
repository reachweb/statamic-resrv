<template>
    <div>
        <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
            {{ __('Select when to show, hide or make this extra required. When adding multiple conditions for an operation, all of them have to apply.') }}
        </p>
        <div v-for="(condition, index) in conditionsForm" :key="index" class="grid grid-cols-1 lg:grid-cols-[1fr_1fr_2fr_auto] gap-3 items-end py-4 border-b border-gray-200 dark:border-gray-700/80 last:border-b-0">
            <Field :label="__('Operation')" :error="errors['conditions.' + index + '.operation']?.[0]">
                <Select v-model="conditionsForm[index].operation" :options="operationOptions" />
            </Field>
            <Field :label="__('Type')" :error="errors['conditions.' + index + '.type']?.[0]">
                <Select
                    v-model="conditionsForm[index].type"
                    :options="typeOptions"
                    @update:modelValue="clearValues(index)"
                />
            </Field>
            <div>
                <Field
                    v-if="typeIsDate(index)"
                    :label="__('Date range')"
                    :error="rowDateError(index)"
                >
                    <DateRangePicker
                        :model-value="rowDateRange(index)"
                        @update:model-value="updateRowDateRange(index, $event)"
                    />
                </Field>
                <div v-if="typeIsTime(index)" class="grid grid-cols-2 gap-2">
                    <Field :label="__('Time start')" :error="errors['conditions.' + index + '.time_start']?.[0]">
                        <time-fieldtype v-model:value="conditionsForm[index].time_start" />
                    </Field>
                    <Field :label="__('Time end')" :error="errors['conditions.' + index + '.time_end']?.[0]">
                        <time-fieldtype v-model:value="conditionsForm[index].time_end" />
                    </Field>
                </div>
                <div v-if="typeIsValue(index)" class="grid grid-cols-2 gap-2">
                    <Field :label="__('Comparison')" :error="errors['conditions.' + index + '.comparison']?.[0]">
                        <Select v-model="conditionsForm[index].comparison" :options="comparisonOptions" />
                    </Field>
                    <Field :label="__('Value')" :error="errors['conditions.' + index + '.value']?.[0]">
                        <Input v-model="conditionsForm[index].value" />
                    </Field>
                </div>
                <Field v-if="typeIsExtra(index)" :label="__('Extra')" :error="errors['conditions.' + index + '.value']?.[0]">
                    <Select v-model="conditionsForm[index].value" :options="extrasWithoutCurrent" />
                </Field>
                <Field v-if="typeIsCategory(index)" :label="__('Category')" :error="errors['conditions.' + index + '.value']?.[0]">
                    <Select v-model="conditionsForm[index].value" :options="categoryOptions" />
                </Field>
            </div>
            <Button icon="trash" variant="ghost" :aria-label="__('Remove')" @click="remove(index)" />
        </div>
        <div class="pt-4">
            <Button :text="__('Add condition')" variant="default" icon="add" @click="add" />
        </div>
    </div>
</template>

<script setup>
import { Button, DateRangePicker, Field, Input, Select } from '@statamic/cms/ui';
import { computed, onMounted, ref, watch } from 'vue';
import { normalizeInputOptions } from '../composables/useInputOptions.js';
import { toCalendarDate, toIsoString } from '../composables/useDateRangeModel.js';

const props = defineProps({
    data: { type: [Array, Object], required: true },
    extras: { type: [Array, Object], required: true },
    errors: { type: Object, required: false, default: () => ({}) },
});

const emit = defineEmits(['updated']);

const conditionsForm = ref([]);

const operationOptions = computed(() => normalizeInputOptions({
    required: __('Required'),
    show: __('Show when'),
    hidden: __('Hide when'),
}));

const typeOptions = computed(() => normalizeInputOptions({
    always: __('Always'),
    pickup_time: __('Pickup time between'),
    dropoff_time: __('Drop off time between'),
    reservation_duration: __('Reservation duration'),
    reservation_dates: __('Reservation dates included'),
    extra_selected: __('Extra is selected'),
    extra_not_selected: __('Extra is not selected'),
    extra_in_category_selected: __('Extra in category is selected'),
    no_extra_in_category_selected: __('No extra in category is selected'),
}));

const comparisonOptions = computed(() => normalizeInputOptions({
    '==': __('Equal to'),
    '!=': __('Not equal to'),
    '>': __('Greater than'),
    '<': __('Less than'),
    '>=': __('Greater or equal to'),
    '<=': __('Less or equal to'),
}));

const extrasWithoutCurrent = computed(() => {
    const list = Array.isArray(props.extras) ? props.extras : Object.values(props.extras);
    return list
        .filter((extra) => extra.id !== props.data.id)
        .map((extra) => ({ value: extra.id, label: extra.name }));
});

const categoryOptions = computed(() => {
    const list = Array.isArray(props.extras) ? props.extras : Object.values(props.extras);
    const seen = new Set();
    const out = [];
    for (const extra of list) {
        if (extra.category_id === null || extra.category_id === undefined) {
            continue;
        }
        if (seen.has(extra.category_id)) {
            continue;
        }
        seen.add(extra.category_id);
        out.push({
            value: parseInt(extra.category.id, 10),
            label: extra.category ? extra.category.name : 'Uncategorized',
        });
    }
    return out;
});

watch(conditionsForm, (conditions) => {
    emit('updated', handleDates(conditions));
}, { deep: true });

onMounted(() => {
    if (props.data) {
        conditionsForm.value = Array.isArray(props.data) ? props.data : [];
    }
    if (conditionsForm.value.length > 0) {
        emit('updated', handleDates(conditionsForm.value));
    }
});

function add() {
    conditionsForm.value.push({
        operation: '',
        type: '',
        comparison: '',
        value: '',
        date_start: '',
        date_end: '',
        time_start: '',
        time_end: '',
    });
}

function remove(index) {
    conditionsForm.value.splice(index, 1);
}

function typeIsDate(index) {
    return conditionsForm.value[index].type === 'reservation_dates';
}

function typeIsTime(index) {
    const t = conditionsForm.value[index].type;
    return t === 'pickup_time' || t === 'dropoff_time';
}

function typeIsValue(index) {
    return conditionsForm.value[index].type === 'reservation_duration';
}

function typeIsExtra(index) {
    const t = conditionsForm.value[index].type;
    return t === 'extra_selected' || t === 'extra_not_selected';
}

function typeIsCategory(index) {
    const t = conditionsForm.value[index].type;
    return t === 'extra_in_category_selected' || t === 'no_extra_in_category_selected';
}

function handleDates(conditions) {
    return conditions.map((condition) => {
        if (condition.type === 'reservation_dates') {
            return {
                ...condition,
                date_start: condition.date_start || '',
                date_end: condition.date_end || '',
            };
        }
        return condition;
    });
}

function rowDateRange(index) {
    const row = conditionsForm.value[index];
    return {
        start: toCalendarDate(row.date_start),
        end: toCalendarDate(row.date_end),
    };
}

function updateRowDateRange(index, value) {
    const row = conditionsForm.value[index];
    row.date_start = toIsoString(value?.start) ?? '';
    row.date_end = toIsoString(value?.end) ?? '';
}

function rowDateError(index) {
    return (
        props.errors['conditions.' + index + '.date_start']?.[0] ||
        props.errors['conditions.' + index + '.date_end']?.[0] ||
        null
    );
}

function clearValues(index) {
    const row = conditionsForm.value[index];
    row.value = '';
    row.comparison = '';
    row.date_start = '';
    row.date_end = '';
    row.time_start = '';
    row.time_end = '';
}
</script>
