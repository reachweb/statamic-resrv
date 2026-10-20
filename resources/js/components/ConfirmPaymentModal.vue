<script setup>
import { computed } from 'vue';
import { ConfirmationModal } from '@statamic/cms/ui';
import ReservationActionSummary from './ReservationActionSummary.vue';

const props = defineProps({
    busy: { type: Boolean, default: false },
    facts: { type: Object, default: null },
});

defineEmits(['confirm', 'cancel']);

// The booking records payment + surcharge as received, so the sentence names that figure.
const recordSentence = computed(() => {
    const { amount, total, chargeAmount, surcharge } = props.facts ?? {};
    if (!amount) return __('Mark this reservation as paid and confirm it?');
    if (chargeAmount && surcharge) {
        return __('Record :amount (including a :surcharge payment surcharge) as received and confirm the reservation?', { amount: chargeAmount, surcharge });
    }
    if (total && total !== amount) {
        return __('Record :amount of the :total total as received and confirm the reservation?', { amount, total });
    }
    return __('Record :amount as received and confirm the reservation?', { amount });
});

const emailSentence = computed(() =>
    props.facts?.customerEmail
        ? __('A confirmation email will be sent to :email, along with your usual admin notifications.', { email: props.facts.customerEmail })
        : __('Confirmation emails will be sent.'),
);
</script>

<template>
    <ConfirmationModal
        :open="true"
        :title="__('Mark as paid')"
        :button-text="__('Mark as paid')"
        :busy="busy"
        @confirm="$emit('confirm')"
        @cancel="$emit('cancel')"
    >
        <ReservationActionSummary :facts="facts" />
        <p class="mb-2">{{ recordSentence }}</p>
        <p v-if="facts?.manualConfirmation === false" class="mb-2">
            {{ __('Only do this if the customer paid you another way. The payment link will stop accepting payments, and any online payment the customer started will be cancelled.') }}
        </p>
        <p>{{ emailSentence }}</p>
    </ConfirmationModal>
</template>
