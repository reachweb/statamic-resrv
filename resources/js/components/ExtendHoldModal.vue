<script setup>
import { computed, ref } from 'vue';
import { Alert, Checkbox, ConfirmationModal, Description, Field, Input } from '@statamic/cms/ui';
import ReservationActionSummary from './ReservationActionSummary.vue';
import { arrivalDayEnd, formatInstant, formatWallDate } from '../composables/useReservationDisplay.js';

const MAX_DAYS = 365;

const props = defineProps({
    busy: { type: Boolean, default: false },
    facts: { type: Object, default: null },
    errors: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['confirm', 'cancel']);

const holdDays = ref('3');
const noDeadline = ref(false);
const resend = ref(!!props.facts?.requestSent);

const parsedDays = computed(() => {
    const raw = String(holdDays.value ?? '').trim();
    if (!/^\d+$/.test(raw)) return null;
    const days = Number(raw);
    return days >= 1 && days <= MAX_DAYS ? days : null;
});

const daysError = computed(() => {
    if (noDeadline.value) return null;
    if (parsedDays.value === null) return __('Enter a whole number of days between 1 and :max.', { max: MAX_DAYS });
    return props.errors?.hold_days?.[0] ?? null;
});

const newDeadlineDate = computed(() => {
    if (noDeadline.value || parsedDays.value === null) return null;
    return new Date(Date.now() + parsedDays.value * 86400000);
});

const newDeadline = computed(() => (newDeadlineDate.value ? formatInstant(newDeadlineDate.value) : null));

// Payment closes at the end of the arrival day, so a later deadline never gets to cancel the hold.
const deadlineAfterArrival = computed(() => {
    if (!newDeadlineDate.value || props.facts?.arrivalDayPassed) return false;
    const cutoff = arrivalDayEnd(props.facts?.arrivalDate);
    return cutoff !== null && newDeadlineDate.value > cutoff;
});

const arrivalDateText = computed(() => formatWallDate(props.facts?.arrivalDate) ?? props.facts?.dateStart ?? '');

const currentDeadlineSentence = computed(() => {
    const deadline = props.facts?.holdExpiresAt;
    if (!deadline) return __('This reservation has no payment deadline at the moment.');
    if (props.facts?.holdDeadlinePassed) return __('The payment deadline passed on :deadline.', { deadline });
    return __('The current payment deadline is :deadline.', { deadline });
});

const resendLabel = computed(() => {
    if (noDeadline.value) {
        return props.facts?.requestSent
            ? __('Email the payment request again (no deadline)')
            : __('Email the payment request to the customer (no deadline)');
    }
    return props.facts?.requestSent
        ? __('Email the payment request again with the new deadline')
        : __('Email the payment request to the customer');
});

const confirmText = computed(() => (noDeadline.value ? __('Remove deadline') : __('Save deadline')));

const canSubmit = computed(() => noDeadline.value || parsedDays.value !== null);

const confirm = () => {
    if (!canSubmit.value) return;
    emit('confirm', {
        hold_days: noDeadline.value ? null : parsedDays.value,
        resend: resend.value,
    });
};
</script>

<template>
    <ConfirmationModal
        :open="true"
        :title="__('Extend payment hold')"
        :button-text="confirmText"
        :disabled="!canSubmit"
        :busy="busy"
        @confirm="confirm"
        @cancel="$emit('cancel')"
    >
        <ReservationActionSummary :facts="facts" />
        <p class="mb-4">{{ currentDeadlineSentence }}</p>

        <div class="space-y-4">
            <Field
                id="resrv-extend-hold-days"
                :label="__('Hold for (days from now)')"
                :error="daysError ?? undefined"
                :disabled="noDeadline"
            >
                <Input
                    id="resrv-extend-hold-days"
                    v-model="holdDays"
                    type="number"
                    min="1"
                    :max="MAX_DAYS"
                    step="1"
                    :disabled="noDeadline || busy"
                />
            </Field>
            <Description v-if="newDeadline">
                {{ __('The new deadline will be :deadline.', { deadline: newDeadline }) }}
            </Description>
            <Alert v-if="deadlineAfterArrival" variant="warning">
                <p>{{ __('The hold ends after the arrival day (:date). The customer cannot pay once the arrival day has passed, so a shorter hold is usually better.', { date: arrivalDateText }) }}</p>
            </Alert>
            <Description v-if="facts?.arrivalDayPassed">
                {{ __('The arrival date has already passed, so the customer cannot pay online even with a new deadline.') }}
            </Description>

            <Checkbox
                v-model="noDeadline"
                :label="__('Remove the deadline')"
                :description="__('The reservation stays on hold until you mark it as paid or cancel it.')"
                :disabled="busy"
            />
            <Checkbox
                v-model="resend"
                :label="resendLabel"
                :description="resend && facts?.customerEmail ? __('Will be sent to :email.', { email: facts.customerEmail }) : null"
                :disabled="busy"
            />
        </div>
    </ConfirmationModal>
</template>
