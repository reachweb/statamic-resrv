<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import axios from 'axios';
import { Head } from '@statamic/cms/inertia';
import {
    Alert,
    Button,
    Card,
    Field,
    Icon,
    Input,
    Select,
    Switch,
    Textarea,
} from '@statamic/cms/ui';
import { useToast } from '../../composables/useToast.js';

const props = defineProps({
    entriesUrl: { type: String, required: true },
    entryUrlTemplate: { type: String, required: true },
    quoteUrl: { type: String, required: true },
    storeUrl: { type: String, required: true },
    backUrl: { type: String, required: true },
    currencySymbol: { type: String, default: '' },
    maximumQuantity: { type: Number, default: 1 },
    gateways: { type: Array, default: () => [] },
    paymentEntryConfigured: { type: Boolean, default: false },
    affiliates: { type: Array, default: null },
    paymentConfig: { type: Object, default: () => ({}) },
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
    entries.value.map((entry) => ({ value: entry.item_id, label: `${entry.title} (${entry.collection})` })),
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

const canSubmit = computed(
    () => quote.value && ! availabilityBlocks.value && form.payment_gateway && ! submitting.value,
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

watch(() => form.item_id, async (itemId) => {
    rates.value = [];
    formFields.value = [];
    form.rate_id = null;
    form.extras = {};
    form.options = {};
    quote.value = null;
    if (! itemId) return;

    try {
        const { data } = await axios.get(props.entryUrlTemplate.replace('ITEMID', itemId));
        rates.value = data.rates;
        formFields.value = data.form_fields;
        form.rate_id = data.rates[0]?.id ?? null;
        form.customer = Object.fromEntries(
            data.form_fields.map((field) => [field.handle, field.type === 'checkboxes' ? [] : '']),
        );
        requestQuote();
    } catch (error) {
        toast.error(__('Could not load the entry data'));
    }
});

// --- Quoting (server-side money math; debounced on input changes) ---
let quoteTimer = null;

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
    custom_amount: form.payment_mode === 'custom' && form.custom_amount !== '' ? form.custom_amount : null,
    payment_gateway: form.payment_gateway,
});

const fetchQuote = async () => {
    if (! datesComplete.value) return;
    quoting.value = true;
    quoteError.value = null;
    try {
        const { data } = await axios.post(props.quoteUrl, quotePayload());
        quote.value = data;
        availableExtras.value = data.available_extras ?? [];
        availableOptions.value = data.available_options ?? [];
    } catch (error) {
        quote.value = null;
        quoteError.value = error?.response?.data?.error
            ?? Object.values(error?.response?.data?.errors ?? {}).flat().join(' ')
            ?? __('Could not compute a quote');
    } finally {
        quoting.value = false;
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

// --- Customer field renderer helpers ---
const knownFieldTypes = ['text', 'textarea', 'select', 'checkboxes'];

const fieldComponentType = (field) => {
    if (knownFieldTypes.includes(field.type)) return field.type;
    console.warn(`[Resrv] Unknown checkout form field type [${field.type}] — rendering as text.`);
    return 'text';
};

const fieldIsRequired = (field) => (field.validate ?? []).some((rule) => String(rule).startsWith('required'));

const fieldSelectOptions = (field) =>
    Object.entries(field.options ?? {}).map(([value, label]) => ({ value, label: label ?? value }));

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

        <div class="flex">
            <a
                :href="backUrl"
                class="flex-initial flex p-2 -m-1 items-center text-xs text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-gray-100"
            >
                <Icon name="chevron-left" class="size-4" />
                <span>{{ __('Back') }}</span>
            </a>
        </div>

        <header class="mt-1 mb-6">
            <h1>{{ __('Create reservation') }}</h1>
        </header>

        <!-- 1. Entry -->
        <Card class="px-6 py-4 mb-6">
            <Field :label="__('Entry')" :error="fieldError('item_id')">
                <Select v-model="form.item_id" :options="entryOptions" searchable :placeholder="__('Select an entry')" />
            </Field>
        </Card>

        <!-- 2. Dates, quantity & rate -->
        <Card v-if="form.item_id" class="px-6 py-4 mb-6">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-x-4 gap-y-6">
                <Field :label="__('Start date')" :error="fieldError('date_start')">
                    <Input v-model="form.date_start" type="date" />
                </Field>
                <Field :label="__('End date')" :error="fieldError('date_end')">
                    <Input v-model="form.date_end" type="date" />
                </Field>
                <Field v-if="maximumQuantity > 1" :label="__('Quantity')" :error="fieldError('quantity')">
                    <Input v-model.number="form.quantity" type="number" :min="1" :max="maximumQuantity" />
                </Field>
                <Field v-if="rates.length" :label="__('Rate')" :error="fieldError('rate_id')">
                    <Select v-model="form.rate_id" :options="rateOptions" :clearable="false" />
                </Field>
            </div>
        </Card>

        <template v-if="datesComplete">
            <!-- 3. Availability -->
            <Alert v-if="quoteError" variant="error" class="mb-6">{{ quoteError }}</Alert>
            <template v-else-if="quote">
                <Alert v-if="availabilityBlocks" variant="error" class="mb-6">
                    {{ __('Not enough availability for these dates') }} —
                    {{ __('available') }}: {{ quote.availability.available }}.
                    {{ __('Turn off "Decrease availability" to overbook deliberately.') }}
                </Alert>
                <Alert v-else-if="willOverbook" variant="warning" class="mb-6">
                    {{ __('This reservation will overbook') }} —
                    {{ __('available') }}: {{ quote.availability.available }},
                    {{ __('requested') }}: {{ form.quantity }}.
                </Alert>
                <Alert v-else variant="success" class="mb-6">
                    {{ __('Available') }} ({{ quote.availability.available }} {{ __('left') }})
                </Alert>
            </template>

            <!-- 4. Extras & options -->
            <Card v-if="availableExtras.length || availableOptions.length" class="px-6 py-4 mb-6">
                <h2 class="text-base mb-4">{{ __('Extras & options') }}</h2>
                <div v-if="availableExtras.length" class="space-y-3 mb-4">
                    <div
                        v-for="extra in availableExtras"
                        :key="`extra-${extra.id}`"
                        class="flex items-center justify-between gap-4"
                    >
                        <div>
                            <span class="font-medium">{{ extra.name }}</span>
                            <span class="text-gray-600 dark:text-gray-400 ml-2">{{ money(extra.price) }}</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <Button size="xs" text="-" @click="setExtraQuantity(extra, extraQuantity(extra) - 1)" />
                            <span class="w-8 text-center">{{ extraQuantity(extra) }}</span>
                            <Button
                                size="xs"
                                text="+"
                                @click="setExtraQuantity(extra, extra.allow_multiple ? extraQuantity(extra) + 1 : 1)"
                            />
                        </div>
                    </div>
                </div>
                <div v-if="availableOptions.length" class="grid grid-cols-1 md:grid-cols-2 gap-x-4 gap-y-6">
                    <Field
                        v-for="option in availableOptions"
                        :key="`option-${option.id}`"
                        :label="option.required ? `${option.name} *` : option.name"
                    >
                        <Select
                            :model-value="form.options[option.id] ?? null"
                            :options="optionValueOptions(option)"
                            @update:model-value="(value) => (form.options[option.id] = value)"
                        />
                    </Field>
                </div>
            </Card>

            <!-- 5. Customer -->
            <Card v-if="formFields.length" class="px-6 py-4 mb-6">
                <h2 class="text-base mb-4">{{ __('Customer') }}</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-4 gap-y-6">
                    <Field
                        v-for="field in formFields"
                        :key="field.handle"
                        :label="fieldIsRequired(field) ? `${field.display} *` : field.display"
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
                        <Input v-else v-model="form.customer[field.handle]" type="text" />
                    </Field>
                </div>
            </Card>

            <!-- 6. Pricing -->
            <Card v-if="quote" class="px-6 py-4 mb-6">
                <h2 class="text-base mb-4">{{ __('Pricing') }}</h2>
                <div class="space-y-1 mb-4">
                    <div class="flex justify-between">
                        <span>{{ __('Base price') }}</span>
                        <span>{{ money(quote.pricing.base_price) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span>{{ __('Extras') }}</span>
                        <span>{{ money(quote.pricing.extras_total) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span>{{ __('Options') }}</span>
                        <span>{{ money(quote.pricing.options_total) }}</span>
                    </div>
                    <div class="flex justify-between font-bold border-t pt-1">
                        <span>{{ __('Total') }}</span>
                        <span>{{ money(quote.pricing.total) }}</span>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-4 gap-y-6">
                    <Field :label="__('Override total')" :instructions="__('Replaces the computed total; the base price is recalculated from it. Dynamic pricing rules are not recorded for overridden totals.')">
                        <Switch v-model="form.override_enabled" />
                    </Field>
                    <Field v-if="form.override_enabled" :label="__('Total to charge')" :error="fieldError('total_override')">
                        <Input v-model="form.total_override" type="number" step="0.01" :min="0" />
                    </Field>
                </div>
            </Card>

            <!-- 7. Payment -->
            <Card v-if="quote" class="px-6 py-4 mb-6">
                <h2 class="text-base mb-4">{{ __('Payment') }}</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-4 gap-y-6">
                    <Field :label="__('Amount to request')" :error="fieldError('payment_mode')">
                        <Select v-model="form.payment_mode" :options="paymentModeOptions" :clearable="false" />
                    </Field>
                    <Field
                        v-if="form.payment_mode === 'custom'"
                        :label="__('Custom amount')"
                        :error="fieldError('custom_amount')"
                    >
                        <Input v-model="form.custom_amount" type="number" step="0.01" :min="0" />
                    </Field>
                    <Field :label="__('Payment method')" :error="fieldError('payment_gateway')">
                        <Select v-model="form.payment_gateway" :options="gatewayOptions" :placeholder="__('Select a payment method')" />
                    </Field>
                    <Field v-if="affiliateOptions.length" :label="__('Affiliate')" :error="fieldError('affiliate_id')">
                        <Select v-model="form.affiliate_id" :options="affiliateOptions" :placeholder="__('None')" />
                    </Field>
                </div>
                <Alert v-if="! paymentEntryConfigured" variant="warning" class="mt-4">
                    {{ __('No payment page entry is configured in the Resrv settings, so online payment methods are disabled. Offline methods can still be confirmed manually.') }}
                </Alert>
                <div v-if="selectedGatewayAmount" class="mt-4 font-medium">
                    {{ __('The customer will be asked to pay') }}
                    {{ money(selectedGatewayAmount.amount_with_surcharge) }}
                    <span v-if="selectedGatewayAmount.surcharge !== '0.00'" class="text-gray-600 dark:text-gray-400">
                        ({{ __('includes surcharge') }} {{ money(selectedGatewayAmount.surcharge) }})
                    </span>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-4 gap-y-6 mt-6">
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

            <!-- 8. Submit -->
            <Alert v-if="storeError" variant="error" class="mb-6">{{ storeError }}</Alert>
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
