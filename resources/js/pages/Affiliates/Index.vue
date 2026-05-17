<script setup>
import { Head } from '@statamic/cms/inertia';
import AffiliatesList from '../../components/AffiliatesList.vue';
</script>

<template>
    <div class="max-w-page mx-auto">
        <Head :title="__('Affiliates')" />

        <AffiliatesList />
    </div>
</template>
