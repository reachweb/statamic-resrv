<template>
    <div>
        <Header :title="__('Reservations')" icon="add-item" />
        <Card class="text-center py-12">
            <div class="text-base font-medium mb-1 text-gray-900 dark:text-gray-200">{{ __('Reservations list is being rebuilt.') }}</div>
            <p class="text-sm text-gray-500 dark:text-gray-400 max-w-xl mx-auto">
                {{ __('Statamic v6 removed the Listing mixin that powered this view. The reservations list is being reimplemented as an Inertia page in Phase 7.') }}
            </p>
        </Card>
    </div>
</template>

<script setup>
import { Card, Header } from '@statamic/cms/ui';

defineProps({
    reservationsUrl: { type: String, default: '' },
    showRoute: { type: String, default: '' },
    refundRoute: { type: String, default: '' },
});
</script>
