<script setup>
import { Head } from '@statamic/cms/inertia';
import SurchargesList from '../../components/SurchargesList.vue';

defineProps({
    surcharges: { type: Array, default: () => [] },
    options: { type: Array, default: () => [] },
});
</script>

<template>
    <div class="max-w-page mx-auto">
        <Head :title="__('Surcharges')" />

        <SurchargesList :surcharges="surcharges" :options="options" />
    </div>
</template>
