<template>
    <Card inset class="overflow-x-auto">
        <table class="w-full text-sm rounded-xl overflow-hidden">
            <thead class="bg-gray-50 dark:bg-gray-800">
                <tr class="border-b border-gray-200 dark:border-gray-700/80">
                    <th
                        v-for="column in columns[tableColumns]"
                        :key="column.field"
                        scope="col"
                        :aria-sort="ariaSortFor(column.field)"
                        class="text-left px-4 py-3 font-medium text-xs uppercase tracking-wide text-gray-600 dark:text-gray-300 cursor-pointer select-none"
                        @click="toggleSort(column.field)"
                    >
                        {{ __(column.label) }}
                        <span v-if="sortColumn === column.field" aria-hidden="true">
                            {{ sortDirection === 'asc' ? '▲' : '▼' }}
                        </span>
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
                    v-for="item in sortedItems"
                    :key="item.id"
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
                        <template v-else-if="column.field === 'commission'">
                            {{ currency }} {{ item.commission }}
                        </template>
                        <template v-else-if="column.field === 'percentage'">
                            {{ Math.round(item.percentage * 100) }}%
                        </template>
                        <template v-else-if="column.field === 'title'">
                            {{ item.title }}
                            <span v-if="item.deleted" class="text-xs text-gray-400 ml-1">({{ __('deleted') }})</span>
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
import { computed, ref } from 'vue';

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
    affiliates: [
        { field: 'title', label: 'Affiliate' },
        { field: 'reservations', label: 'Reservations' },
        { field: 'total_revenue', label: 'Sales' },
        { field: 'commission', label: 'Commission' },
    ],
    dynamic: [
        { field: 'title', label: 'Rule' },
        { field: 'reservations', label: 'Times applied' },
        { field: 'percentage', label: 'Percentage' },
    ],
};

const sortColumn = ref('reservations');
const sortDirection = ref('desc');

function toggleSort(field) {
    if (sortColumn.value === field) {
        sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortColumn.value = field;
        sortDirection.value = 'desc';
    }
}

function ariaSortFor(field) {
    if (sortColumn.value !== field) return 'none';
    return sortDirection.value === 'asc' ? 'ascending' : 'descending';
}

const sortedItems = computed(() => {
    if (!Array.isArray(props.items)) {
        return [];
    }
    const key = sortColumn.value;
    const dir = sortDirection.value === 'asc' ? 1 : -1;
    return [...props.items].sort((a, b) => {
        const av = a[key];
        const bv = b[key];
        if (typeof av === 'number' && typeof bv === 'number') {
            return (av - bv) * dir;
        }
        return String(av ?? '').localeCompare(String(bv ?? '')) * dir;
    });
});
</script>
