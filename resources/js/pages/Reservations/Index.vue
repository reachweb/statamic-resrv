<script setup>
import { ref } from 'vue';
import axios from 'axios';
import { Head, Link } from '@statamic/cms/inertia';
import {
    Header,
    Button,
    Listing,
    Dropdown,
    DropdownMenu,
    DropdownItem,
    ConfirmationModal,
} from '@statamic/cms/ui';
import { useToast } from '../../composables/useToast.js';

const props = defineProps({
    filters: { type: Array, default: () => [] },
    listUrl: { type: String, required: true },
    showUrlTemplate: { type: String, required: true },
    refundUrl: { type: String, required: true },
    resendUrl: { type: String, required: true },
    calendarUrl: { type: String, required: true },
    createUrl: { type: String, default: null },
});

const toast = useToast();
const listing = ref(null);
const refundId = ref(null);
const refunding = ref(false);
const resendId = ref(null);
const resending = ref(false);

const showUrl = (reservation) => props.showUrlTemplate.replace('RESRVURL', reservation.id);

const canResend = (reservation) => ['confirmed', 'partner'].includes(reservation.status);

// Mirrors the state machine: only these states may transition to REFUNDED/CANCELLED,
// so terminal rows don't offer an action the server will reject anyway.
const canRefund = (reservation) => ['pending', 'confirmed', 'partner'].includes(reservation.status);

const badgeClass = (status) => {
    const map = {
        confirmed: 'bg-green-800',
        partner: 'bg-green-600',
        refunded: 'bg-yellow-800',
        cancelled: 'bg-orange-800',
        expired: 'bg-red-800',
    };
    return map[status] ?? 'bg-gray-800';
};

const customerEmail = (customer) => customer?.email ?? '';

const confirmRefund = (reservation) => {
    refundId.value = reservation.id;
};

const cancelRefund = () => {
    refundId.value = null;
};

const refund = async () => {
    if (! refundId.value) return;
    refunding.value = true;
    try {
        // No-charge bookings (partner / zero payment) end as a cancellation, not a refund —
        // report what the server actually did so admins aren't told money moved.
        const { data } = await axios.patch(props.refundUrl, { id: refundId.value });
        toast.success(
            data?.status === 'cancelled'
                ? __('Reservation cancelled — no charges to refund')
                : __('Reservation refunded'),
        );
        refundId.value = null;
        listing.value?.refresh();
    } catch (error) {
        toast.error(error?.response?.data?.error ?? __('Something went wrong'));
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
                <a
                    :href="showUrl(reservation)"
                    :class="badgeClass(reservation.status)"
                    class="inline-block min-w-[100px] text-center p-1 text-white text-xs"
                >
                    {{ reservation.status.toUpperCase() }}
                </a>
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
            </template>
        </Listing>

        <ConfirmationModal
            v-if="refundId"
            :open="true"
            :title="__('Refund and cancel reservation')"
            :danger="true"
            :button-text="__('Confirm')"
            :busy="refunding"
            @confirm="refund"
            @cancel="cancelRefund"
        >
            <p>{{ __('Are you sure you want to cancel this reservation? This cannot be undone.') }}</p>
            <p>{{ __('Any charges collected will be refunded and the customer will be notified.') }}</p>
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
    </div>
</template>
