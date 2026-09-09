<script setup>
import { ConfirmationModal } from '@statamic/cms/ui';

defineProps({
    busy: { type: Boolean, default: false },
});

defineEmits(['confirm', 'cancel']);
</script>

<template>
    <ConfirmationModal
        :open="true"
        :title="__('Confirm payment')"
        :button-text="__('Confirm')"
        :busy="busy"
        @confirm="$emit('confirm')"
        @cancel="$emit('cancel')"
    >
        <p>{{ __('Mark this reservation as paid and confirm it? Confirmation emails will be sent.') }}</p>
    </ConfirmationModal>
</template>
