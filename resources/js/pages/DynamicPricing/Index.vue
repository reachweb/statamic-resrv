<script setup>
import { Head } from '@statamic/cms/inertia';
import DynamicPricingList from '../../components/DynamicPricingList.vue';

defineProps({
    timezone: { type: String, default: 'UTC' },
});
</script>

<template>
    <div class="max-w-page mx-auto">
        <Head :title="__('Dynamic Pricing')" />

        <DynamicPricingList :timezone="timezone" />
    </div>
</template>
