<script setup>
import { Head } from '@statamic/cms/inertia';
import RatesList from '../../components/RatesList.vue';

defineProps({
    collections: { type: Array, required: true },
    selectedCollection: { type: String, default: null },
    rates: { type: Array, default: () => [] },
});
</script>

<template>
    <div class="max-w-page mx-auto">
        <Head :title="__('Rates')" />

        <RatesList
            :collections="collections"
            :initial-selected-collection="selectedCollection"
            :rates="rates"
        />
    </div>
</template>
