<script setup>
import { Head } from '@statamic/cms/inertia';
import ReportsView from '../../components/ReportsView.vue';

defineProps({
    report: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    currency: { type: String, default: '' },
});
</script>

<template>
    <div class="max-w-page mx-auto">
        <Head :title="__('Reports')" />

        <ReportsView
            :report="report"
            :filters="filters"
            :currency="currency"
        />
    </div>
</template>
