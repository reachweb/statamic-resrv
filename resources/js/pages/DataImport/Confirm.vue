<script setup>
import { Head, Link } from '@statamic/cms/inertia';
import { Header, Button, Card } from '@statamic/cms/ui';

const props = defineProps({
    errors: { type: Array, default: () => [] },
    sample: { type: Object, default: null },
    storeUrl: { type: String, required: true },
    indexUrl: { type: String, required: true },
});

function goBack() {
    window.history.back();
}
</script>

<template>
    <div class="max-w-xl mx-auto">
        <Head :title="__('Resrv Data import')" />

        <Header :title="__('Import availability')">
            <Button v-if="!errors.length" :href="storeUrl" variant="primary">
                {{ __('Continue') }}
            </Button>
        </Header>

        <Card class="p-4 lg:px-8 lg:py-6">
            <div v-if="errors.length" class="mb-5">
                <div class="font-bold text-base mb-2">
                    {{ __('Please correct the following errors to continue:') }}
                </div>
                <div v-for="(error, idx) in errors" :key="idx" class="mb-1">
                    {{ error }}
                </div>
                <Button class="mt-2" @click="goBack">
                    {{ __('Go Back') }}
                </Button>
            </div>

            <div v-else>
                <div class="font-bold text-base">{{ __('Sample data') }}</div>
                <p class="text-2xs text-gray-500 mt-1 mb-2">
                    {{ __('Please check that the data is correct before you proceed.') }}
                </p>
                <div class="mt-3">
                    <div class="text-sm mb-1">{{ __('Item ID:') }}</div>
                    <div v-for="(rows, id) in sample" :key="id">
                        <div class="mb-3">{{ id }}</div>
                        <div
                            v-for="(value, rowIdx) in rows"
                            :key="rowIdx"
                            class="border-b py-2"
                        >
                            <div class="flex mb-1">
                                <div class="text-sm text-gray-500 mr-2">{{ __('From date') }}</div>
                                <div class="text-sm">{{ value.date_start }}</div>
                            </div>
                            <div class="flex mb-1">
                                <div class="text-sm text-gray-500 mr-2">{{ __('To date') }}</div>
                                <div class="text-sm">{{ value.date_end }}</div>
                            </div>
                            <div class="flex mb-1">
                                <div class="text-sm text-gray-500 mr-2">{{ __('Price') }}</div>
                                <div class="text-sm">{{ value.price }}</div>
                            </div>
                            <div class="flex mb-1">
                                <div class="text-sm text-gray-500 mr-2">{{ __('Available') }}</div>
                                <div class="text-sm">{{ value.available }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </Card>
    </div>
</template>
