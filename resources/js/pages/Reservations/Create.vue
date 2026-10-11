<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import axios from 'axios';
import { Head, router } from '@statamic/cms/inertia';
import { dateFormatter, dirty, toast as cpToast } from '@statamic/cms/api';
import {
    Alert,
    Badge,
    Button,
    Card,
    Combobox,
    DatePicker,
    DateRangePicker,
    Description,
    ErrorMessage,
    Field,
    Header,
    Icon,
    Input,
    Label,
    Panel,
    PanelHeader,
    Radio,
    RadioGroup,
    Select,
    Separator,
    Switch,
} from '@statamic/cms/ui';
import { today, now, getLocalTimeZone } from '@internationalized/date';
import { useToast } from '../../composables/useToast.js';
import { formatInstant } from '../../composables/useReservationDisplay.js';
import { useDateRangeModel, toCalendarDate, toIsoString } from '../../composables/useDateRangeModel.js';
import CustomerFields from '../../components/manual-reservation/CustomerFields.vue';
import { carriedFieldValue, fieldIsHidden } from '../../components/manual-reservation/customerForm.js';
import { vAccessibleName } from '../../components/manual-reservation/accessibleName.js';

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

const DIRTY_KEY = 'resrv-create-reservation';
const MAX_HOLD_DAYS = 365;

// randomUUID needs a secure context; getRandomValues does not.
const newIdempotencyKey = () => {
    if (typeof crypto.randomUUID === 'function') return crypto.randomUUID();
    return Array.from(crypto.getRandomValues(new Uint8Array(16)), (byte) => byte.toString(16).padStart(2, '0')).join('');
};

// One key per form session: a retried or double-sent store returns the first reservation.
let idempotencyKey = newIdempotencyKey();

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
const entryEditUrl = ref(null);
const quote = ref(null);
const availableExtras = ref([]);
const availableOptions = ref([]);
// Non-null whenever the last quote failed: gates submission and marks the figures out of date.
const quoteError = ref(null);
// The part of a failed quote that no visible field can show (domain errors, hidden inputs).
const quoteAlert = ref(null);
const quoteFieldErrors = ref({});
// The failed submit's message for what no field shows: {text, variant, code?, redirect?, expected?, persistent?}.
// Persistent ones describe a reservation that may exist, so editing the form must not hide them.
const storeAlert = ref(null);
const errors = ref({});
// Form state at the last failed submit: its errors stay until the inputs they depend on change.
let failedSubmit = null;
const quoting = ref(false);
// True from a pricing-input change until the response for that exact input state lands;
// submission stays blocked so the admin cannot approve a stale total.
const quoteDirty = ref(false);
const submitting = ref(false);
// Set once the store succeeded: the reservation exists, so the button never re-enables.
const created = ref(false);
const advancedOpen = ref(false);
const pageRoot = ref(null);
const storeErrorAlert = ref(null);

// Non-breaking spaces keep the symbol, sign and amount on one line.
const money = (amount) => (props.currencySymbol ? `${props.currencySymbol}\u00a0${amount}` : String(amount));

// Signed server-formatted figure ("-20.00") rendered with a typographic sign, no float math.
const signedMoney = (amount) => {
    const value = String(amount).trim();
    const negative = value.startsWith('-');
    return `${negative ? '−' : '+'}\u00a0${money(value.replace(/^[-+]/, ''))}`;
};

const isZero = (amount) => Number(amount) === 0;

const formatDay = (iso) => {
    const date = toCalendarDate(iso);
    if (! date) return '';
    return dateFormatter.format(date.toDate('UTC'), { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' });
};

// --- Entry / rate pickers ---
const entryOptions = computed(() =>
    entries.value
        .map((entry) => ({
            value: entry.item_id,
            label: entry.title,
            collection: String(entry.collection_title ?? entry.collection ?? ''),
        }))
        .sort((a, b) => a.collection.localeCompare(b.collection) || String(a.label).localeCompare(String(b.label))),
);
const multipleCollections = computed(() => new Set(entries.value.map((entry) => entry.collection)).size > 1);
const rateOptions = computed(() => rates.value.map((rate) => ({ value: rate.id, label: rate.title })));
const affiliateOptions = computed(() =>
    (props.affiliates ?? []).map((affiliate) => ({ value: affiliate.id, label: `${affiliate.name} (${affiliate.code})` })),
);

const selectedEntryTitle = computed(() => entries.value.find((entry) => entry.item_id === form.item_id)?.title ?? null);
const selectedRateTitle = computed(() => rates.value.find((rate) => rate.id === form.rate_id)?.title ?? null);
const visibleCustomerHandles = computed(
    () => new Set(formFields.value.filter((field) => ! fieldIsHidden(field)).map((field) => field.handle)),
);

// --- Dates ---
// A maximum period of one day mirrors the frontend's single datepicker: books [date, date + 1 day].
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

const datesComplete = computed(() => Boolean(form.item_id && form.date_start && form.date_end));

const nights = computed(() => {
    const start = toCalendarDate(form.date_start);
    const end = toCalendarDate(form.date_end);
    return start && end ? end.compare(start) : null;
});

const stayLabel = computed(() => {
    if (! form.date_start || ! form.date_end) return null;
    if (singleDate.value) return formatDay(form.date_start);
    return __(':start to :end', { start: formatDay(form.date_start), end: formatDay(form.date_end) });
});

// --- Errors ---
const errorsFromResponse = (data) => {
    const bag = { ...(data?.errors ?? {}) };
    if (data?.error && data?.field) bag[data.field] = [data.error];
    return bag;
};

const fieldError = (key) => {
    const messages = quoteFieldErrors.value[key] ?? errors.value[key];
    return messages?.length ? messages.join(' ') : null;
};

// Every message under a key or its children (e.g. extras.0.quantity), quote errors first.
const sectionError = (prefix) => {
    const collect = (bag) =>
        Object.entries(bag)
            .filter(([key]) => key === prefix || key.startsWith(`${prefix}.`))
            .flatMap(([, messages]) => messages);
    const messages = [...new Set([...collect(quoteFieldErrors.value), ...collect(errors.value)])];
    return messages.length ? messages.join(' ') : null;
};

const dateError = computed(() => fieldError('dates') ?? fieldError('date_start') ?? fieldError('date_end'));

// Only a domain error about the nights themselves (no price, no stock, rate rules) points at the
// entry's calendar: past, inverted and over-long stays are date_start/date_end errors or exceed the known limit.
const datesHint = computed(() => {
    if (! entryEditUrl.value || ! fieldError('dates')) return '';
    if (nights.value === null || nights.value < 1 || nights.value > props.maximumReservationPeriod) return '';
    return __('Every night of the stay needs availability and a price on the entry. [Open the entry](:url) to check its availability calendar.', { url: entryEditUrl.value });
});

// Whether an error key has a rendered control that shows it; everything else goes to an alert.
const errorHasField = (key) => {
    const [root, ...rest] = key.split('.');
    switch (root) {
        case 'item_id':
            return true;
        case 'date_start':
        case 'date_end':
        case 'dates':
            return Boolean(form.item_id);
        case 'quantity':
            return Boolean(form.item_id) && props.maximumQuantity > 1;
        case 'rate_id':
            return rates.value.length > 0;
        case 'extras':
            return datesComplete.value && availableExtras.value.length > 0;
        case 'options':
            return datesComplete.value && availableOptions.value.length > 0;
        case 'total_override':
            return Boolean(form.item_id) && form.override_enabled;
        case 'affiliate_id':
            return Boolean(form.item_id) && affiliateOptions.value.length > 0;
        case 'custom_amount':
            return Boolean(quote.value) && ! amountChoiceHidden.value && form.payment_mode === 'custom';
        case 'payment_mode':
            return Boolean(quote.value) && ! amountChoiceHidden.value;
        case 'payment_gateway':
            return Boolean(quote.value) && ! paymentAmountIsZero.value;
        case 'hold_days':
            return Boolean(quote.value) && form.hold_enabled && ! paymentAmountIsZero.value;
        case 'customer':
            return rest.length ? visibleCustomerHandles.value.has(rest.join('.')) : formFields.value.length > 0;
        default:
            return false;
    }
};

const dropErrors = (predicate) => {
    const kept = Object.entries(errors.value).filter(([key]) => ! predicate(key));
    if (kept.length !== Object.keys(errors.value).length) errors.value = Object.fromEntries(kept);
};

// The inputs each submit-error root depends on. A re-quote never clears these errors: store-only
// checks (rate rules on an overbook, required add-ons) are not re-run by the quote.
const errorInputs = {
    item_id: () => [form.item_id],
    date_start: () => [form.date_start],
    date_end: () => [form.date_start, form.date_end],
    dates: () => [form.date_start, form.date_end, form.quantity, form.rate_id, form.affects_availability],
    quantity: () => [form.quantity],
    rate_id: () => [form.rate_id],
    extras: () => [form.extras, form.options, form.date_start, form.date_end, form.quantity, form.rate_id],
    options: () => [form.options, form.extras, form.date_start, form.date_end, form.quantity, form.rate_id],
    total_override: () => [form.override_enabled, form.total_override],
    custom_amount: () => [form.payment_mode, form.custom_amount],
    payment_mode: () => [form.payment_mode],
    payment_gateway: () => [form.payment_gateway],
    hold_days: () => [form.hold_enabled, form.hold_days],
    affiliate_id: () => [form.affiliate_id],
    customer: () => [form.customer],
};

const errorInputState = (key) => {
    const [root, ...rest] = key.split('.');
    // customer.<handle> errors are cleared per field as it is edited (setCustomerValue).
    if (root === 'customer' && rest.length) return null;
    return errorInputs[root] ? JSON.stringify(errorInputs[root]()) : null;
};

const unmappedMessages = (bag) =>
    Object.entries(bag)
        .filter(([key]) => ! errorHasField(key))
        .flatMap(([, messages]) => messages)
        .join(' ');

// Errors on controls inside the collapsed Advanced group must be visible.
const revealAdvancedFor = (bag) => {
    if (Object.keys(bag).some((key) => ['total_override', 'affiliate_id'].includes(key.split('.')[0]))) {
        advancedOpen.value = true;
    }
};

const revealFirstError = async () => {
    await nextTick();
    const root = pageRoot.value;
    if (! root) return;
    let target = null;
    try {
        target = root.querySelector('[data-ui-field-has-errors]:not(:has([data-ui-field-has-errors])), [data-resrv-error]');
    } catch (error) {
        target = root.querySelector('[data-ui-field-has-errors], [data-resrv-error]');
    }
    target ??= storeErrorAlert.value?.$el ?? null;
    if (! target) return;
    target.scrollIntoView({ behavior: 'smooth', block: 'center' });
    target.querySelector?.('input:not([type="hidden"]), textarea, button:not([disabled])')?.focus({ preventScroll: true });
};

// --- Quote-derived state ---
const quotePending = computed(() => datesComplete.value && (quoteDirty.value || quoting.value));
const quoteStale = computed(() => quoteError.value !== null);
// The last quote's figures, only while they still describe the form.
const freshQuote = computed(() => (quote.value && ! quoteStale.value ? quote.value : null));
// Half-picked dates keep the last quote on screen, but it no longer describes the stay.
const figuresOutdated = computed(() => Boolean(quote.value) && (quoteStale.value || ! datesComplete.value));
const figuresDimmed = computed(() => quotePending.value || figuresOutdated.value);

const availabilityBlocks = computed(
    () => Boolean(quote.value) && ! quote.value.availability.status && form.affects_availability,
);

const willOverbook = computed(
    () => Boolean(quote.value) && ! quote.value.availability.status && ! form.affects_availability,
);

const availabilityBadge = computed(() => {
    if (! freshQuote.value || ! datesComplete.value) return null;
    if (availabilityBlocks.value) return { color: 'red', text: __('Not available') };
    if (willOverbook.value) return { color: 'amber', text: __('Will overbook') };
    if (! form.affects_availability) return { color: 'default', text: __('Availability not decreased') };
    return { color: 'green', text: __(':count available', { count: quote.value.availability.available }) };
});

const selectedGatewayAmount = computed(() => {
    if (! quote.value || ! form.payment_gateway) return null;
    return quote.value.payment.gateways?.[form.payment_gateway] ?? null;
});

const selectedGatewayLabel = computed(
    () => props.gateways.find((gateway) => gateway.key === form.payment_gateway)?.label ?? null,
);

// Client-side check so the message lands under the Custom amount field instead of in the booking card.
const customAmountLocalError = computed(() => {
    if (form.payment_mode !== 'custom' || ! quote.value) return null;
    if (form.custom_amount === '' || form.custom_amount === null) return null;

    const amount = Number(form.custom_amount);

    if (Number.isNaN(amount) || amount <= 0) {
        return __('Enter an amount greater than zero.');
    }

    if (amount > Number(quote.value.pricing.total)) {
        return __('This is more than the total. To request more, raise the total with "Override total" under Advanced.');
    }

    return null;
});

// Plus what only the server can judge (e.g. an amount it cannot parse): the relaxed quote keeps
// pricing through a rejected amount and reports why.
const customAmountError = computed(
    () => customAmountLocalError.value ?? (form.payment_mode === 'custom' ? quote.value?.payment.custom_amount_error ?? null : null),
);

// The custom amount the quote is asked to price. A locally rejected amount is dropped (null) so
// the rest of the quote keeps refreshing — but that rejection is judged against the CURRENT
// quote's total, so raising the override can turn the amount valid with no input change,
// leaving a zero-payment quote on screen while submission would send the real amount. The
// watcher below re-quotes whenever this value flips, so the quote always prices what is sent.
// Only the LOCAL verdict gates it: the server's verdict comes from pricing this very value, so
// letting it drop the value would flip-flop between quotes.
const customAmountForQuote = computed(() =>
    form.payment_mode === 'custom' && form.custom_amount !== '' && ! customAmountLocalError.value ? form.custom_amount : null,
);

// The relaxed quote returns zero for an as-yet-unentered custom amount, but creation requires
// one — unless the total itself is zero, where an omitted amount is a comped booking (the
// server relaxes the same check).
const customAmountRequired = computed(() => ! (quote.value && isZero(quote.value.pricing.total)));

const customAmountMissing = computed(
    () =>
        form.payment_mode === 'custom' &&
        (form.custom_amount === '' || form.custom_amount === null) &&
        customAmountRequired.value,
);

// The relaxed quote also reports 0 for a blank or rejected custom amount; neither is a
// zero-payment booking. A real zero collects nothing and needs no gateway.
const paymentAmountIsZero = computed(
    () =>
        Boolean(quote.value) &&
        isZero(quote.value.payment.amount) &&
        ! customAmountMissing.value &&
        ! customAmountError.value,
);

const amountIsSettled = computed(() => Boolean(freshQuote.value) && ! customAmountMissing.value && ! customAmountError.value);

const totalIsZero = computed(() => Boolean(quote.value) && isZero(quote.value.pricing.total));

// A zero total collects nothing, so there is no amount to choose.
const amountChoiceHidden = computed(() => totalIsZero.value && paymentAmountIsZero.value);

const surchargeIsZero = (gatewayAmount) => gatewayAmount.surcharge_is_zero ?? isZero(gatewayAmount.surcharge);

const showSurcharge = computed(
    () => amountIsSettled.value && ! paymentAmountIsZero.value && selectedGatewayAmount.value !== null && ! surchargeIsZero(selectedGatewayAmount.value),
);

const balance = computed(() => {
    const value = freshQuote.value?.pricing.balance;
    return amountIsSettled.value && value !== undefined && value !== null && ! isZero(value) ? value : null;
});

// Mirrors assertGatewayIsUsable(): a selectable-but-unusable gateway would only bounce off the
// store request with a 422. Zero-amount bookings collect nothing, so no gateway is disabled.
const gatewayDisabledReason = (gateway) => {
    if (paymentAmountIsZero.value) return null;
    if (! gateway.supports_manual_confirmation && ! gateway.supports_webhooks) {
        return __('This method cannot confirm online payments, so it cannot collect this payment.');
    }
    if (! props.paymentEntryConfigured && ! gateway.supports_manual_confirmation) {
        return __('Online payment is unavailable because no payment page entry is configured in the Resrv settings.');
    }
    if (quote.value?.payment.gateways?.[gateway.key]?.available === false) {
        return __('The amount is outside the limits allowed for this method.');
    }
    return null;
};

const gatewayChoices = computed(() =>
    props.gateways.map((gateway) => {
        const reason = gatewayDisabledReason(gateway);
        const description = gateway.description
            ?? (gateway.supports_manual_confirmation
                ? __('Customer pays outside Resrv (e.g. bank transfer); you mark the reservation as paid.')
                : __('Customer pays online through the payment link; the reservation confirms automatically.'));
        return {
            value: gateway.key,
            label: gateway.label,
            description: reason ?? description,
            disabled: reason !== null,
        };
    }),
);

// Standard and full collapse into one choice when the checkout policy charges the whole total.
// An overridden total is not what online checkout would charge, so no comparison is offered then.
const amountChoices = computed(() => {
    const payment = freshQuote.value?.payment;
    const standard = payment?.standard_amount ?? (form.payment_mode === 'standard' ? payment?.amount : null) ?? null;
    const full = payment?.full_amount ?? freshQuote.value?.pricing.total ?? null;
    const totalOverridden = Boolean(freshQuote.value?.pricing.total_overridden);
    const choices = [];

    if (standard !== null && full !== null && standard === full) {
        choices.push({
            value: form.payment_mode === 'full' ? 'full' : 'standard',
            label: __('Full amount'),
            description: totalOverridden
                ? __('The whole total: :amount.', { amount: money(full) })
                : __('The whole total, :amount. Online checkout would charge the same.', { amount: money(full) }),
        });
    } else {
        let standardDescription;
        if (totalOverridden) {
            standardDescription = standard !== null
                ? __('Your checkout payment rule applied to the overridden total: :amount.', { amount: money(standard) })
                : __('Your checkout payment rule applied to the overridden total.');
        } else {
            standardDescription = standard !== null
                ? __('What online checkout would charge for this booking: :amount.', { amount: money(standard) })
                : __('What online checkout would charge for this booking.');
        }
        choices.push({
            value: 'standard',
            label: __('Checkout amount'),
            description: standardDescription,
        });
        choices.push({
            value: 'full',
            label: __('Full amount'),
            description: full !== null
                ? __('The whole total: :amount.', { amount: money(full) })
                : __('The whole total.'),
        });
    }

    choices.push({ value: 'custom', label: __('Custom amount'), description: __('A deposit or partial payment you choose.') });

    return choices;
});

// --- Pricing breakdown (server figures of the last good quote; dimmed while out of date) ---
const overridden = computed(() => Boolean(quote.value?.pricing.total_overridden));

const basePriceShown = computed(() => {
    const pricing = quote.value?.pricing;
    if (! pricing) return null;
    if (overridden.value) return pricing.computed_base_price ?? pricing.base_price;
    if (pricing.discount !== undefined && pricing.discount !== null && pricing.original_base_price !== null) {
        return pricing.original_base_price;
    }
    return pricing.base_price;
});

const discountRow = computed(() => {
    const pricing = quote.value?.pricing;
    if (! pricing || overridden.value || pricing.discount === undefined || pricing.discount === null || isZero(pricing.discount)) {
        return null;
    }
    const surcharge = String(pricing.discount).trim().startsWith('-');
    return {
        label: surcharge ? __('Dynamic pricing surcharge') : __('Dynamic pricing discount'),
        // discount = original − computed, so a positive discount lowers the price.
        value: surcharge ? signedMoney(String(pricing.discount).replace(/^-/, '')) : signedMoney(`-${pricing.discount}`),
    };
});

// Older payloads without `discount`: still show the pre-rule base, struck through.
const struckOriginalBase = computed(() => {
    const pricing = quote.value?.pricing;
    if (! pricing || overridden.value || pricing.discount !== undefined) return null;
    return pricing.original_base_price !== null && pricing.original_base_price !== undefined && pricing.original_base_price !== pricing.base_price
        ? pricing.original_base_price
        : null;
});

const extraQuantity = (extra) => form.extras[extra.id] ?? 0;

const selectedExtraLines = computed(() =>
    availableExtras.value
        .filter((extra) => extraQuantity(extra) > 0)
        .map((extra) => ({ id: extra.id, text: __(':name × :quantity', { name: extra.name, quantity: extraQuantity(extra) }) })),
);

const selectedOptionLines = computed(() =>
    availableOptions.value
        .map((option) => {
            const value = option.values.find((candidate) => candidate.id === form.options[option.id]);
            return value ? { id: option.id, text: __(':option: :value', { option: option.name, value: value.name }) } : null;
        })
        .filter(Boolean),
);

const showExtrasRow = computed(() => Boolean(quote.value) && (! isZero(quote.value.pricing.extras_total) || selectedExtraLines.value.length > 0));
const showOptionsRow = computed(() => Boolean(quote.value) && (! isZero(quote.value.pricing.options_total) || selectedOptionLines.value.length > 0));

const adjustment = computed(() => {
    const value = quote.value?.pricing.adjustment;
    return overridden.value && value !== undefined && value !== null && ! isZero(value) ? value : null;
});

const struckComputedTotal = computed(() => {
    const pricing = quote.value?.pricing;
    return overridden.value && pricing?.computed_total && pricing.computed_total !== pricing.total ? pricing.computed_total : null;
});

// --- Required add-ons (the quote's ExtraCondition verdict for this stay and selection) ---
const missingRequiredExtras = computed(() => availableExtras.value.filter((extra) => extra.required && ! (extraQuantity(extra) > 0)));
const missingRequiredOptions = computed(() => availableOptions.value.filter((option) => option.required && ! form.options[option.id]));

// --- Hold ---
const holdDays = computed(() => {
    const days = Number(form.hold_days);
    return form.hold_enabled && form.hold_days !== '' && Number.isInteger(days) && days >= 1 && days <= MAX_HOLD_DAYS ? days : null;
});

// With the switch on, a cleared field would reach the server as null ("no hold") and the
// reservation would silently get no deadline.
const holdDaysError = computed(() => {
    if (! form.hold_enabled || paymentAmountIsZero.value) return null;
    if (form.hold_days === '' || form.hold_days === null) return __('Enter the number of days.');
    if (holdDays.value === null) return __('Enter a whole number of days from 1 to :max.', { max: MAX_HOLD_DAYS });
    return null;
});

// Mirrors the creator's now()->addDays(hold_days); the server stamps it at submit time.
const holdDeadline = computed(() => (holdDays.value && ! paymentAmountIsZero.value ? now(getLocalTimeZone()).add({ days: holdDays.value }) : null));

const holdDeadlineLabel = computed(() => (holdDeadline.value ? formatInstant(holdDeadline.value.toDate().toISOString()) : null));

const holdDeadlineText = computed(() =>
    holdDeadlineLabel.value
        ? __('If it is still unpaid, it will be cancelled automatically around :date.', { date: holdDeadlineLabel.value })
        : '',
);

// Payment closes once the arrival day has passed, so a hold that outlives it never auto-cancels in time.
const holdEndsAfterArrival = computed(() => {
    const arrival = toCalendarDate(form.date_start);
    return holdDays.value !== null && arrival !== null && ! paymentAmountIsZero.value
        && today(getLocalTimeZone()).add({ days: holdDays.value }).compare(arrival) > 0;
});

// The quote prices a blank override as "no override", so the switch alone would silently do nothing.
const overrideTotalError = computed(() =>
    form.override_enabled && (form.total_override === '' || form.total_override === null) ? __('Enter the new total.') : null,
);

// --- Submission gate: one ordered list of blockers, so the button and its hint never drift apart ---
const submitBlockedReason = computed(() => {
    if (! form.item_id) return __('Choose an entry.');
    if (! form.date_start || ! form.date_end) return __('Choose the dates.');
    if (quoteDirty.value) return __('Updating the price…');
    if (quoteError.value) {
        return quoteAlert.value
            ? __('The price could not be calculated. See the error under Booking.')
            : __('Fix the highlighted fields to update the price.');
    }
    if (! quote.value) return __('Updating the price…');
    if (overrideTotalError.value) return __('Enter the new total under Advanced, or turn off "Override total".');
    if (availabilityBlocks.value) return __('Not enough availability. Use "Overbook anyway" to create it without decreasing availability.');
    if (missingRequiredExtras.value.length) {
        return __('Add the required extras: :extras.', { extras: missingRequiredExtras.value.map((extra) => extra.name).join(', ') });
    }
    if (missingRequiredOptions.value.length) {
        return __('Select a value for: :options.', { options: missingRequiredOptions.value.map((option) => option.name).join(', ') });
    }
    if (customAmountMissing.value) return __('Enter the custom amount to request.');
    if (customAmountError.value) return __('Correct the custom amount.');
    if (! paymentAmountIsZero.value && ! form.payment_gateway) return __('Select a payment method.');
    if (holdDaysError.value) return __('Enter how many days to hold the reservation.');
    return null;
});

const canSubmit = computed(() => submitBlockedReason.value === null && ! submitting.value && ! created.value);

// Submit errors still shown on a field: the summary points at them instead of repeating them.
const submitErrorsShown = computed(() => Object.keys(errors.value).some((key) => errorHasField(key)));

// Rendered as text in a slot (never as HTML): server messages can carry CP-entered names.
const storeAlertText = computed(() => {
    const alert = storeAlert.value;
    if (! alert) return null;
    if (alert.code !== 'price_changed') return alert.text;
    const current = freshQuote.value && ! quotePending.value ? quote.value : null;
    if (current && alert.expected && current.pricing.total !== alert.expected.total) {
        return __('The total changed from :old to :new since it was shown. Review the updated figures, then create again.', {
            old: money(alert.expected.total),
            new: money(current.pricing.total),
        });
    }
    if (current && alert.expected && current.payment.amount !== alert.expected.amount) {
        return __('The amount to request changed from :old to :new since it was shown. Review the updated figures, then create again.', {
            old: money(alert.expected.amount),
            new: money(current.payment.amount),
        });
    }
    return alert.text;
});

const submitLabel = computed(() => {
    if (created.value) return __('Opening the reservation…');
    if (submitting.value) return __('Creating…');
    if (! amountIsSettled.value) return __('Create reservation');
    if (paymentAmountIsZero.value) return __('Create and confirm');
    return form.send_payment_request_email ? __('Create and send payment request') : __('Create without emailing');
});

const submitOutcome = computed(() => {
    if (! amountIsSettled.value) return null;
    if (paymentAmountIsZero.value) {
        return form.send_payment_request_email
            ? __('Nothing to collect: the reservation is confirmed immediately and the customer is emailed a confirmation.')
            : __('Nothing to collect: the reservation is confirmed immediately. The customer is not emailed.');
    }
    const amount = money(selectedGatewayAmount.value?.amount_with_surcharge ?? quote.value.payment.amount);
    return form.send_payment_request_email
        ? __('The reservation waits for payment, and the customer is emailed a request to pay :amount.', { amount })
        : __('The reservation waits for payment of :amount. No email is sent to the customer.', { amount });
});

const sendEmailLabel = computed(() => (paymentAmountIsZero.value ? __('Send confirmation email') : __('Send payment request email')));
const sendEmailInstructions = computed(() =>
    paymentAmountIsZero.value
        ? __('Email the customer a reservation confirmation.')
        : __('Email the customer the amount due and how to pay it.'),
);

const advancedBadges = computed(() =>
    [
        overridden.value ? __('Total overridden') : null,
        ! form.affects_availability ? __('Availability not decreased') : null,
        form.affiliate_id ? __('Affiliate') : null,
    ].filter(Boolean),
);

// A new quote can disable the selected gateway (e.g. the amount moved outside its limits).
watch(gatewayChoices, (choices) => {
    if (choices.find((choice) => choice.value === form.payment_gateway)?.disabled) {
        form.payment_gateway = null;
    }
});

// The amount choice is hidden for a zero total; a leftover custom amount there could only be rejected.
watch(totalIsZero, (zero) => {
    if (zero && form.payment_mode === 'custom') form.payment_mode = 'standard';
});

// --- Data loading ---
onMounted(async () => {
    try {
        const { data } = await axios.get(props.entriesUrl);
        entries.value = data;
    } catch (error) {
        toast.error(__('Could not load the bookable entries'));
    }
});

// Entry-detail requests can resolve out of order like quotes: only the latest selection may write rates/fields/customer state.
let entrySequence = 0;

watch(() => form.item_id, async (itemId) => {
    const sequence = ++entrySequence;
    rates.value = [];
    formFields.value = [];
    entryEditUrl.value = null;
    form.rate_id = null;
    form.extras = {};
    form.options = {};
    quote.value = null;
    availableExtras.value = [];
    availableOptions.value = [];
    quoteError.value = null;
    quoteAlert.value = null;
    quoteFieldErrors.value = {};
    errors.value = {};
    storeAlert.value = null;
    failedSubmit = null;
    if (! itemId) return;

    try {
        const { data } = await axios.get(props.entryUrlTemplate.replace('ITEMID', itemId));
        if (sequence !== entrySequence) return;
        rates.value = data.rates;
        formFields.value = data.form_fields;
        entryEditUrl.value = data.edit_url ?? null;
        form.rate_id = data.rates[0]?.id ?? null;
        // Read after the await so values typed while loading survive; handles the new form lacks are dropped.
        form.customer = Object.fromEntries(
            data.form_fields.map((field) => [field.handle, carriedFieldValue(field, form.customer[field.handle])]),
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

// The sequence is claimed synchronously with the input change (not when the debounce fires)
// so an in-flight response for older inputs can never clear quoteDirty.
const requestQuote = () => {
    const sequence = ++quoteSequence;
    clearTimeout(quoteTimer);
    // No request is made without full dates, so nothing would ever clear the pending flags.
    if (! datesComplete.value) {
        quoteDirty.value = false;
        quoting.value = false;
        return;
    }
    quoteDirty.value = true;
    quoteTimer = setTimeout(() => fetchQuote(sequence), 400);
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
    custom_amount: customAmountForQuote.value,
    payment_gateway: form.payment_gateway,
    // Custom-priced extras multiply by a checkout-form field, so the quote must price with the same customer data creation will use.
    customer: form.customer,
});

const fetchQuote = async (sequence) => {
    // Only the latest request may write quote state — stale responses are discarded.
    if (sequence !== quoteSequence) return;
    if (! datesComplete.value) {
        quoteDirty.value = false;
        return;
    }
    quoting.value = true;
    quoteError.value = null;
    quoteAlert.value = null;
    quoteFieldErrors.value = {};
    try {
        const { data } = await axios.post(props.quoteUrl, quotePayload());
        if (sequence !== quoteSequence) return;
        quote.value = data;
        availableExtras.value = data.available_extras ?? [];
        availableOptions.value = data.available_options ?? [];
        // A store-time stock error is now explained (with its overbook action) by the Booking alert.
        if (! data.availability?.status && form.affects_availability) dropErrors((key) => key.split('.')[0] === 'dates');
    } catch (error) {
        if (sequence !== quoteSequence) return;
        // Keep the last good quote on screen (its inputs may be what needs fixing), mapped to fields where possible.
        const data = error?.response?.data ?? {};
        const bag = errorsFromResponse(data);
        quoteFieldErrors.value = bag;
        revealAdvancedFor(bag);
        // `||`, not `??`: an empty join is not a message.
        quoteAlert.value = (data.error && ! data.field ? data.error : null)
            || unmappedMessages(bag)
            || (Object.keys(bag).length ? null : __('Could not compute a quote'));
        quoteError.value = quoteAlert.value ?? __('Please review the highlighted fields.');
    } finally {
        if (sequence === quoteSequence) {
            quoting.value = false;
            quoteDirty.value = false;
        }
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

// Re-quote when a customer field driving a custom-priced extra changes, not on unrelated keystrokes.
// Serialized to a string so the quote's own availableExtras refresh cannot re-trigger it.
const customPriceFieldState = computed(() =>
    JSON.stringify(
        availableExtras.value
            .filter((extra) => extra.price_type === 'custom' && extra.custom)
            .map((extra) => [extra.custom, form.customer[extra.custom] ?? null]),
    ),
);

watch(customPriceFieldState, requestQuote);

// A total change can flip the custom amount between rejected and sendable without any form
// input changing (see customAmountForQuote) — the form watcher above cannot catch that.
watch(customAmountForQuote, requestQuote);

// --- Unsaved-changes guard (Statamic's dirty state covers Inertia links, unload and back/forward) ---
watch(() => form.item_id !== null, (isDirty) => {
    if (! created.value) dirty.state(DIRTY_KEY, isDirty);
}, { immediate: true });

onBeforeUnmount(() => dirty.remove(DIRTY_KEY));

// --- Failed-submit feedback: cleared as the admin edits what it is about ---
watch(() => JSON.stringify(form), (state) => {
    if (! failedSubmit || state === failedSubmit.form) return;
    dropErrors((key) => {
        const before = failedSubmit.inputs[key];
        return before !== undefined && before !== null && errorInputState(key) !== before;
    });
    if (storeAlert.value && ! storeAlert.value.persistent) storeAlert.value = null;
});

// --- Customer ---
const setCustomerValue = (handle, value) => {
    form.customer[handle] = value;
    const key = `customer.${handle}`;
    if (errors.value[key]) {
        const { [key]: _removed, ...rest } = errors.value;
        errors.value = rest;
    }
};

// --- Extras / options ---
const extraMax = (extra) => {
    if (! extra.allow_multiple) return 1;
    return Number(extra.maximum) > 0 ? Number(extra.maximum) : Infinity;
};

const setExtraQuantity = (extra, quantity) => {
    const next = Math.max(0, Math.min(quantity, extraMax(extra)));
    if (next === 0) {
        delete form.extras[extra.id];
    } else {
        form.extras[extra.id] = next;
    }
};

const extraPriceLabel = (extra) => (isZero(extra.price) ? __('Free') : money(extra.price));

const optionValueOptions = (option) =>
    option.values.map((value) => ({
        value: value.id,
        label: value.price_type === 'free' || isZero(value.price) ? value.name : `${value.name} (${money(value.price)})`,
    }));

// --- Submit ---
const announceCreated = (data) => {
    if (data.status === 'confirmed') {
        toast.success(__('Reservation created and confirmed.'));
    } else if (data.payment_request_sent === true) {
        toast.success(__('Reservation created. The payment request was emailed to the customer.'));
    } else if (data.payment_request_sent === false && form.send_payment_request_email) {
        toast.error(__('Reservation created, but the payment request email was not sent. You can send it from the reservation.'));
    } else {
        toast.success(__('Reservation created'));
    }
};

// Offered only once the admin was told a reservation already exists: a new key lets this form book again.
const startSeparateReservation = () => {
    idempotencyKey = newIdempotencyKey();
    storeAlert.value = null;
};

// Toasts render HTML, so they only ever carry static sentences; server text goes to the slot-rendered alert.
const showStoreFailure = (error, expected) => {
    const status = error?.response?.status ?? null;
    const body = error?.response?.data;
    const data = body && typeof body === 'object' ? body : {};
    let bag = {};

    if (status === null || status >= 500) {
        storeAlert.value = {
            variant: 'error',
            persistent: true,
            text: __('The reservation may have been created, but the server did not confirm it. Check the reservations list before trying again. Creating again from this page will not book it twice.'),
        };
        toast.error(__('The server did not confirm the reservation.'));
    } else if (status === 409) {
        storeAlert.value = {
            variant: 'warning',
            persistent: true,
            code: data.code ?? null,
            redirect: data.code === 'already_created' ? data.redirect ?? null : null,
            text: data.error ?? __('A reservation was already created from this form.'),
        };
    } else if (status === 422 && data.code === 'price_changed') {
        storeAlert.value = { variant: 'warning', code: 'price_changed', expected, text: data.error ?? __('The price changed since it was last shown.') };
    } else {
        bag = errorsFromResponse(data);
        revealAdvancedFor(bag);
        // `||`, not `??`: an empty join is not a message.
        const unmapped = (data.error && ! data.field ? data.error : null) || unmappedMessages(bag);
        const text = unmapped || (Object.keys(bag).length ? null : data.error ?? data.message ?? __('Something went wrong'));
        storeAlert.value = text ? { variant: 'error', text } : null;
        toast.error(__('The reservation could not be created.'));
    }

    errors.value = bag;
    failedSubmit = {
        form: JSON.stringify(form),
        inputs: Object.fromEntries(Object.keys(bag).map((key) => [key, errorInputState(key)])),
    };

    // Availability, prices or limits may have moved since the last quote.
    if (status === 422) requestQuote();
};

const submit = async () => {
    if (! canSubmit.value) return;
    // A replay of a lost earlier attempt ignores what was edited after that attempt failed.
    const editedSinceFailure = failedSubmit !== null && JSON.stringify(form) !== failedSubmit.form;
    submitting.value = true;
    storeAlert.value = null;
    errors.value = {};
    failedSubmit = null;

    // What the admin approved: the server refuses to store different figures.
    const expected = { total: quote.value.pricing.total, amount: quote.value.payment.amount };

    const payload = {
        ...quotePayload(),
        payment_gateway: paymentAmountIsZero.value ? null : form.payment_gateway,
        affects_availability: form.affects_availability,
        send_payment_request_email: form.send_payment_request_email,
        hold_enabled: form.hold_enabled && ! paymentAmountIsZero.value,
        hold_days: form.hold_enabled && ! paymentAmountIsZero.value ? holdDays.value : null,
        affiliate_id: form.affiliate_id,
        customer: form.customer,
        idempotency_key: idempotencyKey,
        expected_total: expected.total,
        expected_amount: expected.amount,
    };

    let response;
    try {
        response = await axios.post(props.storeUrl, payload);
    } catch (error) {
        submitting.value = false;
        showStoreFailure(error, expected);
        revealFirstError();
        return;
    }

    // `submitting` stays true: the button must not re-enable while the Show page loads.
    created.value = true;
    if (response.data?.replayed === true || response.status === 200) {
        // An earlier attempt (whose response was lost) already created it; this request changed nothing.
        cpToast.info(
            editedSinceFailure
                ? __('This reservation had already been created by an earlier attempt, so nothing new was created and the changes made since then were not applied.')
                : __('This reservation had already been created by an earlier attempt, so nothing new was created.'),
            { duration: 10000 },
        );
    } else {
        announceCreated(response.data);
    }
    // Clear synchronously: the dirty-state watcher only drops the guard on a later flush.
    dirty.remove(DIRTY_KEY);
    dirty.disableWarning();
    router.visit(response.data.redirect);
};
</script>

<template>
    <div ref="pageRoot" class="max-w-page mx-auto">
        <Head :title="__('Create reservation')" />

        <Header :title="__('Create reservation')" icon="calendar-date">
            <Button :href="backUrl" :text="__('Cancel')" />
            <Button
                variant="primary"
                :text="submitLabel"
                :disabled="! canSubmit"
                :loading="submitting"
                :title="submitBlockedReason ?? undefined"
                @click="submit"
            />
        </Header>

        <div class="@container/create">
            <div class="grid items-start gap-x-8 @4xl:grid-cols-[minmax(0,1fr)_340px]">
                <div class="min-w-0">
                    <!-- Booking -->
                    <Panel :heading="__('Booking')" :subheading="__('The entry, dates and quantity for this reservation.')">
                        <template #header-actions>
                            <span v-if="quotePending" class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400" role="status">
                                <Icon name="loading" class="size-4" />
                                <span>{{ __('Updating') }}</span>
                            </span>
                            <Badge v-else-if="availabilityBadge" :color="availabilityBadge.color" :text="availabilityBadge.text" />
                        </template>
                        <Card>
                            <div class="space-y-6">
                                <Field :error="fieldError('item_id')">
                                    <template #label>
                                        <Label id="resrv-create-entry-label" for="resrv-create-entry" :text="__('Entry')" />
                                    </template>
                                    <Combobox
                                        id="resrv-create-entry"
                                        v-model="form.item_id"
                                        v-accessible-name="{ labelId: 'resrv-create-entry-label', valueId: 'resrv-create-entry-value' }"
                                        :options="entryOptions"
                                        :placeholder="__('Search for an entry')"
                                    >
                                        <template #option="option">
                                            <div class="flex items-center gap-2">
                                                <span>{{ option.label }}</span>
                                                <Badge v-if="multipleCollections && option.collection" size="sm" :text="option.collection" />
                                            </div>
                                        </template>
                                        <template #selected-option="{ option }">
                                            <span id="resrv-create-entry-value" class="block truncate">{{ option.label }}</span>
                                        </template>
                                    </Combobox>
                                </Field>

                                <div v-if="form.item_id" class="grid gap-x-4 gap-y-6 @lg/panel:grid-cols-12">
                                    <Field
                                        class="@lg/panel:col-span-12 @2xl/panel:col-span-6"
                                        :error="dateError"
                                        :instructions="datesHint"
                                        instructions-below
                                    >
                                        <template #label>
                                            <Label id="resrv-create-dates-label" :text="singleDate ? __('Date') : __('Dates')" />
                                        </template>
                                        <DatePicker
                                            v-if="singleDate"
                                            v-model="singleDateModel"
                                            role="group"
                                            aria-labelledby="resrv-create-dates-label"
                                            granularity="day"
                                            :min="minPickerDate"
                                            :clearable="false"
                                        />
                                        <DateRangePicker
                                            v-else
                                            v-model="dateRange"
                                            v-accessible-name="{ openLabel: __('Open calendar'), clearLabel: __('Clear dates') }"
                                            role="group"
                                            aria-labelledby="resrv-create-dates-label"
                                            granularity="day"
                                            :min="minPickerDate"
                                            :maximum-days="maximumReservationPeriod + 1"
                                            :clearable="false"
                                        />
                                    </Field>
                                    <Field
                                        v-if="maximumQuantity > 1"
                                        id="resrv-create-quantity"
                                        class="@lg/panel:col-span-4 @2xl/panel:col-span-2"
                                        :label="__('Quantity')"
                                        :error="fieldError('quantity')"
                                    >
                                        <Input
                                            id="resrv-create-quantity"
                                            v-model.number="form.quantity"
                                            type="number"
                                            :min="1"
                                            :max="maximumQuantity"
                                        />
                                    </Field>
                                    <Field
                                        v-if="rates.length"
                                        :class="maximumQuantity > 1 ? '@lg/panel:col-span-8 @2xl/panel:col-span-4' : '@lg/panel:col-span-12 @2xl/panel:col-span-6'"
                                        :error="fieldError('rate_id')"
                                    >
                                        <template #label>
                                            <Label id="resrv-create-rate-label" :text="__('Rate')" />
                                        </template>
                                        <Select
                                            v-model="form.rate_id"
                                            v-accessible-name="{ labelId: 'resrv-create-rate-label', valueId: 'resrv-create-rate-value' }"
                                            :options="rateOptions"
                                            :clearable="false"
                                        >
                                            <template #selected-option="{ option }">
                                                <span id="resrv-create-rate-value" class="block truncate">{{ option.label }}</span>
                                            </template>
                                        </Select>
                                    </Field>
                                </div>

                                <template v-if="datesComplete">
                                    <Alert v-if="quoteAlert" variant="error" data-resrv-error>{{ quoteAlert }}</Alert>
                                    <template v-else-if="freshQuote">
                                        <Alert v-if="availabilityBlocks" variant="error">
                                            <div class="flex flex-wrap items-center justify-between gap-3">
                                                <span>
                                                    {{ __('Not enough availability for these dates: :available available, :requested requested.', { available: quote.availability.available, requested: form.quantity }) }}
                                                </span>
                                                <Button size="sm" :text="__('Overbook anyway')" @click="form.affects_availability = false" />
                                            </div>
                                        </Alert>
                                        <Alert v-else-if="willOverbook" variant="warning">
                                            <div class="flex flex-wrap items-center justify-between gap-3">
                                                <span>
                                                    {{ __('This reservation will overbook: :available available, :requested requested. Availability will not be decreased.', { available: quote.availability.available, requested: form.quantity }) }}
                                                </span>
                                                <Button size="sm" :text="__('Undo')" @click="form.affects_availability = true" />
                                            </div>
                                        </Alert>
                                    </template>
                                </template>
                            </div>
                        </Card>
                    </Panel>

                    <!-- Extras & options -->
                    <Panel
                        v-if="datesComplete && (availableExtras.length || availableOptions.length)"
                        :heading="__('Extras & options')"
                        :subheading="__('Add-ons and choices for this stay, priced for the selected dates.')"
                    >
                        <Card>
                            <div class="space-y-6">
                                <div v-if="availableExtras.length" class="space-y-3">
                                    <div class="divide-y divide-gray-200 dark:divide-gray-800">
                                        <div
                                            v-for="extra in availableExtras"
                                            :key="`extra-${extra.id}`"
                                            role="group"
                                            :aria-labelledby="`resrv-create-extra-${extra.id}`"
                                            class="flex items-start justify-between gap-4 py-3 first:pt-0 last:pb-0"
                                        >
                                            <div class="min-w-0 space-y-1">
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <span
                                                        :id="`resrv-create-extra-${extra.id}`"
                                                        class="text-sm font-medium text-gray-900 dark:text-gray-100"
                                                    >{{ extra.name }}</span>
                                                    <Badge
                                                        size="sm"
                                                        class="transition-opacity"
                                                        :class="{ 'opacity-50': figuresDimmed }"
                                                        :text="extraPriceLabel(extra)"
                                                    />
                                                    <Badge v-if="extra.required" size="sm" color="amber" :text="__('Required')" />
                                                </div>
                                                <Description v-if="extra.required && extra.required_reason">{{ extra.required_reason }}</Description>
                                                <Description v-if="extra.description">{{ extra.description }}</Description>
                                            </div>
                                            <div class="flex shrink-0 items-center gap-2">
                                                <Button
                                                    size="sm"
                                                    text="−"
                                                    :aria-label="__('Remove one :name', { name: extra.name })"
                                                    :disabled="extraQuantity(extra) === 0"
                                                    @click="setExtraQuantity(extra, extraQuantity(extra) - 1)"
                                                />
                                                <span class="w-8 text-center text-sm tabular-nums" aria-live="polite" aria-atomic="true">{{ extraQuantity(extra) }}</span>
                                                <Button
                                                    size="sm"
                                                    text="+"
                                                    :aria-label="__('Add one :name', { name: extra.name })"
                                                    :disabled="extraQuantity(extra) >= extraMax(extra)"
                                                    @click="setExtraQuantity(extra, extraQuantity(extra) + 1)"
                                                />
                                            </div>
                                        </div>
                                    </div>
                                    <ErrorMessage v-if="sectionError('extras')" data-resrv-error>{{ sectionError('extras') }}</ErrorMessage>
                                </div>

                                <Separator v-if="availableExtras.length && availableOptions.length" />

                                <div v-if="availableOptions.length" class="space-y-3">
                                    <div class="grid gap-x-4 gap-y-6 @lg/panel:grid-cols-2">
                                        <Field v-for="option in availableOptions" :key="`option-${option.id}`">
                                            <template #label>
                                                <Label :id="`resrv-create-option-${option.id}-label`" :text="option.name" :required="Boolean(option.required)" />
                                            </template>
                                            <Select
                                                v-accessible-name="{ labelId: `resrv-create-option-${option.id}-label`, valueId: `resrv-create-option-${option.id}-value` }"
                                                :model-value="form.options[option.id] ?? null"
                                                :options="optionValueOptions(option)"
                                                :clearable="! option.required"
                                                :placeholder="option.required ? __('Select...') : __('None')"
                                                @update:model-value="(value) => (form.options[option.id] = value)"
                                            >
                                                <template #selected-option="{ option: selected }">
                                                    <span :id="`resrv-create-option-${option.id}-value`" class="block truncate">{{ selected.label }}</span>
                                                </template>
                                            </Select>
                                        </Field>
                                    </div>
                                    <ErrorMessage v-if="sectionError('options')" data-resrv-error>{{ sectionError('options') }}</ErrorMessage>
                                </div>
                            </div>
                        </Card>
                    </Panel>

                    <!-- Customer -->
                    <Panel
                        v-if="formFields.length"
                        :heading="__('Customer')"
                        :subheading="__('Customer details, using this entry\'s checkout form.')"
                    >
                        <Card>
                            <div class="space-y-6">
                                <ErrorMessage v-if="fieldError('customer')" data-resrv-error>{{ fieldError('customer') }}</ErrorMessage>
                                <CustomerFields
                                    :fields="formFields"
                                    :values="form.customer"
                                    :error-for="(handle) => fieldError(`customer.${handle}`)"
                                    @update="setCustomerValue"
                                />
                            </div>
                        </Card>
                    </Panel>

                    <!-- Payment -->
                    <Panel v-if="quote" :heading="__('Payment')" :subheading="__('How much to request now, and how the customer pays.')">
                        <Card>
                            <div class="space-y-6">
                                <Alert v-if="paymentAmountIsZero && ! quoteStale" variant="success">
                                    {{ __('This booking collects no payment, so it will be confirmed immediately. No payment method is needed.') }}
                                </Alert>
                                <Alert v-else-if="! paymentEntryConfigured" variant="warning">
                                    {{ __('No payment page entry is configured in the Resrv settings, so online payment methods are disabled. Offline methods can still be confirmed manually.') }}
                                </Alert>

                                <div v-if="! amountChoiceHidden">
                                    <Field :label="__('Amount to request')" :error="fieldError('payment_mode')">
                                        <RadioGroup v-model="form.payment_mode" :aria-label="__('Amount to request')">
                                            <Radio
                                                v-for="choice in amountChoices"
                                                :key="choice.value"
                                                :value="choice.value"
                                                :label="choice.label"
                                                :description="choice.description"
                                            />
                                        </RadioGroup>
                                    </Field>
                                    <Field
                                        v-if="form.payment_mode === 'custom'"
                                        id="resrv-create-custom-amount"
                                        class="mt-4 ms-5.5 max-w-xs"
                                        :label="__('Custom amount')"
                                        :required="customAmountRequired"
                                        :error="customAmountError ?? fieldError('custom_amount')"
                                    >
                                        <Input
                                            id="resrv-create-custom-amount"
                                            v-model="form.custom_amount"
                                            type="number"
                                            step="0.01"
                                            :min="0"
                                            :prepend="currencySymbol"
                                            :loading="quoting"
                                        />
                                    </Field>
                                </div>

                                <Field
                                    v-if="! paymentAmountIsZero"
                                    :label="__('Payment method')"
                                    required
                                    :instructions="__('Cannot be changed after the reservation is created.')"
                                    :error="fieldError('payment_gateway')"
                                >
                                    <RadioGroup v-if="gatewayChoices.length" v-model="form.payment_gateway" :aria-label="__('Payment method')">
                                        <Radio
                                            v-for="choice in gatewayChoices"
                                            :key="choice.value"
                                            :value="choice.value"
                                            :label="choice.label"
                                            :description="choice.description"
                                            :disabled="choice.disabled"
                                        />
                                    </RadioGroup>
                                    <Description v-else>{{ __('No payment methods are configured, so only bookings that collect nothing can be created.') }}</Description>
                                </Field>

                                <Separator />

                                <Field id="resrv-create-send-email" :label="sendEmailLabel" :instructions="sendEmailInstructions">
                                    <Switch id="resrv-create-send-email" v-model="form.send_payment_request_email" :aria-label="sendEmailLabel" />
                                </Field>

                                <div v-if="! paymentAmountIsZero" class="space-y-4">
                                    <Field
                                        id="resrv-create-hold"
                                        :label="__('Hold for a limited time')"
                                        :instructions="__('Cancel the reservation automatically if it is still unpaid after the chosen number of days.')"
                                    >
                                        <Switch id="resrv-create-hold" v-model="form.hold_enabled" />
                                    </Field>
                                    <div v-if="form.hold_enabled" class="ms-5.5 space-y-4">
                                        <Field
                                            id="resrv-create-hold-days"
                                            class="max-w-xs"
                                            :label="__('Hold for')"
                                            required
                                            :error="holdDaysError ?? fieldError('hold_days')"
                                            :instructions="holdDeadlineText"
                                            instructions-below
                                        >
                                            <Input
                                                id="resrv-create-hold-days"
                                                v-model.number="form.hold_days"
                                                type="number"
                                                step="1"
                                                :min="1"
                                                :max="MAX_HOLD_DAYS"
                                                :append="__('days')"
                                            />
                                        </Field>
                                        <Alert v-if="holdEndsAfterArrival" variant="warning">
                                            {{ __('The hold ends after the arrival day (:date). The customer cannot pay once the arrival day has passed, so a shorter hold is usually better.', { date: formatDay(form.date_start) }) }}
                                        </Alert>
                                    </div>
                                </div>
                            </div>
                        </Card>
                    </Panel>

                    <!-- Advanced -->
                    <Panel v-if="form.item_id">
                        <PanelHeader>
                            <button
                                type="button"
                                class="flex w-full cursor-pointer items-center gap-2 py-2 text-start"
                                :aria-expanded="advancedOpen ? 'true' : 'false'"
                                aria-controls="resrv-create-advanced"
                                @click="advancedOpen = ! advancedOpen"
                            >
                                <Icon
                                    name="chevron-right"
                                    class="size-4 text-gray-500 transition-transform"
                                    :class="{ 'rotate-90': advancedOpen }"
                                />
                                <span class="text-sm font-medium tracking-tight text-gray-700 antialiased dark:text-white">{{ __('Advanced') }}</span>
                                <span v-if="advancedBadges.length" class="ms-auto me-3 flex flex-wrap justify-end gap-1.5">
                                    <Badge v-for="badge in advancedBadges" :key="badge" as="span" size="sm" color="amber" :text="badge" />
                                </span>
                            </button>
                        </PanelHeader>
                        <Card v-show="advancedOpen" id="resrv-create-advanced">
                            <div class="space-y-6">
                                <div class="grid gap-x-4 gap-y-6 @lg/panel:grid-cols-2">
                                    <Field
                                        id="resrv-create-override"
                                        :label="__('Override total')"
                                        :instructions="__('Replace the computed total. The difference is applied to the base price, and dynamic pricing is not recorded for overridden totals.')"
                                    >
                                        <Switch id="resrv-create-override" v-model="form.override_enabled" />
                                    </Field>
                                    <Field
                                        v-if="form.override_enabled"
                                        id="resrv-create-total-override"
                                        :label="__('New reservation total')"
                                        required
                                        :error="overrideTotalError ?? fieldError('total_override')"
                                    >
                                        <Input
                                            id="resrv-create-total-override"
                                            v-model="form.total_override"
                                            type="number"
                                            step="0.01"
                                            :min="0"
                                            :prepend="currencySymbol"
                                            :loading="quoting"
                                        />
                                    </Field>
                                </div>
                                <Field
                                    id="resrv-create-affects-availability"
                                    :label="__('Decrease availability')"
                                    :instructions="__('Turn off to create the reservation without taking stock. It will never restore stock either.')"
                                >
                                    <Switch id="resrv-create-affects-availability" v-model="form.affects_availability" />
                                </Field>
                                <Field
                                    v-if="affiliateOptions.length"
                                    :instructions="__('Credit this reservation to an affiliate.')"
                                    :error="fieldError('affiliate_id')"
                                >
                                    <template #label>
                                        <Label id="resrv-create-affiliate-label" :text="__('Affiliate')" />
                                    </template>
                                    <Select
                                        v-model="form.affiliate_id"
                                        v-accessible-name="{ labelId: 'resrv-create-affiliate-label', valueId: 'resrv-create-affiliate-value' }"
                                        :options="affiliateOptions"
                                        clearable
                                        :placeholder="__('None')"
                                    >
                                        <template #selected-option="{ option }">
                                            <span id="resrv-create-affiliate-value" class="block truncate">{{ option.label }}</span>
                                        </template>
                                    </Select>
                                </Field>
                            </div>
                        </Card>
                    </Panel>
                </div>

                <!-- Summary: capped to the viewport (below the CP header) so its actions stay reachable -->
                <aside class="min-w-0 @4xl:sticky @4xl:top-4 @4xl:max-h-[calc(100dvh-6.5rem)] @4xl:overflow-y-auto @4xl:overscroll-contain">
                    <Panel :heading="__('Summary')" class="@4xl/create:mb-0!">
                        <template #header-actions>
                            <span v-if="quotePending" class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                                <Icon name="loading" class="size-4" />
                                <span>{{ __('Updating') }}</span>
                            </span>
                            <Badge v-else-if="figuresOutdated" size="sm" color="amber" :text="__('Out of date')" />
                        </template>
                        <Card>
                            <div class="space-y-5">
                                <Description v-if="! form.item_id">{{ __('Choose an entry and dates to see the price.') }}</Description>
                                <div v-else class="space-y-1.5">
                                    <div class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ selectedEntryTitle }}</div>
                                    <div v-if="stayLabel" class="text-sm text-gray-600 dark:text-gray-400">
                                        {{ stayLabel }}
                                        <template v-if="nights && ! singleDate">· {{ __n(':count night|:count nights', nights, { count: nights }) }}</template>
                                    </div>
                                    <div v-if="maximumQuantity > 1 || selectedRateTitle" class="text-sm text-gray-600 dark:text-gray-400">
                                        <template v-if="maximumQuantity > 1">{{ __('Quantity: :count', { count: form.quantity }) }}</template>
                                        <template v-if="maximumQuantity > 1 && selectedRateTitle"> · </template>
                                        <template v-if="selectedRateTitle">{{ selectedRateTitle }}</template>
                                    </div>
                                    <Badge v-if="availabilityBadge" class="mt-1" size="sm" :color="availabilityBadge.color" :text="availabilityBadge.text" />
                                </div>

                                <template v-if="quote">
                                    <Separator />
                                    <dl class="space-y-2 text-sm transition-opacity" :class="{ 'opacity-50': figuresDimmed }">
                                        <div class="flex items-baseline justify-between gap-3">
                                            <dt class="text-gray-600 dark:text-gray-400">{{ __('Base price') }}</dt>
                                            <dd class="flex items-baseline gap-2 tabular-nums">
                                                <span v-if="struckOriginalBase" class="text-xs text-gray-500 line-through">{{ money(struckOriginalBase) }}</span>
                                                <span>{{ money(basePriceShown) }}</span>
                                            </dd>
                                        </div>
                                        <div v-if="discountRow" class="flex items-baseline justify-between gap-3">
                                            <dt class="text-gray-600 dark:text-gray-400">{{ discountRow.label }}</dt>
                                            <dd class="tabular-nums">{{ discountRow.value }}</dd>
                                        </div>
                                        <div v-if="showExtrasRow" class="flex items-baseline justify-between gap-3">
                                            <dt class="min-w-0 text-gray-600 dark:text-gray-400">
                                                {{ __('Extras') }}
                                                <ul v-if="selectedExtraLines.length" class="mt-0.5 space-y-0.5 ps-3 text-xs text-gray-500 dark:text-gray-400">
                                                    <li v-for="line in selectedExtraLines" :key="line.id" class="break-words">{{ line.text }}</li>
                                                </ul>
                                            </dt>
                                            <dd class="tabular-nums">{{ money(quote.pricing.extras_total) }}</dd>
                                        </div>
                                        <div v-if="showOptionsRow" class="flex items-baseline justify-between gap-3">
                                            <dt class="min-w-0 text-gray-600 dark:text-gray-400">
                                                {{ __('Options') }}
                                                <ul v-if="selectedOptionLines.length" class="mt-0.5 space-y-0.5 ps-3 text-xs text-gray-500 dark:text-gray-400">
                                                    <li v-for="line in selectedOptionLines" :key="line.id" class="break-words">{{ line.text }}</li>
                                                </ul>
                                            </dt>
                                            <dd class="tabular-nums">{{ money(quote.pricing.options_total) }}</dd>
                                        </div>
                                        <div v-if="adjustment" class="flex items-baseline justify-between gap-3">
                                            <dt class="text-gray-600 dark:text-gray-400">{{ __('Manual adjustment') }}</dt>
                                            <dd class="tabular-nums">{{ signedMoney(adjustment) }}</dd>
                                        </div>
                                        <div class="flex items-center justify-between gap-3 border-t border-gray-200 pt-3 dark:border-gray-700">
                                            <dt class="flex items-center gap-2 font-medium text-gray-900 dark:text-gray-100">
                                                {{ __('Total') }}
                                                <Badge v-if="overridden" size="sm" color="amber" :text="__('Overridden')" />
                                            </dt>
                                            <dd class="flex items-baseline gap-2 tabular-nums">
                                                <span v-if="struckComputedTotal" class="text-xs text-gray-500 line-through">{{ money(struckComputedTotal) }}</span>
                                                <span class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ money(quote.pricing.total) }}</span>
                                            </dd>
                                        </div>
                                    </dl>

                                    <dl class="space-y-2 text-sm transition-opacity" :class="{ 'opacity-50': figuresDimmed }">
                                        <div class="flex items-baseline justify-between gap-3">
                                            <dt class="text-gray-600 dark:text-gray-400">{{ __('Requested now') }}</dt>
                                            <dd class="tabular-nums font-medium">
                                                <template v-if="paymentAmountIsZero">{{ __('Nothing to collect') }}</template>
                                                <template v-else-if="amountIsSettled">{{ money(quote.payment.amount) }}</template>
                                                <template v-else>—</template>
                                            </dd>
                                        </div>
                                        <template v-if="showSurcharge">
                                            <div class="flex items-baseline justify-between gap-3">
                                                <dt class="text-gray-600 dark:text-gray-400">{{ __('Payment surcharge') }}</dt>
                                                <dd class="tabular-nums">{{ signedMoney(selectedGatewayAmount.surcharge) }}</dd>
                                            </div>
                                            <div class="flex items-baseline justify-between gap-3">
                                                <dt class="text-gray-600 dark:text-gray-400">{{ __('Customer pays') }}</dt>
                                                <dd class="tabular-nums font-medium">{{ money(selectedGatewayAmount.amount_with_surcharge) }}</dd>
                                            </div>
                                        </template>
                                        <div v-if="balance" class="flex items-baseline justify-between gap-3">
                                            <dt class="text-gray-600 dark:text-gray-400">{{ __('Balance not collected now') }}</dt>
                                            <dd class="tabular-nums">{{ money(balance) }}</dd>
                                        </div>
                                        <div v-if="! paymentAmountIsZero" class="flex items-baseline justify-between gap-3">
                                            <dt class="text-gray-600 dark:text-gray-400">{{ __('Payment method') }}</dt>
                                            <dd class="text-end break-words">{{ selectedGatewayLabel ?? '—' }}</dd>
                                        </div>
                                        <div v-if="holdDeadlineLabel" class="flex items-baseline justify-between gap-3">
                                            <dt class="text-gray-600 dark:text-gray-400">{{ __('Auto-cancels if unpaid') }}</dt>
                                            <dd class="text-end">{{ holdDeadlineLabel }}</dd>
                                        </div>
                                    </dl>
                                </template>

                                <div
                                    class="space-y-3 border-t border-gray-200 pt-4 dark:border-gray-700 @4xl/create:sticky @4xl/create:bottom-0 @4xl/create:-mx-4.5 @4xl/create:-mb-5 @4xl/create:rounded-b-xl @4xl/create:bg-white @4xl/create:px-4.5 @4xl/create:pb-5 @4xl/create:dark:bg-gray-850"
                                >
                                    <Alert v-if="storeAlertText" ref="storeErrorAlert" :variant="storeAlert.variant">
                                        <div class="space-y-3">
                                            <p>{{ storeAlertText }}</p>
                                            <div v-if="storeAlert.redirect" class="flex flex-wrap gap-2">
                                                <Button size="sm" :href="storeAlert.redirect" :text="__('Open the existing reservation')" />
                                                <Button size="sm" variant="ghost" :text="__('Create a separate reservation')" @click="startSeparateReservation" />
                                            </div>
                                        </div>
                                    </Alert>

                                    <div
                                        v-if="form.item_id && submitBlockedReason && ! submitting && ! created"
                                        class="flex items-start gap-2 text-sm text-gray-600 dark:text-gray-400"
                                    >
                                        <Icon :name="quotePending ? 'loading' : 'info'" class="mt-0.5 size-4 shrink-0" />
                                        <span>{{ submitBlockedReason }}</span>
                                    </div>
                                    <div
                                        v-else-if="submitErrorsShown && ! submitting && ! created"
                                        class="flex items-start gap-2 text-sm text-gray-600 dark:text-gray-400"
                                    >
                                        <Icon name="warning-diamond" class="mt-0.5 size-4 shrink-0" />
                                        <span>{{ __('Fix the highlighted fields, then create again.') }}</span>
                                    </div>
                                    <Description v-else-if="submitOutcome && ! created">{{ submitOutcome }}</Description>

                                    <Button
                                        variant="primary"
                                        class="w-full"
                                        :text="submitLabel"
                                        :disabled="! canSubmit"
                                        :loading="submitting"
                                        :title="submitBlockedReason ?? undefined"
                                        @click="submit"
                                    />
                                </div>
                            </div>
                        </Card>
                    </Panel>
                </aside>
            </div>
        </div>
    </div>
</template>
