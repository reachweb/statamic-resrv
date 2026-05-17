<template>
    <transition name="fade">
        <div class="absolute h-full w-full left-0 right-0 mx-auto top-0 flex items-center justify-center z-10">
            <div class="px-4 py-2 bg-gray-900/90 dark:bg-gray-700 backdrop-blur-sm shadow-lg text-white rounded-lg flex items-center gap-2 text-sm">
                <Icon name="loading" class="animate-spin size-4" />
                <span v-html="text"></span>
            </div>
        </div>
    </transition>
</template>

<script setup>
import { Icon } from '@statamic/cms/ui';

defineProps({
    text: {
        type: String,
        default: 'Loading...',
    },
});
</script>
