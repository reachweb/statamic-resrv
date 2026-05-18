<template>
    <Modal :open="true" :title="__('Change availability')" icon="calendar-date" @dismissed="emit('cancel')">
        <div class="space-y-6 p-2">
            <Field v-if="rate" :label="__('Rates')">
                <template #actions>
                    <Button size="xs" variant="ghost" :text="__('Select all')" @click="selectAllRates" />
                </template>
                <Combobox
                    multiple
                    :close-on-select="false"
                    :placeholder="__('Select rate')"
                    v-model="selectedRateIds"
                    :options="rateOptions"
                />
            </Field>

            <Field :label="__('Select dates')" :errors="dateErrors">
                <DateRangePicker v-model="dateRange" granularity="day" />
            </Field>

            <div class="grid grid-cols-2 gap-x-4 gap-y-6">
                <Field :label="__('Available')" :errors="errors.available">
                    <Input v-model="available" />
                </Field>
                <Field :label="__('Price')" :errors="errors.price">
                    <Input v-model="price" />
                </Field>
            </div>

            <Field :label="__('Only apply to specific days of the week (optional)')">
                <CheckboxGroup v-model="onlyDays" inline>
                    <Checkbox
                        v-for="(day, index) in weekdays"
                        :key="index"
                        :value="index"
                        :label="day"
                    />
                </CheckboxGroup>
            </Field>
        </div>

        <template #footer>
            <div class="flex items-center justify-end gap-2 p-3 border-t border-gray-200 dark:border-gray-700">
                <Button :text="__('Cancel')" variant="ghost" @click="emit('cancel')" />
                <Button :text="__('Save')" variant="primary" :disabled="disableSave" @click="save" />
            </div>
        </template>
    </Modal>
</template>

<script setup>
import { Button, Checkbox, CheckboxGroup, Combobox, DateRangePicker, Field, Input, Modal } from '@statamic/cms/ui';
import { computed, reactive, ref } from 'vue';
import { useDateRangeModel } from '../composables/useDateRangeModel.js';
import { useFormHandler } from '../composables/useFormHandler.js';

const props = defineProps({
    parentId: {
        type: String,
        required: true,
    },
    rate: {
        type: Object,
        required: false,
    },
    rateOptions: {
        type: Array,
        required: false,
        default: () => [],
    },
});

const emit = defineEmits(['cancel', 'saved']);

const dates = reactive({ start: '', end: '' });
const dateRange = useDateRangeModel(
    () => dates.start,
    () => dates.end,
    (v) => (dates.start = v ?? ''),
    (v) => (dates.end = v ?? ''),
);
const available = ref(null);
const price = ref(null);
const onlyDays = ref([]);
const weekdays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const selectedRateIds = ref(props.rate ? [props.rate.code] : []);

function selectAllRates() {
    selectedRateIds.value = props.rateOptions.map((option) => option.value);
}

const submit = computed(() => {
    const fields = {
        date_start: dates.start,
        date_end: dates.end,
        statamic_id: props.parentId,
        price: price.value,
        available: available.value,
    };
    if (onlyDays.value.length > 0) {
        fields.onlyDays = onlyDays.value;
    }
    if (props.rate) {
        fields.rate_ids = selectedRateIds.value;
    }
    return fields;
});

const { disableSave, errors, save } = useFormHandler({
    submit,
    postUrl: '/cp/resrv/availability',
    method: 'post',
    successMessage: 'Availability successfully saved',
    emit,
});

const dateErrors = computed(() => {
    const out = [];
    if (errors.value?.date_start) out.push(...errors.value.date_start);
    if (errors.value?.date_end) out.push(...errors.value.date_end);
    return out.length ? out : null;
});
</script>
