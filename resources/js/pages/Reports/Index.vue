<script setup>
import { Head } from '@statamic/cms/inertia';
import ReportsView from '../../components/ReportsView.vue';

defineProps({
    reportsUrl: { type: String, required: true },
    currency: { type: String, default: '' },
});
</script>

<template>
    <div class="max-w-page mx-auto">
        <Head :title="__('Reports')" />

        <ReportsView :reports-url="reportsUrl" :currency="currency" />
    </div>
</template>
