<template>
    <div>
        <div v-if="selectedItems.length" class="rounded-lg border border-gray-200 dark:border-gray-700/80 divide-y divide-gray-200 dark:divide-gray-700/80 bg-white dark:bg-gray-900/40">
            <div
                v-for="item in selectedItems"
                :key="item.value"
                class="flex items-center justify-between gap-3 py-2 ps-4 pe-2"
            >
                <span class="text-sm text-gray-900 dark:text-gray-100 truncate" :class="{ 'italic text-gray-500 dark:text-gray-400': item.orphan }">
                    {{ item.label }}
                </span>
                <Button
                    icon="trash"
                    variant="ghost"
                    size="sm"
                    :aria-label="__('Remove')"
                    @click="remove(item.value)"
                />
            </div>
        </div>
        <p v-else class="text-sm text-gray-500 dark:text-gray-400">{{ emptyText }}</p>

        <div class="mt-3 flex flex-wrap items-center gap-2">
            <Button :text="linkLabel" icon="link" @click="openPicker" />
            <slot name="actions" />
        </div>

        <Stack
            v-if="stackOpen"
            :open="stackOpen"
            :title="stackTitle"
            :icon="stackIcon"
            size="narrow"
            @closed="stackOpen = false"
        >
            <template #header-actions>
                <Button :text="__('Done')" variant="primary" @click="confirm" />
            </template>
            <template #default>
                <div class="space-y-4">
                    <Input v-model="search" :placeholder="searchPlaceholder" />

                    <div class="flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                        <span>{{ filteredOptions.length }} {{ __('of') }} {{ options.length }}</span>
                        <div class="flex items-center gap-2">
                            <Button size="xs" variant="ghost" :text="__('Select all')" @click="pickAllFiltered" />
                            <span class="text-gray-300 dark:text-gray-600">|</span>
                            <Button size="xs" variant="ghost" :text="__('Clear')" @click="picked = []" />
                        </div>
                    </div>

                    <p v-if="filteredOptions.length === 0" class="text-sm text-gray-500 dark:text-gray-400 text-center py-8">
                        {{ __('No matching entries') }}
                    </p>
                    <div
                        v-else
                        class="rounded-lg border border-gray-200 dark:border-gray-700/80 divide-y divide-gray-200 dark:divide-gray-700/80 bg-white dark:bg-gray-900/40 max-h-[60vh] overflow-y-auto"
                    >
                        <div
                            v-for="option in filteredOptions"
                            :key="option[optionValue]"
                            class="py-2.5 px-4 hover:bg-gray-50 dark:hover:bg-gray-800/50"
                        >
                            <Checkbox
                                :model-value="picked.includes(option[optionValue])"
                                :label="option[optionLabel]"
                                @update:modelValue="toggle(option[optionValue], $event)"
                            />
                        </div>
                    </div>
                </div>
            </template>
        </Stack>
    </div>
</template>

<script setup>
import { Button, Checkbox, Input, Stack } from '@statamic/cms/ui';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    modelValue: { type: Array, default: () => [] },
    options: { type: Array, default: () => [] },
    optionLabel: { type: String, default: 'title' },
    optionValue: { type: String, default: 'id' },
    linkLabel: { type: String, default: () => __('Link entries') },
    stackTitle: { type: String, default: () => __('Select entries') },
    stackIcon: { type: String, default: 'link' },
    emptyText: { type: String, default: () => __('No entries selected') },
    searchPlaceholder: { type: String, default: () => __('Search entries') },
});

const emit = defineEmits(['update:modelValue']);

const stackOpen = ref(false);
const search = ref('');
const picked = ref([]);

const optionsByValue = computed(() => {
    const map = new Map();
    for (const option of props.options) {
        map.set(option[props.optionValue], option);
    }
    return map;
});

const selectedItems = computed(() =>
    (props.modelValue || []).map((value) => {
        const option = optionsByValue.value.get(value);
        return option
            ? { value, label: option[props.optionLabel], orphan: false }
            : { value, label: __('Unknown entry') + ' (' + value + ')', orphan: true };
    }),
);

const filteredOptions = computed(() => {
    const term = search.value.trim().toLowerCase();
    if (!term) return props.options;
    return props.options.filter((option) => {
        const label = option[props.optionLabel];
        return typeof label === 'string' && label.toLowerCase().includes(term);
    });
});

watch(stackOpen, (open) => {
    if (open) {
        picked.value = [...(props.modelValue || [])];
        search.value = '';
    }
});

function openPicker() {
    stackOpen.value = true;
}

function confirm() {
    emit('update:modelValue', [...picked.value]);
    stackOpen.value = false;
}

function toggle(value, checked) {
    const set = new Set(picked.value);
    if (checked) {
        set.add(value);
    } else {
        set.delete(value);
    }
    picked.value = [...set];
}

function pickAllFiltered() {
    const set = new Set(picked.value);
    for (const option of filteredOptions.value) {
        set.add(option[props.optionValue]);
    }
    picked.value = [...set];
}

function remove(value) {
    emit(
        'update:modelValue',
        (props.modelValue || []).filter((v) => v !== value),
    );
}
</script>
