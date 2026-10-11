<script setup>
import { computed, ref } from 'vue';
import axios from 'axios';
import { Head } from '@statamic/cms/inertia';
import {
    Header,
    Badge,
    Button,
    Icon,
    Listing,
    DropdownItem,
    DropdownSeparator,
    ConfirmationModal,
} from '@statamic/cms/ui';
import CancelAwaitingPaymentModal from '../../components/CancelAwaitingPaymentModal.vue';
import ConfirmPaymentModal from '../../components/ConfirmPaymentModal.vue';
import ExtendHoldModal from '../../components/ExtendHoldModal.vue';
import ReservationActionSummary from '../../components/ReservationActionSummary.vue';
import {
    copyPaymentLink,
    factsFromRow,
    markAsPaidLabel,
    paymentRequestActionLabel,
    paymentRequestConfirmText,
    statusBadgeClass,
    statusName,
} from '../../composables/useReservationDisplay.js';
import { useToast } from '../../composables/useToast.js';

const props = defineProps({
    filters: { type: Array, default: () => [] },
    listUrl: { type: String, required: true },
    showUrlTemplate: { type: String, required: true },
    refundUrl: { type: String, required: true },
    resendUrl: { type: String, required: true },
    calendarUrl: { type: String, required: true },
    createUrl: { type: String, default: null },
    confirmPaymentUrlTemplate: { type: String, default: null },
    cancelAwaitingUrlTemplate: { type: String, default: null },
    sendPaymentRequestUrlTemplate: { type: String, default: null },
    extendHoldUrlTemplate: { type: String, default: null },
});

const toast = useToast();
const listing = ref(null);
const refundRow = ref(null);
const refunding = ref(false);
const resendId = ref(null);
const resending = ref(false);

const showUrl = (reservation) => props.showUrlTemplate.replace('RESRVURL', reservation.id);

const canResend = (reservation) => ['confirmed', 'partner'].includes(reservation.status);

// Mirrors the state machine: only these states may transition to REFUNDED/CANCELLED,
// so terminal rows don't offer an action the server will reject anyway.
// Awaiting-payment rows get their own confirm/cancel pair instead of Refund.
const canRefund = (reservation) => ['pending', 'confirmed', 'partner'].includes(reservation.status);

const isAwaitingPayment = (reservation) => reservation.status === 'awaiting_payment';
const paymentWindowClosed = (reservation) => isAwaitingPayment(reservation) && !!reservation.payment_window_closed;
const rowStatusText = (reservation) => reservation.status_label ?? statusName(reservation.status);

const overdueLabel = (reservation) => (reservation.arrival_day_passed ? __('Arrival date passed') : __('Hold expired'));
const overdueExplanation = (reservation) => (reservation.arrival_day_passed
    ? __('The arrival date has passed, so the customer can no longer pay through the payment link.')
    : __('Hold expired — will be cancelled by the next sweep.'));

const customerEmail = (customer) => customer?.email ?? '';

// Closed rows whose requested amount never arrived; without amount_paid_formatted only an
// expired checkout is known to be unpaid.
const notCollected = (reservation) => {
    if (!['cancelled', 'expired', 'refunded'].includes(reservation.status)) return false;
    if (Number(reservation.payment_formatted) === 0) return false;
    if (reservation.amount_paid_formatted === undefined) return reservation.status === 'expired';
    return reservation.amount_paid_formatted !== null && Number(reservation.amount_paid_formatted) === 0;
};

const rowMarkAsPaidLabel = (reservation) =>
    markAsPaidLabel(reservation.supports_manual_confirmation ?? reservation.payment_gateway_supports_manual_confirmation);

const confirmRefund = (reservation) => {
    refundRow.value = reservation;
};

const cancelRefund = () => {
    refundRow.value = null;
};

const isZero = (amount) => amount !== null && amount !== undefined && amount !== '' && Number(amount) === 0;

// Partner and zero-amount bookings hold no charge: the server cancels them instead of refunding.
// holds_no_charge is the server's own test (empty payment_id too); the fallback mirrors it loosely.
const refundHasNoCharge = computed(() => {
    const row = refundRow.value;
    if (!row) return false;
    if (typeof row.holds_no_charge === 'boolean') return row.holds_no_charge;
    return row.status === 'partner' || isZero(row.payment_formatted);
});

const refundFacts = computed(() => {
    if (!refundRow.value) return null;
    const rowFacts = factsFromRow(refundRow.value);
    return refundHasNoCharge.value ? { ...rowFacts, amount: null, surcharge: null } : rowFacts;
});

const refundSentence = computed(() => {
    const row = refundRow.value;
    if (!row) return null;
    if (refundHasNoCharge.value) {
        return row.status === 'partner'
            ? __('Partner reservations have no payment to refund, so the reservation will be cancelled and the customer notified.')
            : __('No payment was taken for this reservation, so there is nothing to refund: it will be cancelled and the customer notified.');
    }
    const rowFacts = refundFacts.value;
    const amount = rowFacts.chargeAmount ?? rowFacts.amount;
    if (row.refund_is_automatic === false) {
        // amountPaid null/undefined: Resrv cannot tell whether the money arrived (e.g. bank transfer).
        return rowFacts.amountPaid && !isZero(row.amount_paid_formatted)
            ? __('This payment was recorded outside an online payment gateway, so it will not be returned automatically: return :amount to the customer yourself. The customer will be notified.', { amount: rowFacts.amountPaid })
            : __('If the customer has already paid, return :amount to them yourself: payments made outside an online payment gateway are not returned automatically. The customer will be notified.', { amount });
    }
    if (row.status === 'pending') {
        return row.payment_gateway
            ? __('Any payment taken through :gateway will be refunded automatically. The customer will be notified.', { gateway: row.payment_gateway })
            : __('Any payment taken through the online payment gateway will be refunded automatically. The customer will be notified.');
    }
    return row.payment_gateway
        ? __(':amount will be refunded automatically through :gateway. The customer will be notified.', { amount, gateway: row.payment_gateway })
        : __(':amount will be refunded automatically through the online payment gateway. The customer will be notified.', { amount });
});

const refund = async () => {
    if (! refundRow.value) return;
    refunding.value = true;
    try {
        // No-charge bookings (partner / zero payment) end as a cancellation, not a refund —
        // report what the server actually did so admins aren't told money moved. A REFUNDED
        // row whose payment never touched a gateway (out-of-band / offline) needs the admin
        // to return the money by hand, so that toast must not read like an automatic refund.
        const paidKnown = !!refundFacts.value?.amountPaid && !isZero(refundRow.value.amount_paid_formatted);
        const { data } = await axios.patch(props.refundUrl, { id: refundRow.value.id });
        const manualMessage = paidKnown
            ? __('Refund recorded — return the payment to the customer manually')
            : __('Refund recorded — if the customer paid, return the payment to them manually');
        toast.success(
            data?.status !== 'refunded'
                ? __('Reservation cancelled — no charges to refund')
                : data?.refund_is_automatic === false
                    ? manualMessage
                    : __('Reservation refunded'),
        );
        refundRow.value = null;
        listing.value?.refresh();
    } catch (error) {
        toast.error(error?.response?.data?.error ?? __('Something went wrong'));
        // Already refunded/cancelled elsewhere (409) or no longer refundable (422): show the real row.
        if ([409, 422].includes(error?.response?.status)) {
            refundRow.value = null;
            listing.value?.refresh();
        }
    } finally {
        refunding.value = false;
    }
};

const confirmResend = (reservation) => {
    resendId.value = reservation.id;
};

const cancelResend = () => {
    resendId.value = null;
};

const resend = async () => {
    if (! resendId.value) return;
    resending.value = true;
    try {
        await axios.post(props.resendUrl, { id: resendId.value });
        toast.success(__('Confirmation email sent'));
        resendId.value = null;
    } catch (error) {
        toast.error(error?.response?.data?.error ?? __('Something went wrong'));
    } finally {
        resending.value = false;
    }
};

// --- Awaiting-payment actions ---
const confirmPaymentRow = ref(null);
const cancelAwaitingRow = ref(null);
const paymentRequestRow = ref(null);
const extendHoldRow = ref(null);
const extendErrors = ref({});
const actionBusy = ref(false);

const facts = (row) => (row ? factsFromRow(row) : null);

const extendHoldUrlTemplate = computed(() =>
    props.extendHoldUrlTemplate ?? cp_url('resrv/reservation/RESRVURL/extend-hold'),
);

// POST a row action and refresh the list. Returns whether the caller should close its modal:
// on success, and on a 4xx (the row changed elsewhere, so a retry cannot succeed). Field
// validation errors and network/5xx failures keep the modal open.
const runRowAction = async (urlTemplate, id, successMessage, payload = {}) => {
    actionBusy.value = true;
    try {
        const { data } = await axios.post(urlTemplate.replace('RESRVURL', id), payload);
        if (data?.payment_unresolved) {
            toast.error(__('Done, but the online payment for reservation #:id needs checking in your payment provider. Open the reservation for details.', { id }));
        } else if (data?.payment_request_error) {
            toast.error(__('The payment deadline was updated, but the payment request was not sent: :error', { error: data.payment_request_error }));
        } else {
            toast.success(typeof successMessage === 'function' ? successMessage(data) : successMessage);
        }
        listing.value?.refresh();
        return true;
    } catch (error) {
        const response = error?.response;
        const data = response?.data;
        if (response?.status === 422 && data?.errors) {
            extendErrors.value = data.errors;
            toast.error(data.message ?? __('Something went wrong'));
            return false;
        }
        toast.error(data?.error ?? __('Something went wrong'));
        if (response && response.status < 500) {
            listing.value?.refresh();
            return true;
        }
        return false;
    } finally {
        actionBusy.value = false;
    }
};

const confirmPayment = async () => {
    if (! confirmPaymentRow.value) return;
    if (await runRowAction(props.confirmPaymentUrlTemplate, confirmPaymentRow.value.id, __('Reservation marked as paid'))) {
        confirmPaymentRow.value = null;
    }
};

const cancelAwaiting = async (payload) => {
    if (! cancelAwaitingRow.value) return;
    if (await runRowAction(props.cancelAwaitingUrlTemplate, cancelAwaitingRow.value.id, __('Reservation cancelled'), payload)) {
        cancelAwaitingRow.value = null;
    }
};

const sendPaymentRequest = async () => {
    if (! paymentRequestRow.value) return;
    if (await runRowAction(props.sendPaymentRequestUrlTemplate, paymentRequestRow.value.id, __('Payment request email sent'))) {
        paymentRequestRow.value = null;
    }
};

const extendHold = async (payload) => {
    if (! extendHoldRow.value) return;
    const message = (data) => (data?.payment_request_sent
        ? __('Payment deadline updated and payment request sent')
        : __('Payment deadline updated'));
    if (await runRowAction(extendHoldUrlTemplate.value, extendHoldRow.value.id, message, payload)) {
        closeExtendHold();
    }
};

const closeExtendHold = () => {
    extendHoldRow.value = null;
    extendErrors.value = {};
};

const copyLink = (reservation) => copyPaymentLink(reservation.payment_url, toast);
</script>

<template>
    <div class="max-w-page mx-auto">
        <Head :title="__('Reservations')" />

        <Header :title="__('Reservations')" icon="add-item">
            <Button :href="calendarUrl" :text="__('Calendar view')" />
            <Button v-if="createUrl" :href="createUrl" variant="primary" :text="__('Create reservation')" />
        </Header>

        <Listing
            ref="listing"
            :url="listUrl"
            :filters="filters"
            sort-column="created_at"
            sort-direction="desc"
            preferences-prefix="resrv.reservations"
            push-query
        >
            <template #cell-status="{ row: reservation }">
                <div class="flex flex-col items-start gap-1">
                    <div class="flex items-center gap-1.5">
                        <a
                            :href="showUrl(reservation)"
                            :class="statusBadgeClass(reservation.status)"
                            class="inline-block min-w-[100px] whitespace-nowrap text-center px-2 py-1 text-white text-xs uppercase"
                        >
                            {{ rowStatusText(reservation) }}
                        </a>
                        <span
                            v-if="reservation.payment_unresolved"
                            role="img"
                            class="inline-flex"
                            :title="__('The online payment needs checking in your payment provider. Open the reservation for details.')"
                            :aria-label="__('The online payment needs checking in your payment provider. Open the reservation for details.')"
                        >
                            <Icon name="warning-diamond" class="size-4 text-red-600 dark:text-red-400" />
                        </span>
                    </div>
                    <Badge
                        v-if="paymentWindowClosed(reservation)"
                        size="sm"
                        color="red"
                        :text="overdueLabel(reservation)"
                        :title="overdueExplanation(reservation)"
                    />
                </div>
            </template>

            <template #cell-entry="{ row: reservation }">
                <a v-if="reservation.entry?.url" :href="reservation.entry.url" target="_blank">
                    {{ reservation.entry.title }}
                </a>
                <span v-else>{{ reservation.entry?.title }}</span>
            </template>

            <template #cell-customer="{ row: reservation }">
                <a
                    v-if="customerEmail(reservation.customer)"
                    :href="`mailto:${customerEmail(reservation.customer)}`"
                >
                    {{ customerEmail(reservation.customer) }}
                </a>
            </template>

            <template #cell-payment="{ row: reservation }">
                <span class="whitespace-nowrap">{{ reservation.payment }}</span>
                <span v-if="isAwaitingPayment(reservation)" class="ms-1 whitespace-nowrap text-xs text-gray-600 dark:text-gray-400">
                    {{ __('(unpaid)') }}
                </span>
                <span v-else-if="notCollected(reservation)" class="ms-1 whitespace-nowrap text-xs text-gray-600 dark:text-gray-400">
                    {{ __('(not collected)') }}
                </span>
            </template>

            <template #cell-price="{ row: reservation }">
                <span class="whitespace-nowrap">{{ reservation.price }}</span>
            </template>

            <template #cell-payment_gateway="{ row: reservation }">
                {{ reservation.payment_gateway || '-' }}
            </template>

            <template #prepended-row-actions="{ row: reservation }">
                <DropdownItem :text="__('View')" :href="showUrl(reservation)" icon="eye" />
                <DropdownItem
                    v-if="canResend(reservation)"
                    :text="__('Resend confirmation email')"
                    icon="mail"
                    @click="confirmResend(reservation)"
                />
                <DropdownItem
                    v-if="canRefund(reservation)"
                    :text="__('Refund')"
                    icon="return-square"
                    @click="confirmRefund(reservation)"
                />
                <template v-if="isAwaitingPayment(reservation)">
                    <DropdownItem
                        :text="rowMarkAsPaidLabel(reservation)"
                        icon="checkmark"
                        @click="confirmPaymentRow = reservation"
                    />
                    <DropdownItem
                        v-if="!paymentWindowClosed(reservation)"
                        :text="paymentRequestActionLabel(!!reservation.payment_request_email_sent_at)"
                        icon="mail"
                        @click="paymentRequestRow = reservation"
                    />
                    <DropdownItem
                        v-if="reservation.payment_url && !paymentWindowClosed(reservation)"
                        :text="__('Copy payment link')"
                        icon="link"
                        @click="copyLink(reservation)"
                    />
                    <DropdownItem
                        v-if="!reservation.arrival_day_passed"
                        :text="__('Extend hold…')"
                        icon="time-clock"
                        @click="extendHoldRow = reservation"
                    />
                    <DropdownSeparator />
                    <DropdownItem
                        :text="__('Cancel reservation')"
                        icon="x"
                        variant="destructive"
                        @click="cancelAwaitingRow = reservation"
                    />
                </template>
            </template>
        </Listing>

        <ConfirmationModal
            v-if="refundRow"
            :open="true"
            :title="refundHasNoCharge ? __('Cancel reservation') : __('Refund and cancel reservation')"
            :danger="true"
            :button-text="refundHasNoCharge ? __('Cancel reservation') : __('Refund and cancel')"
            :cancel-text="__('Keep reservation')"
            :busy="refunding"
            @confirm="refund"
            @cancel="cancelRefund"
        >
            <ReservationActionSummary :facts="refundFacts" :amount-label="__('Payment')" />
            <p class="mb-2">{{ refundSentence }}</p>
            <p>{{ __('This cannot be undone.') }}</p>
        </ConfirmationModal>

        <ConfirmationModal
            v-if="resendId"
            :open="true"
            :title="__('Resend confirmation email')"
            :button-text="__('Send')"
            :busy="resending"
            @confirm="resend"
            @cancel="cancelResend"
        >
            <p>{{ __('This will email the confirmation again to the customer for this reservation.') }}</p>
        </ConfirmationModal>

        <ConfirmationModal
            v-if="paymentRequestRow"
            :open="true"
            :title="paymentRequestActionLabel(!!paymentRequestRow.payment_request_email_sent_at)"
            :button-text="__('Send')"
            :busy="actionBusy"
            @confirm="sendPaymentRequest"
            @cancel="paymentRequestRow = null"
        >
            <ReservationActionSummary :facts="facts(paymentRequestRow)" />
            <p>{{ paymentRequestConfirmText(facts(paymentRequestRow)) }}</p>
        </ConfirmationModal>

        <ConfirmPaymentModal
            v-if="confirmPaymentRow"
            :busy="actionBusy"
            :facts="facts(confirmPaymentRow)"
            @confirm="confirmPayment"
            @cancel="confirmPaymentRow = null"
        />

        <CancelAwaitingPaymentModal
            v-if="cancelAwaitingRow"
            :busy="actionBusy"
            :facts="facts(cancelAwaitingRow)"
            :affects-availability="cancelAwaitingRow.affects_availability"
            @confirm="cancelAwaiting"
            @cancel="cancelAwaitingRow = null"
        />

        <ExtendHoldModal
            v-if="extendHoldRow"
            :busy="actionBusy"
            :facts="facts(extendHoldRow)"
            :errors="extendErrors"
            @confirm="extendHold"
            @cancel="closeExtendHold"
        />
    </div>
</template>
