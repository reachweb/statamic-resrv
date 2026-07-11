<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import axios from 'axios';
import { Head } from '@statamic/cms/inertia';
import {
    Alert,
    Badge,
    Button,
    Card,
    Combobox,
    DatePicker,
    DateRangePicker,
    Description,
    Field,
    Header,
    Heading,
    Icon,
    Input,
    Select,
    Separator,
    Switch,
    Textarea,
} from '@statamic/cms/ui';
import { today, getLocalTimeZone } from '@internationalized/date';
import { useToast } from '../../composables/useToast.js';
import { useDateRangeModel, toCalendarDate, toIsoString } from '../../composables/useDateRangeModel.js';

const props = defineProps({
    entriesUrl: { type: String, required: true },
    entryUrlTemplate: { type: String, required: true },
    quoteUrl: { type: String, required: true },
    storeUrl: { type: String, required: true },
    backUrl: { type: String, required: true },
    currencySymbol: { type: String, default: '' },
    maximumQuantity: { type: Number, default: 1 },
    maximumReservationPeriod: { type: Number, default: 30 },
    minimumDaysBefore: { type: Number, default: 0 },
    gateways: { type: Array, default: () => [] },
    paymentEntryConfigured: { type: Boolean, default: false },
    affiliates: { type: Array, default: null },
});

const toast = useToast();

// --- Form state (all money figures are server-computed; this only holds inputs) ---
const form = reactive({
    item_id: null,
    date_start: '',
    date_end: '',
    quantity: 1,
    rate_id: null,
    extras: {}, // extra id -> quantity
    options: {}, // option id -> value id
    total_override: '',
    override_enabled: false,
    payment_mode: 'standard',
    custom_amount: '',
    payment_gateway: null,
    affects_availability: true,
    send_payment_request_email: true,
    hold_enabled: false,
    hold_days: 3,
    affiliate_id: null,
    customer: {},
});

const entries = ref([]);
const rates = ref([]);
const formFields = ref([]);
const quote = ref(null);
const availableExtras = ref([]);
const availableOptions = ref([]);
const quoteError = ref(null);
const storeError = ref(null);
const errors = ref({});
const quoting = ref(false);
const submitting = ref(false);

const entryOptions = computed(() =>
    entries.value.map((entry) => ({ value: entry.item_id, label: entry.title, collection: entry.collection })),
);
const rateOptions = computed(() => rates.value.map((rate) => ({ value: rate.id, label: rate.title })));
const gatewayOptions = computed(() =>
    props.gateways.map((gateway) => ({
        value: gateway.key,
        label: gatewayDisabled(gateway) ? `${gateway.label} — ${__('payment page not configured')}` : gateway.label,
        disabled: gatewayDisabled(gateway),
    })),
);
const affiliateOptions = computed(() =>
    (props.affiliates ?? []).map((affiliate) => ({ value: affiliate.id, label: `${affiliate.name} (${affiliate.code})` })),
);
const paymentModeOptions = computed(() => [
    { value: 'standard', label: standardModeLabel.value },
    { value: 'full', label: quote.value ? `${__('Full amount')} (${money(quote.value.pricing.total)})` : __('Full amount') },
    { value: 'custom', label: __('Custom amount') },
]);

const standardModeLabel = computed(() => {
    const base = __('Standard (what checkout would charge)');
    if (quote.value && form.payment_mode === 'standard') {
        return `${base} — ${money(quote.value.payment.amount)}`;
    }
    return base;
});

// Online gateways are unusable without a configured payment page (there is no link to
// email); offline (manually-confirmable) ones always work.
const gatewayDisabled = (gateway) => ! props.paymentEntryConfigured && ! gateway.supports_manual_confirmation;

const money = (amount) => `${props.currencySymbol} ${amount}`;

// --- Dates ---
// A maximum period of one day means one-day bookings: mirror the frontend's single
// datepicker, which books [date, date + 1 day].
const singleDate = computed(() => props.maximumReservationPeriod === 1);

const minPickerDate = computed(() => today(getLocalTimeZone()).add({ days: props.minimumDaysBefore }));

const dateRange = useDateRangeModel(
    () => form.date_start,
    () => form.date_end,
    (value) => (form.date_start = value ?? ''),
    (value) => (form.date_end = value ?? ''),
);

const singleDateModel = computed({
    get: () => toCalendarDate(form.date_start),
    set: (value) => {
        form.date_start = value ? toIsoString(value) : '';
        form.date_end = value ? toIsoString(value.add({ days: 1 })) : '';
    },
});

const dateError = computed(() => fieldError('date_start') ?? fieldError('date_end'));

const datesComplete = computed(() => form.item_id && form.date_start && form.date_end);

const availabilityBlocks = computed(
    () => quote.value && ! quote.value.availability.status && form.affects_availability,
);

const willOverbook = computed(
    () => quote.value && ! quote.value.availability.status && ! form.affects_availability,
);

const selectedGatewayAmount = computed(() => {
    if (! quote.value || ! form.payment_gateway) return null;
    return quote.value.payment.gateways?.[form.payment_gateway] ?? null;
});

// Validated on the client against the quoted total so the message lands right under the
// Custom amount field instead of the server round-trip surfacing it up in the booking card.
const customAmountError = computed(() => {
    if (form.payment_mode !== 'custom' || ! quote.value) return null;
    if (form.custom_amount === '' || form.custom_amount === null) return null;

    const amount = Number(form.custom_amount);

    if (Number.isNaN(amount) || amount <= 0) {
        return __('Enter an amount greater than zero.');
    }

    if (amount > Number(quote.value.pricing.total)) {
        return __('This is more than the total. To charge more, raise it with the "Override total" toggle above.');
    }

    return null;
});

// A zero-amount booking (fully comped / zero deposit) collects nothing and confirms immediately,
// so it needs no gateway — which matters when online gateways are disabled for want of a payment
// page. The server enforces "gateway required" whenever the amount is non-zero.
const paymentAmountIsZero = computed(() => quote.value && Number(quote.value.payment.amount) === 0);

const canSubmit = computed(
    () => datesComplete.value && quote.value && ! quoteError.value && ! customAmountError.value && ! availabilityBlocks.value && (paymentAmountIsZero.value || form.payment_gateway) && ! submitting.value,
);

// --- Data loading ---
onMounted(async () => {
    try {
        const { data } = await axios.get(props.entriesUrl);
        entries.value = data;
    } catch (error) {
        toast.error(__('Could not load the bookable entries'));
    }
});

// Entry-detail requests can resolve out of order like quotes can (select entry A, then B
// before A's response lands), so only the latest selection may write rates/fields/customer
// state — a stale response would leave the form quoting B with A's rate and checkout fields.
let entrySequence = 0;

watch(() => form.item_id, async (itemId) => {
    const sequence = ++entrySequence;
    rates.value = [];
    formFields.value = [];
    form.rate_id = null;
    form.extras = {};
    form.options = {};
    quote.value = null;
    if (! itemId) return;

    try {
        const { data } = await axios.get(props.entryUrlTemplate.replace('ITEMID', itemId));
        if (sequence !== entrySequence) return;
        rates.value = data.rates;
        formFields.value = data.form_fields;
        form.rate_id = data.rates[0]?.id ?? null;
        form.customer = Object.fromEntries(
            data.form_fields.map((field) => [field.handle, emptyFieldValue(field)]),
        );
        requestQuote();
    } catch (error) {
        if (sequence !== entrySequence) return;
        toast.error(__('Could not load the entry data'));
    }
});

// --- Quoting (server-side money math; debounced on input changes) ---
let quoteTimer = null;
let quoteSequence = 0;

const requestQuote = () => {
    clearTimeout(quoteTimer);
    quoteTimer = setTimeout(fetchQuote, 400);
};

const quotePayload = () => ({
    item_id: form.item_id,
    date_start: form.date_start,
    date_end: form.date_end,
    quantity: form.quantity,
    rate_id: form.rate_id,
    extras: Object.entries(form.extras)
        .filter(([, quantity]) => quantity > 0)
        .map(([id, quantity]) => ({ id: Number(id), quantity })),
    options: Object.entries(form.options)
        .filter(([, value]) => value)
        .map(([id, value]) => ({ id: Number(id), value })),
    total_override: form.override_enabled && form.total_override !== '' ? form.total_override : null,
    payment_mode: form.payment_mode,
    custom_amount: form.payment_mode === 'custom' && form.custom_amount !== '' && ! customAmountError.value ? form.custom_amount : null,
    payment_gateway: form.payment_gateway,
    // Custom-priced extras multiply by a checkout-form field (e.g. adults), so the quote
    // must price with the same customer data creation will use — never a ×1 preview.
    customer: form.customer,
});

const fetchQuote = async () => {
    if (! datesComplete.value) return;
    // Requests can resolve out of order (a slow older request finishing after a newer one),
    // so only the latest request may write quote state — stale responses are discarded.
    const sequence = ++quoteSequence;
    quoting.value = true;
    quoteError.value = null;
    try {
        const { data } = await axios.post(props.quoteUrl, quotePayload());
        if (sequence !== quoteSequence) return;
        quote.value = data;
        availableExtras.value = data.available_extras ?? [];
        availableOptions.value = data.available_options ?? [];
    } catch (error) {
        if (sequence !== quoteSequence) return;
        // Keep the last good quote on screen so the pricing/payment section never collapses
        // (which would hide the very inputs needed to fix the problem); surface the reason
        // inline and block submission instead.
        const validationMessage = Object.values(error?.response?.data?.errors ?? {}).flat().join(' ');
        quoteError.value = error?.response?.data?.error
            ?? (validationMessage || __('Could not compute a quote'));
    } finally {
        if (sequence === quoteSequence) quoting.value = false;
    }
};

watch(
    () => [
        form.date_start,
        form.date_end,
        form.quantity,
        form.rate_id,
        form.extras,
        form.options,
        form.total_override,
        form.override_enabled,
        form.payment_mode,
        form.custom_amount,
        form.payment_gateway,
    ],
    requestQuote,
    { deep: true },
);

// Re-quote when a customer field that drives a custom-priced extra changes — selected or not,
// since the listed per-unit price is also computed from it — but not on every keystroke in
// unrelated fields like the name. Serialized to a string so the quote's own availableExtras
// refresh (same content, new array) cannot re-trigger it.
const customPriceFieldState = computed(() =>
    JSON.stringify(
        availableExtras.value
            .filter((extra) => extra.price_type === 'custom' && extra.custom)
            .map((extra) => [extra.custom, form.customer[extra.custom] ?? null]),
    ),
);

watch(customPriceFieldState, requestQuote);

// --- Customer field renderer helpers ---
// The same set the frontend checkout renders (resources/views/livewire/components/fields/) —
// a checkout form that works there must be fillable here with the same value shapes.
const knownFieldTypes = ['text', 'textarea', 'select', 'checkboxes', 'radio', 'toggle', 'integer', 'dictionary'];

const fieldComponentType = (field) => {
    if (knownFieldTypes.includes(field.type)) return field.type;
    console.warn(`[Resrv] Unknown checkout form field type [${field.type}] — rendering as text.`);
    return 'text';
};

const emptyFieldValue = (field) => {
    if (field.type === 'checkboxes') return [];
    if (field.type === 'toggle') return false;
    return '';
};

const fieldIsRequired = (field) => (field.validate ?? []).some((rule) => String(rule).startsWith('required'));

// Statamic stores select/radio/checkboxes options as an assoc map, a list of {key, value}
// rows, or a plain list of values — normalize all three (cf. HasSelectOptions::getOptions()).
const fieldSelectOptions = (field) => {
    const options = field.options ?? {};

    if (Array.isArray(options)) {
        return options.map((option) =>
            option !== null && typeof option === 'object'
                ? { value: option.key, label: option.value ?? String(option.key) }
                : { value: option, label: String(option) },
        );
    }

    return Object.entries(options).map(([value, label]) => ({ value, label: label ?? value }));
};

const toggleCheckboxValue = (handle, value) => {
    const current = new Set(form.customer[handle] ?? []);
    current.has(value) ? current.delete(value) : current.add(value);
    form.customer[handle] = [...current];
};

// --- Extras / options ---
const extraQuantity = (extra) => form.extras[extra.id] ?? 0;

const setExtraQuantity = (extra, quantity) => {
    const max = extra.maximum > 0 ? extra.maximum : Infinity;
    const next = Math.max(0, Math.min(quantity, max));
    if (next === 0) {
        delete form.extras[extra.id];
    } else {
        form.extras[extra.id] = next;
    }
};

const optionValueOptions = (option) => [
    { value: null, label: '—' },
    ...option.values.map((value) => ({
        value: value.id,
        label: value.price_type === 'free' ? value.name : `${value.name} (${money(value.price)})`,
    })),
];

// --- Submit ---
const submit = async () => {
    if (! canSubmit.value) return;
    submitting.value = true;
    storeError.value = null;
    errors.value = {};
    try {
        const payload = {
            ...quotePayload(),
            affects_availability: form.affects_availability,
            send_payment_request_email: form.send_payment_request_email,
            hold_days: form.hold_enabled ? form.hold_days : null,
            affiliate_id: form.affiliate_id,
            customer: form.customer,
        };
        const { data } = await axios.post(props.storeUrl, payload);
        toast.success(__('Reservation created'));
        window.location.href = data.redirect;
    } catch (error) {
        errors.value = error?.response?.data?.errors ?? {};
        storeError.value = error?.response?.data?.error
            ?? (Object.keys(errors.value).length ? __('Please review the highlighted fields.') : __('Something went wrong'));
    } finally {
        submitting.value = false;
    }
};

const fieldError = (key) => {
    const messages = errors.value[key];
    return messages ? messages.join(' ') : null;
};
</script>

<template>
    <div class="max-w-page mx-auto">
        <Head :title="__('Create reservation')" />

        <div class="flex pt-4">
            <a
                :href="backUrl"
                class="flex-initial flex p-2 -m-1 items-center text-xs text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-gray-100"
            >
                <Icon name="chevron-left" class="size-4" />
                <span>{{ __('Back') }}</span>
            </a>
        </div>

        <Header :title="__('Create reservation')" icon="calendar-date">
            <Button :href="backUrl" :text="__('Cancel')" />
            <Button
                variant="primary"
                :text="submitting ? __('Creating…') : __('Create reservation')"
                :disabled="! canSubmit"
                @click="submit"
            />
        </Header>

        <Alert v-if="storeError" variant="error" class="mb-6">{{ storeError }}</Alert>

        <!-- 1. Booking -->
        <Card class="p-6 mb-6">
            <div class="flex items-start justify-between gap-4 mb-6">
                <div class="space-y-1">
                    <Heading size="lg" :text="__('Booking')" />
                    <Description :text="__('Pick the entry, dates, and quantity for this reservation.')" />
                </div>
                <div v-if="quoting" class="flex items-center gap-2 text-gray-500 dark:text-gray-400 text-sm">
                    <Icon name="loading" class="size-4 animate-spin" />
                    <span>{{ __('Updating') }}</span>
                </div>
            </div>

            <div class="grid gap-x-4 gap-y-6 md:grid-cols-2">
                <Field :label="__('Entry')" :error="fieldError('item_id')">
                    <Combobox
                        v-model="form.item_id"
                        :options="entryOptions"
                        :placeholder="__('Search for an entry')"
                    >
                        <template #option="option">
                            <div class="flex items-center gap-2">
                                <span>{{ option.label }}</span>
                                <Badge size="sm" :text="option.collection" />
                            </div>
                        </template>
                    </Combobox>
                </Field>
            </div>

            <div v-if="form.item_id" class="grid gap-x-4 gap-y-6 md:grid-cols-12 mt-6">
                <Field
                    class="md:col-span-6"
                    :label="singleDate ? __('Date') : __('Dates')"
                    :error="dateError"
                >
                    <DatePicker
                        v-if="singleDate"
                        v-model="singleDateModel"
                        granularity="day"
                        :min="minPickerDate"
                        :clearable="false"
                    />
                    <DateRangePicker
                        v-else
                        v-model="dateRange"
                        granularity="day"
                        :min="minPickerDate"
                        :clearable="false"
                    />
                </Field>
                <Field
                    v-if="maximumQuantity > 1"
                    class="md:col-span-2"
                    :label="__('Quantity')"
                    :error="fieldError('quantity')"
                >
                    <Input v-model.number="form.quantity" type="number" :min="1" :max="maximumQuantity" />
                </Field>
                <Field
                    v-if="rates.length"
                    :class="maximumQuantity > 1 ? 'md:col-span-4' : 'md:col-span-6'"
                    :label="__('Rate')"
                    :error="fieldError('rate_id')"
                >
                    <Select v-model="form.rate_id" :options="rateOptions" :clearable="false" />
                </Field>
            </div>

            <template v-if="datesComplete">
                <Alert v-if="quoteError" variant="error" class="mt-6">{{ quoteError }}</Alert>
                <template v-else-if="quote">
                    <Alert v-if="availabilityBlocks" variant="error" class="mt-6">
                        {{ __('Not enough availability for these dates') }} —
                        {{ __('available') }}: {{ quote.availability.available }}.
                        {{ __('Turn off "Decrease availability" to overbook deliberately.') }}
                    </Alert>
                    <Alert v-else-if="willOverbook" variant="warning" class="mt-6">
                        {{ __('This reservation will overbook') }} —
                        {{ __('available') }}: {{ quote.availability.available }},
                        {{ __('requested') }}: {{ form.quantity }}.
                    </Alert>
                    <Alert v-else variant="success" class="mt-6">
                        {{ __('Available') }} ({{ quote.availability.available }} {{ __('left') }})
                    </Alert>
                </template>
            </template>
        </Card>

        <template v-if="datesComplete">
            <!-- 2. Extras & options -->
            <Card v-if="availableExtras.length || availableOptions.length" class="p-6 mb-6">
                <div class="space-y-1 mb-6">
                    <Heading size="lg" :text="__('Extras & options')" />
                    <Description :text="__('Add-ons and choices for this stay, priced for the selected dates.')" />
                </div>
                <div v-if="availableExtras.length" class="divide-y divide-gray-200 dark:divide-gray-800">
                    <div
                        v-for="extra in availableExtras"
                        :key="`extra-${extra.id}`"
                        class="flex items-center justify-between gap-4 py-3 first:pt-0 last:pb-0"
                    >
                        <div class="flex items-center gap-3">
                            <span class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ extra.name }}</span>
                            <Badge size="sm" :text="money(extra.price)" />
                        </div>
                        <div class="flex items-center gap-2">
                            <Button
                                size="sm"
                                text="−"
                                :disabled="extraQuantity(extra) === 0"
                                @click="setExtraQuantity(extra, extraQuantity(extra) - 1)"
                            />
                            <span class="w-8 text-center text-sm tabular-nums">{{ extraQuantity(extra) }}</span>
                            <Button
                                size="sm"
                                text="+"
                                @click="setExtraQuantity(extra, extra.allow_multiple ? extraQuantity(extra) + 1 : 1)"
                            />
                        </div>
                    </div>
                </div>
                <Separator v-if="availableExtras.length && availableOptions.length" class="my-6" />
                <div v-if="availableOptions.length" class="grid gap-x-4 gap-y-6 md:grid-cols-2">
                    <Field
                        v-for="option in availableOptions"
                        :key="`option-${option.id}`"
                        :label="option.name"
                        :required="Boolean(option.required)"
                    >
                        <Select
                            :model-value="form.options[option.id] ?? null"
                            :options="optionValueOptions(option)"
                            @update:model-value="(value) => (form.options[option.id] = value)"
                        />
                    </Field>
                </div>
            </Card>

            <!-- 3. Customer -->
            <Card v-if="formFields.length" class="p-6 mb-6">
                <div class="space-y-1 mb-6">
                    <Heading size="lg" :text="__('Customer')" />
                    <Description :text="__('The checkout form for this entry — manual reservations look exactly like frontend ones.')" />
                </div>
                <div class="grid gap-x-4 gap-y-6 md:grid-cols-2">
                    <Field
                        v-for="field in formFields"
                        :key="field.handle"
                        :label="field.display"
                        :required="fieldIsRequired(field)"
                        :error="fieldError(`customer.${field.handle}`)"
                    >
                        <Textarea v-if="fieldComponentType(field) === 'textarea'" v-model="form.customer[field.handle]" />
                        <Select
                            v-else-if="fieldComponentType(field) === 'select'"
                            v-model="form.customer[field.handle]"
                            :options="fieldSelectOptions(field)"
                        />
                        <div v-else-if="fieldComponentType(field) === 'checkboxes'" class="space-y-1">
                            <label
                                v-for="opt in fieldSelectOptions(field)"
                                :key="opt.value"
                                class="flex items-center gap-2"
                            >
                                <input
                                    type="checkbox"
                                    :checked="(form.customer[field.handle] ?? []).includes(opt.value)"
                                    @change="toggleCheckboxValue(field.handle, opt.value)"
                                />
                                <span>{{ opt.label }}</span>
                            </label>
                        </div>
                        <div v-else-if="fieldComponentType(field) === 'radio'" class="space-y-1">
                            <label
                                v-for="opt in fieldSelectOptions(field)"
                                :key="opt.value"
                                class="flex items-center gap-2"
                            >
                                <input
                                    type="radio"
                                    :name="`customer-${field.handle}`"
                                    :value="opt.value"
                                    :checked="form.customer[field.handle] === opt.value"
                                    @change="form.customer[field.handle] = opt.value"
                                />
                                <span>{{ opt.label }}</span>
                            </label>
                        </div>
                        <Switch
                            v-else-if="fieldComponentType(field) === 'toggle'"
                            v-model="form.customer[field.handle]"
                        />
                        <Input
                            v-else-if="fieldComponentType(field) === 'integer'"
                            v-model="form.customer[field.handle]"
                            type="number"
                        />
                        <Input
                            v-else-if="fieldComponentType(field) === 'dictionary' && field.phone_dictionary"
                            v-model="form.customer[field.handle]"
                            type="tel"
                        />
                        <Combobox
                            v-else-if="fieldComponentType(field) === 'dictionary'"
                            v-model="form.customer[field.handle]"
                            :options="field.dictionary_items ?? []"
                            :placeholder="__('Please select')"
                        />
                        <Input v-else v-model="form.customer[field.handle]" type="text" />
                    </Field>
                </div>
            </Card>

            <!-- 4. Pricing -->
            <Card v-if="quote" class="p-6 mb-6">
                <div class="space-y-1 mb-6">
                    <Heading size="lg" :text="__('Pricing')" />
                    <Description :text="__('Computed server-side, exactly like a frontend checkout.')" />
                </div>
                <div class="space-y-2 mb-6">
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-gray-600 dark:text-gray-400">{{ __('Base price') }}</span>
                        <span class="tabular-nums">{{ money(quote.pricing.base_price) }}</span>
                    </div>
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-gray-600 dark:text-gray-400">{{ __('Extras') }}</span>
                        <span class="tabular-nums">{{ money(quote.pricing.extras_total) }}</span>
                    </div>
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-gray-600 dark:text-gray-400">{{ __('Options') }}</span>
                        <span class="tabular-nums">{{ money(quote.pricing.options_total) }}</span>
                    </div>
                    <Separator class="my-3" />
                    <div class="flex items-center justify-between">
                        <Heading :text="__('Total')" />
                        <Heading size="lg" class="tabular-nums" :text="money(quote.pricing.total)" />
                    </div>
                </div>
                <div class="grid gap-x-4 gap-y-6 md:grid-cols-2">
                    <Field :label="__('Override total')" :instructions="__('Replaces the computed total; the base price is recalculated from it. Dynamic pricing rules are not recorded for overridden totals.')">
                        <Switch v-model="form.override_enabled" />
                    </Field>
                    <Field v-if="form.override_enabled" :label="__('Total to charge')" :error="fieldError('total_override')">
                        <Input v-model="form.total_override" type="number" step="0.01" :min="0" :prepend="currencySymbol" />
                    </Field>
                </div>
            </Card>

            <!-- 5. Payment -->
            <Card v-if="quote" class="p-6 mb-6">
                <div class="space-y-1 mb-6">
                    <Heading size="lg" :text="__('Payment')" />
                    <Description :text="__('How much to request now, and how the customer will pay.')" />
                </div>

                <Alert v-if="paymentAmountIsZero" variant="success" class="mb-6">
                    {{ __('This booking collects no payment, so it will be confirmed immediately — no payment method is required.') }}
                </Alert>
                <Alert v-else-if="! paymentEntryConfigured" variant="warning" class="mb-6">
                    {{ __('No payment page entry is configured in the Resrv settings, so online payment methods are disabled. Offline methods can still be confirmed manually.') }}
                </Alert>

                <div class="grid gap-x-4 gap-y-6 md:grid-cols-2">
                    <Field :label="__('Amount to request')" :error="fieldError('payment_mode')">
                        <Select v-model="form.payment_mode" :options="paymentModeOptions" :clearable="false" />
                    </Field>
                    <Field
                        v-if="form.payment_mode === 'custom'"
                        :label="__('Custom amount')"
                        :instructions="__('How much to collect now — a deposit or partial payment. To request more than the total, raise it with the \'Override total\' toggle above.')"
                        :error="customAmountError ?? fieldError('custom_amount')"
                    >
                        <Input v-model="form.custom_amount" type="number" step="0.01" :min="0" :prepend="currencySymbol" />
                    </Field>
                    <Field :label="__('Payment method')" :error="fieldError('payment_gateway')">
                        <Select v-model="form.payment_gateway" :options="gatewayOptions" :placeholder="__('Select a payment method')" />
                    </Field>
                    <Field v-if="affiliateOptions.length" :label="__('Affiliate')" :error="fieldError('affiliate_id')">
                        <Select v-model="form.affiliate_id" :options="affiliateOptions" clearable :placeholder="__('None')" />
                    </Field>
                </div>

                <div
                    v-if="selectedGatewayAmount && ! customAmountError"
                    class="mt-6 flex items-center justify-between gap-4 rounded-lg bg-gray-100 dark:bg-gray-800 px-4 py-3"
                >
                    <Description :text="__('The customer will be asked to pay')" />
                    <div class="text-right">
                        <Heading size="lg" class="tabular-nums" :text="money(selectedGatewayAmount.amount_with_surcharge)" />
                        <Description
                            v-if="selectedGatewayAmount.surcharge !== '0.00'"
                            :text="`${__('includes surcharge')} ${money(selectedGatewayAmount.surcharge)}`"
                        />
                    </div>
                </div>

                <Separator class="my-6" />

                <div class="grid gap-x-4 gap-y-6 md:grid-cols-2">
                    <Field :label="__('Send payment request email')">
                        <Switch v-model="form.send_payment_request_email" />
                    </Field>
                    <Field :label="__('Decrease availability')" :instructions="__('Turn off to create the reservation without taking stock — it will never restore stock either.')">
                        <Switch v-model="form.affects_availability" />
                    </Field>
                    <Field :label="__('Hold for a limited time')" :instructions="__('Automatically cancel the reservation if it stays unpaid past the deadline.')">
                        <Switch v-model="form.hold_enabled" />
                    </Field>
                    <Field v-if="form.hold_enabled" :label="__('Hold for (days)')" :error="fieldError('hold_days')">
                        <Input v-model.number="form.hold_days" type="number" :min="1" />
                    </Field>
                </div>
            </Card>

            <!-- 6. Submit -->
            <div class="flex justify-end gap-3 mb-12">
                <Button :href="backUrl" :text="__('Cancel')" />
                <Button
                    variant="primary"
                    :text="submitting ? __('Creating…') : __('Create reservation')"
                    :disabled="! canSubmit"
                    @click="submit"
                />
            </div>
        </template>
    </div>
</template>
