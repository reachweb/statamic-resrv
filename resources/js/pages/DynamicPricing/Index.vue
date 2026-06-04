<script setup>
import { Head } from '@statamic/cms/inertia';
import DynamicPricingList from '../../components/DynamicPricingList.vue';

defineProps({
    timezone: { type: String, default: 'UTC' },
    filters: { type: Object, default: () => ({}) },
    pricings: {
        type: Object,
        default: () => ({ data: [], current_page: 1, last_page: 1, per_page: 25, total: 0 }),
    },
});
</script>

<template>
    <div class="max-w-page mx-auto">
        <Head :title="__('Dynamic Pricing')" />

        <DynamicPricingList
            :timezone="timezone"
            :filters="filters"
            :pricings="pricings"
        />
    </div>
</template>
