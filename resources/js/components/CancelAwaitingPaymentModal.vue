<script setup>
import { ref } from 'vue';
import { Checkbox, ConfirmationModal } from '@statamic/cms/ui';
import ReservationActionSummary from './ReservationActionSummary.vue';

const props = defineProps({
    busy: { type: Boolean, default: false },
    affectsAvailability: { type: Boolean, default: false },
    facts: { type: Object, default: null },
});

const emit = defineEmits(['confirm', 'cancel']);

// Default on only when the customer was ever told about the booking.
const notifyCustomer = ref(!!props.facts?.requestSent);

const confirm = () => emit('confirm', { notify_customer: notifyCustomer.value });
</script>

<template>
    <ConfirmationModal
        :open="true"
        :title="__('Cancel reservation')"
        :danger="true"
        :button-text="__('Cancel reservation')"
        :cancel-text="__('Keep reservation')"
        :busy="busy"
        @confirm="confirm"
        @cancel="$emit('cancel')"
    >
        <ReservationActionSummary :facts="facts" />
        <p v-if="affectsAvailability" class="mb-4">
            {{ __('Cancel this reservation and release its inventory? This cannot be undone.') }}
        </p>
        <p v-else class="mb-4">
            {{ __('Cancel this reservation? It never took inventory, so none will be restored. This cannot be undone.') }}
        </p>
        <Checkbox
            v-model="notifyCustomer"
            :label="__('Email the customer a cancellation notice')"
            :description="facts && !facts.requestSent ? __('No payment request was emailed for this reservation, so the customer may not know about it.') : null"
            :disabled="busy"
        />
    </ConfirmationModal>
</template>
