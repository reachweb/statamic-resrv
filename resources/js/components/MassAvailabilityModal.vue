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
                <Field :label="__('Available')" :errors="errors.available" :instructions="availabilityHint">
                    <Input v-model="available" :disabled="selectedEditability.allAvailabilityLocked" />
                </Field>
                <Field :label="__('Price')" :errors="errors.price" :instructions="priceHint">
                    <Input v-model="price" :disabled="selectedEditability.allPriceLocked" />
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
                <Button :text="__('Save')" variant="primary" :disabled="saveDisabled" @click="save" />
            </div>
        </template>
    </Modal>
</template>

<script setup>
import { Button, Checkbox, CheckboxGroup, Combobox, DateRangePicker, Field, Input, Modal } from '@statamic/cms/ui';
import { computed, reactive, ref } from 'vue';
import { useDateRangeModel } from '../composables/useDateRangeModel.js';
import { useFormHandler } from '../composables/useFormHandler.js';
import { rateEditability } from '../composables/useRateEditability.js';

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
    rates: {
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

const selectedRatesResolved = computed(() => props.rates.filter((r) => selectedRateIds.value.includes(r.id)));

// Editability descriptor per selected rate, computed once and reused by both the gating logic
// below and the submit-time rate_ids filtering, so rateEditability runs once per rate, not twice.
const selectedDescriptors = computed(() =>
    selectedRatesResolved.value.map((r) => ({ id: r.id, editability: rateEditability(r) }))
);

// Editability across the current multi-rate selection: a field is "all locked" only when every
// selected rate locks it, and "mixed" when some do and some don't. Drives input disabling + hints.
const selectedEditability = computed(() => {
    const descriptors = selectedDescriptors.value;
    if (!props.rate || descriptors.length === 0) {
        return { allPriceLocked: false, allAvailabilityLocked: false, mixedPrice: false, mixedAvailability: false };
    }
    const priceEditable = descriptors.filter((d) => d.editability.price).length;
    const availabilityEditable = descriptors.filter((d) => d.editability.availability).length;
    return {
        allPriceLocked: priceEditable === 0,
        allAvailabilityLocked: availabilityEditable === 0,
        mixedPrice: priceEditable > 0 && priceEditable < descriptors.length,
        mixedAvailability: availabilityEditable > 0 && availabilityEditable < descriptors.length,
    };
});

const priceHint = computed(() => {
    const e = selectedEditability.value;
    if (e.allPriceLocked) {
        return __('All selected rates derive their price from a base rate, so the price field is read-only.');
    }
    if (e.mixedPrice) {
        return __('Some selected rates derive their price from a base rate — the price you enter applies only to the rates that allow it.');
    }
    return null;
});

const availabilityHint = computed(() => {
    const e = selectedEditability.value;
    if (e.allAvailabilityLocked) {
        return __('All selected rates share inventory with a base rate, so the availability field is read-only.');
    }
    if (e.mixedAvailability) {
        return __('Some selected rates share inventory with a base rate — availability applies only to the rates that allow it.');
    }
    return null;
});

const submit = computed(() => {
    const hasPrice = price.value !== null && price.value !== '';
    const hasAvailable = available.value !== null && available.value !== '';

    const fields = {
        date_start: dates.start,
        date_end: dates.end,
        statamic_id: props.parentId,
        price: hasPrice ? price.value : null,
        available: hasAvailable ? available.value : null,
    };
    if (onlyDays.value.length > 0) {
        fields.onlyDays = onlyDays.value;
    }
    if (props.rate) {
        // A price-only edit on a price-locked rate either errors (shared+relative aborts the
        // whole batch) or is a dead no-op (relative). When only a price is being set, restrict
        // it to the rates that can actually accept one.
        const priceOnly = hasPrice && !hasAvailable && props.rates.length > 0;
        fields.rate_ids = priceOnly
            ? selectedDescriptors.value.filter((d) => d.editability.price).map((d) => d.id)
            : selectedRateIds.value;
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

// An empty rate selection would post rate_ids: [], which the backend silently no-ops; block Save instead.
// Likewise block Save when every selected rate locks both fields — there is nothing to submit.
const saveDisabled = computed(() => disableSave.value
    || (Boolean(props.rate) && selectedRateIds.value.length === 0)
    || (selectedEditability.value.allPriceLocked && selectedEditability.value.allAvailabilityLocked));

const dateErrors = computed(() => {
    const out = [];
    if (errors.value?.date_start) out.push(...errors.value.date_start);
    if (errors.value?.date_end) out.push(...errors.value.date_end);
    return out.length ? out : null;
});
</script>
