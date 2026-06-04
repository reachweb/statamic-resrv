<script setup>
import { Head } from '@statamic/cms/inertia';
import AffiliatesList from '../../components/AffiliatesList.vue';

defineProps({
    affiliates: { type: Array, default: () => [] },
});
</script>

<template>
    <div class="max-w-page mx-auto">
        <Head :title="__('Affiliates')" />

        <AffiliatesList :affiliates="affiliates" />
    </div>
</template>
