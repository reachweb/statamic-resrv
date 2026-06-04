<script setup>
import { Head } from '@statamic/cms/inertia';
import { Header, Button, Card } from '@statamic/cms/ui';

defineProps({
    indexUrl: { type: String, required: true },
});
</script>

<template>
    <div class="max-w-xl mx-auto">
        <Head :title="__('Resrv Data import')" />

        <Header :title="__('Import availability')">
            <Button :href="indexUrl" variant="primary">
                {{ __('Import again') }}
            </Button>
        </Header>

        <Card class="p-4 lg:px-8 lg:py-6">
            <div class="font-bold text-center text-base">
                {{ __('Import finished!') }}
            </div>
        </Card>
    </div>
</template>
