<template>
    <Modal :open="true" :title="__('Change availability')" icon="calendar-date" @dismissed="emit('cancel')">
        <div class="space-y-6 p-2">
            <Alert v-if="generalErrors" variant="error">
                <div v-for="(message, index) in generalErrors" :key="index">{{ message }}</div>
            </Alert>

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
                <Field :label="__('Available')" :errors="availableErrors" :instructions="availabilityHint">
                    <Input v-model="available" :disabled="selectedEditability.allAvailabilityLocked" />
                </Field>
                <Field :label="__('Price')" :errors="priceErrors" :instructions="priceHint">
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
import { Alert, Button, Checkbox, CheckboxGroup, Combobox, DateRangePicker, Field, Input, Modal } from '@statamic/cms/ui';
import { computed, reactive, ref } from 'vue';
import axios from 'axios';
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

// Build the payload(s) to POST. Each value must only reach the rates that accept it (availability
// never redirects a shared rate's edit onto its base pool; price never lands on a mirror rate), yet
// rates that accept BOTH fields must be edited together so the combined write can create new dates
// (the CP validator only allows a single-field edit when priced rows already exist). When a rate is
// in play, the selection is bucketed by (takesPrice, takesAvailable) signature and sent as ONE
// request whose `groups` the backend applies in a single transaction — so even a mixed-signature
// selection is fully atomic (no group can commit while another is rejected).
function buildRequests() {
    const hasPrice = price.value !== null && price.value !== '';
    const hasAvailable = available.value !== null && available.value !== '';

    // Nothing entered means there is nothing to send. Returning [] here (rather than relying on a
    // separate guard) keeps this the single source of truth for what a save would actually post, so
    // saveDisabled and save() can never disagree.
    if (!hasPrice && !hasAvailable) {
        return [];
    }

    const base = {
        date_start: dates.start,
        date_end: dates.end,
        statamic_id: props.parentId,
    };
    if (onlyDays.value.length > 0) {
        base.onlyDays = onlyDays.value;
    }

    // No rate context (single-rate-less entry) or rate metadata unavailable: one unscoped request.
    if (!props.rate || selectedDescriptors.value.length === 0) {
        const fields = { ...base, price: hasPrice ? price.value : null, available: hasAvailable ? available.value : null };
        if (props.rate) {
            fields.rate_ids = selectedRateIds.value;
        }
        return [fields];
    }

    // Bucket rates by their (takesPrice, takesAvailable) signature — considering only the fields the
    // user actually filled in, so a blank field never forces a rate into its own group.
    const groups = new Map();
    for (const d of selectedDescriptors.value) {
        const takesPrice = hasPrice && d.editability.price;
        const takesAvailable = hasAvailable && d.editability.availability;
        if (!takesPrice && !takesAvailable) {
            continue;
        }
        const key = `${takesPrice ? 'p' : ''}${takesAvailable ? 'a' : ''}`;
        if (!groups.has(key)) {
            groups.set(key, { takesPrice, takesAvailable, ids: [] });
        }
        groups.get(key).ids.push(d.id);
    }

    if (groups.size === 0) {
        return [];
    }

    const groupPayload = Array.from(groups.values()).map(({ takesPrice, takesAvailable, ids }) => ({
        price: takesPrice ? price.value : null,
        available: takesAvailable ? available.value : null,
        rate_ids: ids,
    }));

    return [{ ...base, groups: groupPayload }];
}

const { disableSave, errors, handleSuccess, handleErrors, clearErrors } = useFormHandler({
    // submit is unused: save() builds and posts its own request(s) via buildRequests() (see below)
    // rather than submitting this payload, so the handler is reused only for its error/toast/disable state.
    submit: computed(() => ({})),
    postUrl: cp_url('resrv/availability'),
    method: 'post',
    successMessage: 'Availability successfully saved',
    emit,
});

// A save posts exactly what buildRequests() produces. Computing it here makes it the single source
// of truth shared by save() and saveDisabled, so Save can never be enabled for an edit that resolves
// to nothing — e.g. a value typed for a field that every (possibly narrowed) selected rate locks,
// which buildRequests() drops, leaving []. (buildRequests() also returns [] when no value is entered.)
const requests = computed(() => buildRequests());

async function save() {
    if (requests.value.length === 0) {
        return;
    }
    disableSave.value = true;
    clearErrors();
    try {
        // A rate-scoped edit is a single request whose `groups` the backend applies in one
        // transaction, so the whole bulk edit is atomic. (The no-rate fallback is also a single
        // request.) The loop simply posts whatever buildRequests() returned.
        for (const data of requests.value) {
            await axios.post(cp_url('resrv/availability'), data);
        }
        handleSuccess();
    } catch (error) {
        handleErrors(error.response);
    }
}

// Block Save whenever there is nothing to post: no value entered, or every entered value is filtered
// out because the (possibly narrowed) rate selection locks those fields — both collapse requests to
// []. An empty rate selection would post rate_ids: [], which the backend silently no-ops, so block
// that explicitly: buildRequests() falls back to one unscoped request in that case (length 1).
const saveDisabled = computed(() => disableSave.value
    || requests.value.length === 0
    || (Boolean(props.rate) && selectedRateIds.value.length === 0));

// 422s from the grouped bulk path key errors by internal bucket names the user never sees
// (`groups.0`, `groups.0.price`, …). Fold field-scoped group errors back onto the visible Price and
// Available fields, keep date errors on the date picker, and surface everything else (group-level
// messages like "availability does not exist", rate_ids, statamic_id) in a general alert — so a 422
// carrying an `errors` object is never silently swallowed (handleErrors() suppresses its toast).
function collectErrors(matches) {
    const all = errors.value ?? {};
    const out = [];
    for (const [key, messages] of Object.entries(all)) {
        if (matches(key)) {
            out.push(...(Array.isArray(messages) ? messages : [messages]));
        }
    }
    return out;
}

const priceErrors = computed(() => {
    const out = collectErrors((key) => key === 'price' || /^groups\.\d+\.price$/.test(key));
    return out.length ? out : null;
});

const availableErrors = computed(() => {
    const out = collectErrors((key) => key === 'available' || /^groups\.\d+\.available$/.test(key));
    return out.length ? out : null;
});

const dateErrors = computed(() => {
    const out = collectErrors((key) => key === 'date_start' || key === 'date_end');
    return out.length ? out : null;
});

const FIELD_SCOPED_ERROR = /^(price|available|date_start|date_end|groups\.\d+\.(price|available))$/;
const generalErrors = computed(() => {
    const unique = [...new Set(collectErrors((key) => ! FIELD_SCOPED_ERROR.test(key)))];
    return unique.length ? unique : null;
});
</script>
