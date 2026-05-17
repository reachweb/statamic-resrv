<script setup>
import { Head } from '@statamic/cms/inertia';
import RatesList from '../../components/RatesList.vue';
</script>

<template>
    <div class="max-w-page mx-auto">
        <Head :title="__('Rates')" />

        <RatesList />
    </div>
</template>
