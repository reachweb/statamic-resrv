<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import axios from 'axios';
import { Head, router } from '@statamic/cms/inertia';
import {
    Alert,
    Badge,
    Button,
    Card,
    ConfirmationModal,
    Dropdown,
    DropdownItem,
    DropdownMenu,
    DropdownSeparator,
    Header,
    Heading,
    Icon,
    Input,
} from '@statamic/cms/ui';
import CancelAwaitingPaymentModal from '../../components/CancelAwaitingPaymentModal.vue';
import ConfirmPaymentModal from '../../components/ConfirmPaymentModal.vue';
import ExtendHoldModal from '../../components/ExtendHoldModal.vue';
import ReservationActionSummary from '../../components/ReservationActionSummary.vue';
import {
    copyPaymentLink as copyLink,
    factsFromDetail,
    formatInstant,
    formatStayDate,
    formatWallDate,
    markAsPaidLabel,
    paymentRequestActionLabel,
    paymentRequestConfirmText,
    relativeInstant,
    statusBadgeClass,
    statusName,
} from '../../composables/useReservationDisplay.js';
import { useToast } from '../../composables/useToast.js';

const props = defineProps({
    reservation: { type: Object, required: true },
    fields: { type: Object, default: () => ({}) },
    currencySymbol: { type: String, default: '' },
    maximumQuantity: { type: Number, default: 1 },
    backUrl: { type: String, required: true },
    refundUrl: { type: String, required: true },
    confirmPaymentUrl: { type: String, default: null },
    cancelAwaitingUrl: { type: String, default: null },
    sendPaymentRequestUrl: { type: String, default: null },
    extendHoldUrl: { type: String, default: null },
});

const toast = useToast();

// Local copy so the awaiting-payment actions can refresh page state without a full Inertia reload;
// a partial reload (after a rejected action) replaces it with the server's current state.
const reservation = ref(props.reservation);
watch(() => props.reservation, (fresh) => {
    reservation.value = fresh;
});

const isParent = computed(() => reservation.value.type === 'parent');
const isAwaitingPayment = computed(() => reservation.value.status === 'awaiting_payment');
const showQuantityColumn = computed(() => props.maximumQuantity > 1);

const fieldLabel = (handle) => props.fields[handle] ?? handle;
const statusText = computed(() => reservation.value.status_label ?? statusName(reservation.value.status));
const facts = computed(() => factsFromDetail(reservation.value, props.currencySymbol));
const money = (amount) => `${props.currencySymbol} ${amount}`.trim();
const isZero = (amount) => amount !== null && amount !== undefined && Number(amount) === 0;

const headerTitle = computed(() =>
    reservation.value.entry?.title
        ? __('Reservation #:id — :item', { id: reservation.value.id, item: reservation.value.entry.title })
        : __('Reservation #:id', { id: reservation.value.id }),
);

// --- Awaiting-payment state ---
const requestSent = computed(() => facts.value.requestSent);
const manualGateway = computed(() => reservation.value.payment_gateway_supports_manual_confirmation === true);
const arrivalPassed = computed(() => isAwaitingPayment.value && !!reservation.value.arrival_day_passed);
const windowClosed = computed(() =>
    isAwaitingPayment.value && !!(reservation.value.payment_window_closed ?? reservation.value.hold_deadline_passed),
);
const holdExpired = computed(() => windowClosed.value && !arrivalPassed.value);
const canSendRequest = computed(() => isAwaitingPayment.value && !windowClosed.value);
const canExtendHold = computed(() =>
    isAwaitingPayment.value && (reservation.value.can_extend_hold ?? true) && !arrivalPassed.value,
);

const holdDeadlineText = computed(() =>
    formatInstant(reservation.value.hold_expires_at_iso, reservation.value.hold_expires_at ?? null),
);
const requestSentText = computed(() =>
    formatInstant(reservation.value.payment_request_email_sent_at_iso, reservation.value.payment_request_email_sent_at ?? null),
);

const chargeAmount = computed(() =>
    reservation.value.payment_surcharge_is_zero
        ? money(reservation.value.payment_formatted)
        : money(reservation.value.total_to_charge_formatted),
);

// The lead sentence states the situation: payment window closed, never asked, or waiting.
const leadSentence = computed(() => {
    const amount = chargeAmount.value;
    const name = facts.value.customerName;
    const method = reservation.value.payment_gateway_label;
    if (arrivalPassed.value) {
        return name
            ? __('No payment of :amount from :name was recorded before the arrival date.', { name, amount })
            : __('No payment of :amount was recorded before the arrival date.', { amount });
    }
    if (holdExpired.value) {
        return name
            ? __('No payment of :amount from :name was recorded by the payment deadline.', { name, amount })
            : __('No payment of :amount was recorded by the payment deadline.', { amount });
    }
    if (!requestSent.value) {
        return name
            ? __('No payment request for :amount has been emailed to :name yet.', { name, amount })
            : __('No payment request for :amount has been emailed to the customer yet.', { amount });
    }
    if (name && method) return __('Waiting for :name to pay :amount by :method.', { name, amount, method });
    if (name) return __('Waiting for :name to pay :amount.', { name, amount });
    if (method) return __('Waiting for the customer to pay :amount by :method.', { amount, method });
    return __('Waiting for the customer to pay :amount.', { amount });
});

const requestSentence = computed(() =>
    requestSentText.value ? __('Payment request emailed :when.', { when: requestSentText.value }) : null,
);

// The CP keeps #main-content's scroll position across Inertia visits; a reservation opens at its header.
onMounted(() => document.getElementById('main-content')?.scrollTo(0, 0));

const deadlineSentence = computed(() => {
    if (windowClosed.value) return null;
    if (!holdDeadlineText.value) {
        return __('No payment deadline: the reservation stays on hold until you mark it as paid or cancel it.');
    }
    // A hold that outlasts the arrival day: payment already stops at the end of that day.
    // The arrival day is a wall date: shown as is, never shifted into the viewer's zone.
    const dueIso = reservation.value.payment_due_at_iso;
    if (dueIso && reservation.value.hold_expires_at_iso && dueIso !== reservation.value.hold_expires_at_iso) {
        return __('Payment is accepted until the end of the arrival day (:due); the reservation is cancelled automatically on :deadline if still unpaid.', {
            due: formatWallDate(reservation.value.date_start_iso, reservation.value.date_start ?? null),
            deadline: holdDeadlineText.value,
        });
    }
    const relative = relativeInstant(reservation.value.hold_expires_at_iso);
    return relative
        ? __('Cancels automatically if not paid by :deadline (:relative).', { deadline: holdDeadlineText.value, relative })
        : __('Cancels automatically if not paid by :deadline.', { deadline: holdDeadlineText.value });
});

const hasBalance = computed(() =>
    reservation.value.balance_formatted !== null
    && reservation.value.balance_formatted !== undefined
    && !isZero(reservation.value.balance_formatted),
);

const primaryAction = computed(() => {
    if (!isAwaitingPayment.value) return null;
    if (arrivalPassed.value) return 'confirm';
    if (windowClosed.value) return canExtendHold.value ? 'extend' : 'confirm';
    return manualGateway.value ? 'confirm' : 'request';
});

const primaryLabel = computed(() => ({
    confirm: __('Mark as paid'),
    request: paymentRequestActionLabel(requestSent.value),
    extend: __('Extend hold'),
}[primaryAction.value] ?? null));

const secondaryActions = computed(() => {
    if (!isAwaitingPayment.value) return [];
    const items = [];
    if (primaryAction.value !== 'confirm') {
        items.push({ key: 'confirm', icon: 'checkmark', text: markAsPaidLabel(manualGateway.value) });
    }
    if (canSendRequest.value && primaryAction.value !== 'request') {
        items.push({ key: 'request', icon: 'mail', text: paymentRequestActionLabel(requestSent.value) });
    }
    if (canExtendHold.value && primaryAction.value !== 'extend') {
        items.push({ key: 'extend', icon: 'time-clock', text: __('Extend hold…') });
    }
    return items;
});

const clipboardAvailable = typeof navigator !== 'undefined' && typeof navigator.clipboard?.writeText === 'function';
const selectAll = (event) => event.target?.select?.();

// --- Payment information ---
const paymentState = computed(() => {
    const r = reservation.value;
    if (r.payment_formatted === null) return 'partner';
    if (['awaiting_payment', 'pending'].includes(r.status)) return 'requested';
    if (r.amount_paid_formatted === null || r.amount_paid_formatted === undefined) return 'unknown';
    if (isZero(r.amount_paid_formatted) && ['cancelled', 'expired', 'refunded'].includes(r.status)) return 'not_collected';
    return 'paid';
});

const paymentRowLabel = computed(() => ({
    requested: __('Requested (unpaid)'),
    not_collected: __('Not collected'),
    paid: __('Paid'),
}[paymentState.value] ?? __('Payment')));

// amount_paid_formatted includes the surcharge, which the surcharge and "Total charged" rows below itemise.
const paymentRowAmount = computed(() =>
    paymentState.value === 'paid' && reservation.value.payment_surcharge_is_zero
        ? reservation.value.amount_paid_formatted
        : reservation.value.payment_formatted,
);

const showBalanceRow = computed(() =>
    hasBalance.value && ['awaiting_payment', 'pending', 'confirmed'].includes(reservation.value.status),
);

// A refund outside a gateway is returned by hand. A null amount_paid_formatted means Resrv cannot
// tell whether the money arrived (frontend offline booking), so that wording is hedged.
const manualRefund = computed(() => {
    const r = reservation.value;
    if (r.status !== 'refunded' || r.refund_is_automatic !== false) return null;
    const known = r.amount_paid_formatted !== null && r.amount_paid_formatted !== undefined;
    const amount = known
        ? r.amount_paid_formatted
        : (r.payment_surcharge_is_zero === false ? r.total_to_charge_formatted : r.payment_formatted);
    if (amount === null || amount === undefined || isZero(amount)) return null;
    return { amount: money(amount), known };
});

const createdText = computed(() => formatInstant(reservation.value.created_at_iso, reservation.value.created_at ?? null));
const stayDate = (iso, fallback) => formatStayDate(iso, fallback ?? null, reservation.value.stay_dates_have_time ?? null);

// --- Actions ---
const showConfirmModal = ref(false);
const showCancelModal = ref(false);
const showPaymentRequestModal = ref(false);
const showExtendModal = ref(false);
const extendErrors = ref({});
const busy = ref(false);

const closeModals = () => {
    showConfirmModal.value = false;
    showCancelModal.value = false;
    showPaymentRequestModal.value = false;
    showExtendModal.value = false;
    extendErrors.value = {};
};

const openAction = (key) => {
    if (key === 'confirm') showConfirmModal.value = true;
    else if (key === 'request') showPaymentRequestModal.value = true;
    else if (key === 'extend') showExtendModal.value = true;
    else if (key === 'cancel') showCancelModal.value = true;
};

const closeExtendModal = () => {
    showExtendModal.value = false;
    extendErrors.value = {};
};

const runAction = async (url, successMessage, payload = {}) => {
    busy.value = true;
    try {
        const { data } = await axios.post(url, payload);
        reservation.value = data;
        if (data?.payment_unresolved) {
            toast.error(__('Done, but the online payment needs checking in your payment provider. See the warning on this page.'));
        } else if (data?.payment_request_error) {
            toast.error(__('The payment deadline was updated, but the payment request was not sent: :error', { error: data.payment_request_error }));
        } else {
            toast.success(typeof successMessage === 'function' ? successMessage(data) : successMessage);
        }
        closeModals();
    } catch (error) {
        const response = error?.response;
        const data = response?.data;
        if (response?.status === 422 && data?.errors) {
            // Field validation: keep the modal open so the value can be corrected.
            extendErrors.value = data.errors;
            toast.error(data.message ?? __('Something went wrong'));
            return;
        }
        toast.error(data?.error ?? __('Something went wrong'));
        if (response && response.status < 500) {
            // Usually the reservation changed elsewhere (paid, cancelled, lapsed): show its real state.
            closeModals();
            router.reload({ only: ['reservation'] });
        }
    } finally {
        busy.value = false;
    }
};

const extendHoldUrl = computed(() =>
    props.extendHoldUrl ?? cp_url(`resrv/reservation/${reservation.value.id}/extend-hold`),
);

const confirmPayment = () => runAction(props.confirmPaymentUrl, __('Reservation marked as paid'));
const cancelAwaiting = (payload) => runAction(props.cancelAwaitingUrl, __('Reservation cancelled'), payload);
const sendPaymentRequest = () => runAction(props.sendPaymentRequestUrl, __('Payment request email sent'));
const extendHold = (payload) => runAction(
    extendHoldUrl.value,
    (data) => (data?.payment_request_sent ? __('Payment deadline updated and payment request sent') : __('Payment deadline updated')),
    payload,
);

const copyPaymentLink = () => copyLink(reservation.value.payment_url, toast);
</script>

<template>
    <div class="max-w-page mx-auto">
        <Head :title="__('Reservation #:id', { id: reservation.id })" />

        <div class="flex">
            <a
                :href="backUrl"
                class="flex-initial flex p-2 -m-1 items-center text-xs text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white"
            >
                <Icon name="chevron-left" class="size-4" />
                <span>{{ __('Back') }}</span>
            </a>
        </div>

        <Header>
            <template #title>
                <span class="flex flex-wrap items-center gap-x-2.5 gap-y-1 min-w-0">
                    <span class="break-words">{{ headerTitle }}</span>
                    <span
                        :class="statusBadgeClass(reservation.status)"
                        class="inline-block whitespace-nowrap px-2 py-1 text-white text-xs uppercase"
                    >
                        {{ statusText }}
                    </span>
                    <Badge
                        v-if="windowClosed"
                        color="red"
                        :text="arrivalPassed ? __('Arrival date passed') : __('Hold expired')"
                    />
                </span>
            </template>
            <template v-if="isAwaitingPayment" #actions>
                <Button variant="primary" :text="primaryLabel" :disabled="busy" @click="openAction(primaryAction)" />
                <Dropdown align="end">
                    <template #trigger>
                        <Button :text="__('More actions')" icon-append="chevron-down" :disabled="busy" />
                    </template>
                    <DropdownMenu>
                        <DropdownItem
                            v-for="action in secondaryActions"
                            :key="action.key"
                            :text="action.text"
                            :icon="action.icon"
                            @click="openAction(action.key)"
                        />
                        <DropdownSeparator v-if="secondaryActions.length" />
                        <DropdownItem
                            :text="__('Cancel reservation')"
                            icon="x"
                            variant="destructive"
                            @click="openAction('cancel')"
                        />
                    </DropdownMenu>
                </Dropdown>
            </template>
        </Header>

        <Alert v-if="reservation.payment_unresolved" variant="error" class="mb-6">
            <Heading :text="__('Check this payment with your payment provider')" />
            <p v-if="reservation.status === 'confirmed'">
                {{ reservation.payment_reference
                    ? __('This reservation was marked as paid, but the online payment :reference may also have been charged or could not be verified. Check it in your payment provider and refund any duplicate charge.', { reference: reservation.payment_reference })
                    : __('This reservation was marked as paid, but an online payment may also have been charged or could not be verified. Check your payment provider and refund any duplicate charge.') }}
            </p>
            <p v-else-if="reservation.status === 'refunded'">
                {{ reservation.payment_reference
                    ? __('If the online payment :reference was charged, it was refunded through your payment provider when you refunded this reservation. If it is still open, cancel it there so it cannot collect a payment.', { reference: reservation.payment_reference })
                    : __('If an online payment for this reservation was charged, it was refunded through your payment provider when you refunded this reservation. If it is still open, cancel it there so it cannot collect a payment.') }}
            </p>
            <p v-else>
                {{ reservation.payment_reference
                    ? __('The online payment :reference could not be verified as cancelled. Check it in your payment provider and refund it there if the customer was charged.', { reference: reservation.payment_reference })
                    : __('An online payment for this reservation could not be verified as cancelled. Check your payment provider and refund it there if the customer was charged.') }}
            </p>
        </Alert>

        <Alert v-if="manualRefund" variant="warning" class="mb-6">
            <template v-if="manualRefund.known">
                <Heading :text="__('Return the payment manually')" />
                <p>
                    {{ __('This payment was recorded outside an online payment gateway, so it was not returned automatically. Return :amount to the customer yourself if you have not already done so.', { amount: manualRefund.amount }) }}
                </p>
            </template>
            <template v-else>
                <Heading :text="__('Return any payment manually')" />
                <p>
                    {{ __('Resrv cannot tell whether the customer paid for this reservation (for example by bank transfer), so nothing was returned automatically. If the customer has already paid, return :amount to them yourself.', { amount: manualRefund.amount }) }}
                </p>
            </template>
        </Alert>

        <section>
            <div class="mb-2 content flex">
                <h2 class="text-base">{{ __('Reservation Details') }}</h2>
            </div>
            <Card class="px-6 py-4 mb-8 divide-y">
                <div class="grid grid-cols-3 my-2">
                    <div>
                        <div class="font-bold mb-2">{{ __('Reservation ID') }}</div>
                        <div># {{ reservation.id }}</div>
                    </div>
                    <div>
                        <div class="font-bold mb-2">{{ __('Reference') }}</div>
                        <div>{{ reservation.reference }}</div>
                    </div>
                    <div>
                        <div class="font-bold mb-2">{{ __('Status') }}</div>
                        <div>
                            <span
                                :class="statusBadgeClass(reservation.status)"
                                class="inline-block whitespace-nowrap px-2 py-1 text-white text-xs uppercase"
                            >
                                {{ statusText }}
                            </span>
                        </div>
                    </div>
                </div>

                <div v-if="!isParent" class="grid grid-cols-2 my-2 pt-2">
                    <div>
                        <div class="font-bold mb-2">{{ __('Start date') }}</div>
                        <div>{{ stayDate(reservation.date_start_iso, reservation.date_start) }}</div>
                    </div>
                    <div>
                        <div class="font-bold mb-2">{{ __('End date') }}</div>
                        <div>{{ stayDate(reservation.date_end_iso, reservation.date_end) }}</div>
                    </div>
                </div>

                <div v-if="!isParent" class="grid grid-cols-2 my-2 pt-2">
                    <div v-if="showQuantityColumn">
                        <div class="font-bold mb-2">{{ __('Quantity') }}</div>
                        <div>x {{ reservation.quantity }}</div>
                    </div>
                    <div v-if="reservation.rate_id">
                        <div class="font-bold mb-2">{{ __('Rate') }}</div>
                        <div>{{ reservation.rate_label }}</div>
                    </div>
                </div>

                <div class="grid grid-cols-3 my-2 pt-2">
                    <div>
                        <div class="font-bold mb-2">{{ __('Created') }}</div>
                        <div>{{ createdText }}</div>
                    </div>
                    <template v-if="reservation.created_by">
                        <div>
                            <div class="font-bold mb-2">{{ __('Created by') }}</div>
                            <div>{{ reservation.created_by_name ?? reservation.created_by }}</div>
                        </div>
                        <div>
                            <div class="font-bold mb-2">{{ __('Decreases availability') }}</div>
                            <div>{{ reservation.affects_availability ? __('Yes') : __('No') }}</div>
                        </div>
                    </template>
                </div>
            </Card>
        </section>

        <section v-if="isAwaitingPayment">
            <div class="mb-2 content flex">
                <h2 class="text-base">{{ __('Awaiting payment') }}</h2>
            </div>

            <Alert
                v-if="arrivalPassed"
                variant="warning"
                class="mb-4"
                :heading="__('Arrival date passed')"
                :text="__('The stay has already started, so the customer can no longer pay through the payment link and a payment request cannot be sent. Mark the reservation as paid if you collected the money another way, or cancel it.')"
            />
            <Alert
                v-else-if="holdExpired"
                variant="warning"
                class="mb-4"
                :heading="__('Hold expired — will be cancelled by the next sweep')"
                :text="__('The payment deadline has passed, so the payment link no longer works and a payment request cannot be sent. Extend the hold to give the customer more time, mark the reservation as paid, or cancel it now.')"
            />

            <Card class="px-6 py-4 mb-8 divide-y">
                <div class="pb-4 space-y-1">
                    <p class="font-medium">{{ leadSentence }}</p>
                    <p v-if="requestSentence" class="text-sm text-gray-700 dark:text-gray-300">{{ requestSentence }}</p>
                    <p v-if="deadlineSentence" class="text-sm text-gray-700 dark:text-gray-300">{{ deadlineSentence }}</p>
                    <p v-if="hasBalance" class="text-sm text-gray-700 dark:text-gray-300">
                        {{ __('The remaining balance of :balance is not included in this payment request.', { balance: money(reservation.balance_formatted) }) }}
                    </p>
                </div>
                <div class="grid grid-cols-2 xl:grid-cols-3 pt-4 gap-y-4 gap-x-4">
                    <div>
                        <div class="font-bold mb-2">{{ __('Amount requested') }}</div>
                        <div>
                            {{ money(reservation.payment_formatted) }}
                            <span v-if="!reservation.payment_surcharge_is_zero" class="text-gray-600 dark:text-gray-400">
                                {{ __('(+ :amount surcharge)', { amount: money(reservation.payment_surcharge_formatted) }) }}
                            </span>
                        </div>
                    </div>
                    <div v-if="hasBalance">
                        <div class="font-bold mb-2">{{ __('Balance not requested') }}</div>
                        <div>{{ money(reservation.balance_formatted) }}</div>
                    </div>
                    <div>
                        <div class="font-bold mb-2">{{ __('Payment method') }}</div>
                        <div>{{ reservation.payment_gateway_label ?? '-' }}</div>
                    </div>
                    <div>
                        <div class="font-bold mb-2">{{ __('Hold deadline') }}</div>
                        <div class="flex flex-wrap items-center gap-2">
                            <span>{{ holdDeadlineText ?? __('No deadline') }}</span>
                            <Badge v-if="reservation.hold_deadline_passed || holdExpired" color="red" size="sm" :text="__('Expired')" />
                        </div>
                    </div>
                    <div>
                        <div class="font-bold mb-2">{{ __('Payment request emailed') }}</div>
                        <div>{{ requestSentText ?? __('Not sent') }}</div>
                    </div>
                    <div class="col-span-full">
                        <label for="resrv-payment-link" class="block font-bold mb-2">{{ __('Payment link') }}</label>
                        <template v-if="reservation.payment_url">
                            <div v-if="windowClosed" class="text-gray-600 dark:text-gray-400">
                                {{ __('The payment link no longer accepts payments.') }}
                            </div>
                            <div v-else class="flex items-center gap-2" @focusin="selectAll">
                                <Input
                                    id="resrv-payment-link"
                                    :model-value="reservation.payment_url"
                                    read-only
                                    copyable
                                    size="sm"
                                    class="flex-1 min-w-0"
                                    input-class="font-mono"
                                />
                                <Button v-if="!clipboardAvailable" size="sm" :text="__('Copy')" @click="copyPaymentLink" />
                            </div>
                        </template>
                        <div v-else class="text-gray-600 dark:text-gray-400">
                            {{ __('Payment page not configured') }}
                        </div>
                    </div>
                </div>
            </Card>
        </section>

        <ConfirmationModal
            v-if="showPaymentRequestModal"
            :open="true"
            :title="paymentRequestActionLabel(requestSent)"
            :button-text="__('Send')"
            :busy="busy"
            @confirm="sendPaymentRequest"
            @cancel="showPaymentRequestModal = false"
        >
            <ReservationActionSummary :facts="facts" />
            <p>{{ paymentRequestConfirmText(facts) }}</p>
        </ConfirmationModal>

        <ConfirmPaymentModal
            v-if="showConfirmModal"
            :busy="busy"
            :facts="facts"
            @confirm="confirmPayment"
            @cancel="showConfirmModal = false"
        />

        <CancelAwaitingPaymentModal
            v-if="showCancelModal"
            :busy="busy"
            :facts="facts"
            :affects-availability="reservation.affects_availability"
            @confirm="cancelAwaiting"
            @cancel="showCancelModal = false"
        />

        <ExtendHoldModal
            v-if="showExtendModal"
            :busy="busy"
            :facts="facts"
            :errors="extendErrors"
            @confirm="extendHold"
            @cancel="closeExtendModal"
        />

        <section v-if="isParent && reservation.childs.length">
            <div class="mb-2 content flex">
                <h2 class="text-base">{{ __('Related reservations') }}</h2>
            </div>
            <Card v-for="child in reservation.childs" :key="child.id" class="px-6 py-4 mb-6 divide-y">
                <div class="grid grid-cols-2 my-2">
                    <div>
                        <div class="font-bold mb-2">{{ __('Start date') }}</div>
                        <div>{{ stayDate(child.date_start_iso, child.date_start) }}</div>
                    </div>
                    <div>
                        <div class="font-bold mb-2">{{ __('End date') }}</div>
                        <div>{{ stayDate(child.date_end_iso, child.date_end) }}</div>
                    </div>
                </div>
                <div class="grid grid-cols-2 my-2 pt-2">
                    <div v-if="showQuantityColumn">
                        <div class="font-bold mb-2">{{ __('Quantity') }}</div>
                        <div>x {{ child.quantity }}</div>
                    </div>
                    <div v-if="child.rate_id">
                        <div class="font-bold mb-2">{{ __('Rate') }}</div>
                        <div>{{ child.rate_label }}</div>
                    </div>
                </div>
            </Card>
        </section>

        <section v-if="Object.keys(reservation.customer_data).length">
            <div class="mb-2 content">
                <h2 class="text-base">{{ __('Checkout data') }}</h2>
            </div>
            <Card class="px-6 py-4 mb-6">
                <div class="grid grid-cols-2 xl:grid-cols-3 mt-2">
                    <div
                        v-for="(value, handle) in reservation.customer_data"
                        :key="handle"
                        class="mb-2"
                    >
                        <div class="font-bold mb-2">{{ fieldLabel(handle) }}</div>
                        <div>{{ value }}</div>
                    </div>
                </div>
            </Card>
        </section>

        <section v-if="reservation.options.length">
            <div class="mb-2 content">
                <h2 class="text-base">{{ __('Options') }}</h2>
            </div>
            <Card class="px-6 py-4 mb-6">
                <div
                    v-for="option in reservation.options"
                    :key="option.id"
                    class="mb-2 border-b border-gray flex justify-between w-full p-2"
                >
                    <div>{{ option.name }}</div>
                    <div>{{ option.value_name }}</div>
                    <div v-if="option.price_formatted" class="font-bold">
                        {{ option.price_formatted }}
                        <span class="font-normal">
                            <template v-if="option.price_type === 'fixed'">
                                / {{ __('reservation') }}
                            </template>
                            <template v-if="option.price_type === 'perday'">
                                / {{ __('day') }}
                            </template>
                        </span>
                    </div>
                </div>
            </Card>
        </section>

        <section v-if="reservation.extras.length">
            <div class="mb-2 content">
                <h2 class="text-base">{{ __('Extras') }}</h2>
            </div>
            <Card class="px-6 py-4 mb-6">
                <div
                    v-for="extra in reservation.extras"
                    :key="extra.id"
                    class="mb-2 border-b border-gray flex justify-between w-full p-2"
                >
                    <div>{{ extra.name }} x{{ extra.quantity }}</div>
                    <div class="font-bold">
                        {{ currencySymbol }} {{ extra.price_formatted }}
                    </div>
                </div>
            </Card>
        </section>

        <section v-if="reservation.affiliate">
            <div class="mb-2 content">
                <h2 class="text-base">{{ __('Affiliate') }}</h2>
            </div>
            <Card class="px-6 py-4 mb-6">
                <div class="mb-2 border-b border-gray flex justify-between w-full p-2">
                    <div>{{ reservation.affiliate.name }}</div>
                    <div class="font-bold">{{ reservation.affiliate.email }}</div>
                </div>
                <div class="mb-2 border-b border-gray flex justify-between w-full p-2">
                    <div>{{ __('Fee at the time of reservation') }}</div>
                    <div class="font-bold">{{ reservation.affiliate.fee }}%</div>
                </div>
                <div class="mb-2 border-b border-gray flex justify-between w-full p-2">
                    <div>{{ __('Preliminary fee to be paid') }}</div>
                    <div class="font-bold">
                        <span v-if="reservation.affiliate.commission_cancelled" class="text-red-500">
                            {{ __('Commission cancelled') }}
                        </span>
                        <span v-else>
                            {{ currencySymbol }} {{ reservation.affiliate.fee_amount_formatted }}
                        </span>
                    </div>
                </div>
            </Card>
        </section>

        <section v-if="reservation.dynamic_pricings.length">
            <div class="mb-2 content">
                <h2 class="text-base">{{ __('Dynamic pricing policies applied') }}</h2>
            </div>
            <Card class="px-6 py-4 mb-6">
                <div
                    v-for="pricing in reservation.dynamic_pricings"
                    :key="pricing.id"
                    class="mb-2 border-b border-gray grid grid-cols-2 p-2"
                >
                    <div>
                        <div class="mb-1">{{ __('Title') }}</div>
                        <div class="font-bold">
                            <span class="mr-1 text-sm font-light">{{ pricing.order }}</span>
                            {{ pricing.title }}
                        </div>
                    </div>
                    <div>
                        <div class="mb-1">{{ __('Amount') }}</div>
                        <div class="font-bold">
                            {{ pricing.amount }}
                            <template v-if="pricing.amount_type === 'fixed'">{{ currencySymbol }}</template>
                            <template v-else-if="pricing.amount_type === 'percent'">%</template>
                            <span class="ml-3 text-sm font-light">{{ pricing.amount_operation }}</span>
                        </div>
                    </div>
                </div>
            </Card>
        </section>

        <section>
            <div class="mb-2 content">
                <h2 class="text-base">{{ __('Payment information') }}</h2>
            </div>
            <Card class="px-6 py-4 mb-6">
                <div v-if="reservation.payment_gateway" class="mb-2 border-b border-gray flex justify-between w-full p-2">
                    <div>{{ __('Payment method') }}</div>
                    <div class="font-bold">{{ reservation.payment_gateway_label }}</div>
                </div>
                <div class="mb-2 border-b border-gray flex justify-between w-full p-2">
                    <div>{{ paymentRowLabel }}</div>
                    <div class="font-bold">
                        <template v-if="paymentState !== 'partner'">
                            {{ money(paymentRowAmount) }}
                        </template>
                        <template v-else>
                            {{ __('No payment collected (partner reservation)') }}
                        </template>
                    </div>
                </div>
                <div v-if="showBalanceRow" class="mb-2 border-b border-gray flex justify-between w-full p-2">
                    <div>{{ __('Balance not requested') }}</div>
                    <div class="font-bold">{{ money(reservation.balance_formatted) }}</div>
                </div>
                <template v-if="!reservation.payment_surcharge_is_zero">
                    <div class="mb-2 border-b border-gray flex justify-between w-full p-2">
                        <div>{{ __('Payment surcharge') }}</div>
                        <div class="font-bold">
                            {{ money(reservation.payment_surcharge_formatted) }}
                        </div>
                    </div>
                    <div class="mb-2 border-b border-gray flex justify-between w-full p-2">
                        <div>{{ paymentState === 'paid' ? __('Total charged') : __('Total requested') }}</div>
                        <div class="font-bold">
                            {{ money(reservation.total_to_charge_formatted) }}
                        </div>
                    </div>
                </template>
                <div
                    v-if="paymentState === 'not_collected' && reservation.hold_expires_at"
                    class="mb-2 border-b border-gray flex justify-between w-full p-2"
                >
                    <div>{{ __('Payment deadline') }}</div>
                    <div class="font-bold">{{ holdDeadlineText }}</div>
                </div>
                <div class="mb-2 border-b border-gray flex justify-between w-full p-2">
                    <div>{{ __('Reservation price') }}</div>
                    <div class="font-bold">
                        {{ money(reservation.price_formatted) }}
                    </div>
                </div>
                <div class="mb-2 border-b border-gray flex justify-between w-full p-2">
                    <div class="font-bold text-xl">{{ __('Total price (including extras & options)') }}</div>
                    <div class="font-bold text-xl">
                        {{ money(reservation.total_formatted) }}
                    </div>
                </div>
            </Card>
        </section>
    </div>
</template>
