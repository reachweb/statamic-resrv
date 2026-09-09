<script setup>
import { ConfirmationModal } from '@statamic/cms/ui';

defineProps({
    busy: { type: Boolean, default: false },
    affectsAvailability: { type: Boolean, default: false },
});

defineEmits(['confirm', 'cancel']);
</script>

<template>
    <ConfirmationModal
        :open="true"
        :title="__('Cancel reservation')"
        :danger="true"
        :button-text="__('Cancel reservation')"
        :busy="busy"
        @confirm="$emit('confirm')"
        @cancel="$emit('cancel')"
    >
        <p v-if="affectsAvailability">
            {{ __('Cancel this reservation and release its inventory? The customer will be notified by email.') }}
        </p>
        <p v-else>
            {{ __('Cancel this reservation? It never took inventory, so none will be restored. The customer will be notified by email.') }}
        </p>
    </ConfirmationModal>
</template>
