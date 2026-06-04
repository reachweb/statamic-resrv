<script setup>
import { Head } from '@statamic/cms/inertia';
import ExtrasList from '../../components/ExtrasList.vue';
</script>

<template>
    <div class="max-w-page mx-auto">
        <Head :title="__('Extras')" />

        <ExtrasList />
    </div>
</template>
