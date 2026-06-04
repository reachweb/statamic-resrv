<script setup>
import { Head } from '@statamic/cms/inertia';
import ReservationsExport from '../../components/ReservationsExport.vue';

const props = defineProps({
    fields: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    entries: { type: Array, default: () => [] },
    affiliates: { type: Array, default: () => [] },
    countUrl: { type: String, required: true },
    downloadUrl: { type: String, required: true },
});
</script>

<template>
    <div class="max-w-page mx-auto">
        <Head :title="__('Export Reservations')" />

        <ReservationsExport
            :count-url="countUrl"
            :download-url="downloadUrl"
            :fields="fields"
            :statuses="statuses"
            :entries="entries"
            :affiliates="affiliates"
        />
    </div>
</template>
