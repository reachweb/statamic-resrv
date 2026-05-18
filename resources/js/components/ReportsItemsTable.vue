<template>
    <Card inset class="overflow-x-auto">
        <table class="w-full text-sm rounded-xl overflow-hidden">
            <thead class="bg-gray-50 dark:bg-gray-800">
                <tr class="border-b border-gray-200 dark:border-gray-700/80">
                    <th
                        v-for="column in columns[tableColumns]"
                        :key="column.field"
                        class="text-left px-4 py-3 font-medium text-xs uppercase tracking-wide text-gray-600 dark:text-gray-300"
                    >
                        {{ __(column.label) }}
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="sortedItems.length === 0">
                    <td :colspan="columns[tableColumns].length" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">
                        {{ __('No data') }}
                    </td>
                </tr>
                <tr
                    v-for="(item, index) in sortedItems"
                    :key="index"
                    class="border-b last:border-b-0 border-gray-200 dark:border-gray-700/80"
                >
                    <td
                        v-for="column in columns[tableColumns]"
                        :key="column.field"
                        class="px-4 py-3 text-gray-900 dark:text-gray-200"
                    >
                        <template v-if="column.field === 'total_revenue'">
                            {{ currency }} {{ item.total_revenue }}
                        </template>
                        <template v-else-if="column.field === 'percentage'">
                            {{ Math.round(item.percentage * 100) }}%
                        </template>
                        <template v-else>
                            {{ item[column.field] }}
                        </template>
                    </td>
                </tr>
            </tbody>
        </table>
    </Card>
</template>

<script setup>
import { Card } from '@statamic/cms/ui';
import { computed } from 'vue';

const props = defineProps({
    items: { type: Array, required: true, default: () => [] },
    tableColumns: { type: String, required: true },
    currency: { type: String, default: '' },
});

const columns = {
    items: [
        { field: 'title', label: 'Title' },
        { field: 'reservations', label: 'Reservations' },
        { field: 'total_revenue', label: 'Revenue' },
        { field: 'percentage', label: 'Percentage' },
    ],
    other: [
        { field: 'title', label: 'Title' },
        { field: 'reservations', label: 'Reservations' },
        { field: 'percentage', label: 'Percentage' },
    ],
};

const sortedItems = computed(() => {
    if (!Array.isArray(props.items)) {
        return [];
    }
    return [...props.items].sort((a, b) => (b.reservations || 0) - (a.reservations || 0));
});
</script>
