<template>
    <div>
        <Header :title="__('Dynamic Pricing')" icon="money-cashier-price-tag">
            <Button
                :text="__('Add Dynamic Pricing')"
                variant="primary"
                icon="plus"
                @click="addPricing"
            />
        </Header>

        <Card inset>
            <div class="flex flex-wrap items-center gap-3 px-4 py-4 border-b border-gray-200 dark:border-gray-700/80">
                <div class="flex-1 min-w-[220px]">
                    <Input
                        v-model="searchQuery"
                        type="search"
                        icon-prepend="magnifying-glass"
                        :placeholder="__('Search by title')"
                    />
                </div>
                <div class="min-w-[170px]">
                    <Select
                        v-model="filters.operation"
                        :options="operationOptions"
                        :clearable="true"
                        :placeholder="__('Any operation')"
                    />
                </div>
                <div class="min-w-[200px]">
                    <Select
                        v-model="filters.dates_active"
                        :options="datesActiveOptions"
                        :clearable="true"
                        :placeholder="__('Any dates')"
                    />
                </div>
                <div class="min-w-[210px]">
                    <Select
                        v-model="filters.condition"
                        :options="conditionOptions"
                        :clearable="true"
                        :placeholder="__('Any condition')"
                    />
                </div>
                <Button
                    v-if="hasActiveFilters"
                    variant="ghost"
                    size="sm"
                    :text="__('Reset all')"
                    @click="resetFilters"
                />
            </div>

            <p v-if="hasActiveFilters" class="text-xs text-gray-600 dark:text-gray-400 px-4 pt-3">
                {{ __('Drag to reorder is disabled while filters are active. Use "Move to position" or reset filters.') }}
            </p>

            <div class="relative">
                <div v-if="loading" class="absolute inset-0 z-10 flex items-center justify-center bg-white/60 dark:bg-gray-900/60 rounded-b-xl">
                    <span class="text-sm text-gray-700 dark:text-gray-300">{{ __('Loading…') }}</span>
                </div>
                <div v-if="dynamicPricings.length === 0" class="p-10 text-center text-gray-500 dark:text-gray-400">
                    {{ __('No dynamic pricing found') }}
                </div>
                <draggable
                    v-else
                    class="p-4 space-y-2"
                    v-model="dynamicPricings"
                    item-key="id"
                    :disabled="hasActiveFilters"
                    @start="drag = true"
                    @end="drag = false"
                    @change="onDragChange"
                >
                    <template #item="{ element: dynamic }">
                        <div
                            class="w-full flex flex-wrap items-center justify-between p-3 rounded-lg border bg-white shadow-ui-sm dark:bg-gray-850 border-gray-200 dark:border-gray-700/80 transition-colors"
                            :class="{ 'cursor-move hover:bg-gray-50 dark:hover:bg-gray-800': !hasActiveFilters }"
                        >
                            <div class="flex items-center gap-2">
                                <StatusIndicator :status="dynamic.published ? 'published' : 'draft'" />
                                <span class="text-xs font-mono px-2 py-0.5 rounded-md bg-gray-150 text-gray-700 dark:bg-gray-800 dark:text-gray-300" :title="__('Order')">#{{ dynamic.order }}</span>
                                <span class="font-medium cursor-pointer text-gray-900 dark:text-gray-200 hover:underline" v-html="dynamic.title" @click="editPricing(dynamic)"></span>
                                <Badge v-if="dynamic.overrides_all" :text="__('OVERRIDING')" variant="warning" size="sm" />
                            </div>
                            <div>
                                <Dropdown>
                                    <DropdownMenu>
                                        <DropdownItem :text="__('Edit')" icon="pencil" @click="editPricing(dynamic)" />
                                        <DropdownItem :text="__('Move to position…')" icon="arrow-up-down" @click="openMoveDialog(dynamic, 'position')" />
                                        <DropdownItem :text="__('Move to page…')" icon="arrow-right" @click="openMoveDialog(dynamic, 'page')" />
                                        <DropdownSeparator />
                                        <DropdownItem :text="__('Delete')" icon="trash" variant="destructive" @click="confirmDelete(dynamic)" />
                                    </DropdownMenu>
                                </Dropdown>
                            </div>
                        </div>
                    </template>
                </draggable>
            </div>
        </Card>

        <DynamicPricingPanel
            v-if="showPanel"
            :data="dynamic"
            :timezone="timezone"
            @closed="togglePanel"
            @saved="togglePanel"
        />
        <confirmation-modal
            v-if="deleteId"
            :title="__('Delete dynamic pricing')"
            :danger="true"
            @confirm="deleteDynamic"
            @cancel="deleteId = null"
        >
            {{ __('Are you sure you want to delete this dynamic pricing?') }} <strong>{{ __('This cannot be undone.') }}</strong>
        </confirmation-modal>
        <confirmation-modal
            v-if="moveDialog.open"
            :title="moveDialog.mode === 'position' ? __('Move to position') : __('Move to page')"
            :button-text="__('Move')"
            @confirm="submitMove"
            @cancel="closeMoveDialog"
        >
            <div class="mb-2">
                <span v-if="moveDialog.mode === 'position'">
                    {{ __('Enter the absolute order number (1 is the top of the list).') }}
                </span>
                <span v-else>
                    {{ __('Enter the page number. The item will land at the top of that page.') }}
                </span>
            </div>
            <Input type="number" min="1" v-model.number="moveDialog.value" />
            <p v-if="moveDialog.mode === 'page'" class="text-xs text-gray-600 dark:text-gray-400 mt-2">
                {{ __('Resolves to position') }}: {{ resolvedMoveOrder }}
            </p>
        </confirmation-modal>
    </div>
</template>

<script setup>
import { Badge, Button, Card, Dropdown, DropdownItem, DropdownMenu, DropdownSeparator, Header, Input, Select, StatusIndicator } from '@statamic/cms/ui';
import { router } from '@statamic/cms/inertia';
import draggable from 'vuedraggable';
import { computed, nextTick, reactive, ref, watch } from 'vue';
import axios from 'axios';
import DynamicPricingPanel from './DynamicPricingPanel.vue';
import { useToast } from '../composables/useToast.js';

const props = defineProps({
    timezone: { type: String, required: true, default: 'UTC' },
    filters: { type: Object, default: () => ({}) },
    pricings: {
        type: Object,
        default: () => ({ data: [], current_page: 1, last_page: 1, per_page: 25, total: 0 }),
    },
});

const toast = useToast();

const showPanel = ref(false);
const dynamicPricings = ref(props.pricings.data ?? []);
const loading = ref(false);
const deleteId = ref(null);
const dynamic = ref({});
const drag = ref(false);
const searchQuery = ref(props.filters.search ?? '');
const filters = reactive({
    operation: props.filters.operation ?? '',
    dates_active: props.filters.dates_active ?? '',
    condition: props.filters.condition ?? '',
});
const currentPage = ref(props.pricings.current_page ?? 1);
const perPage = ref(props.pricings.per_page ?? 25);
const total = computed(() => props.pricings.total ?? 0);
const lastPage = computed(() => props.pricings.last_page ?? 1);
const resetting = ref(false);
let searchDebounce = null;

const moveDialog = reactive({ open: false, mode: null, item: null, value: 1 });

const operationOptions = [
    { value: 'increase', label: 'Increase' },
    { value: 'decrease', label: 'Decrease' },
    { value: 'minimum', label: 'Minimum' },
    { value: 'maximum', label: 'Maximum' },
];
const datesActiveOptions = [
    { value: 'active', label: 'Currently active' },
    { value: 'upcoming', label: 'Upcoming' },
    { value: 'expired', label: 'Expired' },
    { value: 'always', label: 'Always-on (no dates)' },
];
const conditionOptions = [
    { value: 'reservation_duration', label: 'Reservation duration' },
    { value: 'reservation_price', label: 'Reservation price' },
    { value: 'days_to_reservation', label: 'Days to reservation' },
    { value: 'none', label: 'No condition' },
];

const emptyDynamic = {
    title: '', amount: '', amount_type: '', amount_operation: '',
    date_start: '', date_end: '', date_include: '',
    condition_type: '', condition_comparison: '', condition_value: '',
    entries: [], extras: [], coupon: '', expire_at: '', overrides_all: false,
    published: true,
};

const hasActiveFilters = computed(() =>
    !!searchQuery.value || !!filters.operation || !!filters.dates_active || !!filters.condition,
);
const resolvedMoveOrder = computed(() => {
    if (moveDialog.mode !== 'page') return null;
    const page = Math.min(Math.max(1, parseInt(moveDialog.value) || 1), lastPage.value);
    return (page - 1) * perPage.value + 1;
});

watch(() => props.pricings?.data, (newData) => {
    dynamicPricings.value = newData ?? [];
});

watch(() => props.pricings?.current_page, (val) => {
    if (val !== undefined) currentPage.value = val;
});

watch(searchQuery, () => {
    if (resetting.value) return;
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => {
        currentPage.value = 1;
        applyFilters();
    }, 300);
});

watch(filters, () => {
    if (resetting.value) return;
    currentPage.value = 1;
    applyFilters();
});

function togglePanel() {
    showPanel.value = !showPanel.value;
}

function addPricing() {
    dynamic.value = { ...emptyDynamic };
    togglePanel();
}

function editPricing(item) {
    dynamic.value = item;
    togglePanel();
}

function applyFilters() {
    const data = {
        page: currentPage.value,
        per_page: perPage.value,
    };
    if (searchQuery.value) data.search = searchQuery.value;
    if (filters.operation) data.operation = filters.operation;
    if (filters.dates_active) data.dates_active = filters.dates_active;
    if (filters.condition) data.condition = filters.condition;

    router.reload({
        only: ['pricings', 'filters'],
        data,
        preserveState: true,
        preserveScroll: true,
        onStart: () => { loading.value = true; },
        onFinish: () => { loading.value = false; },
    });
}

function resetFilters() {
    resetting.value = true;
    clearTimeout(searchDebounce);
    searchQuery.value = '';
    filters.operation = '';
    filters.dates_active = '';
    filters.condition = '';
    currentPage.value = 1;
    nextTick(() => {
        resetting.value = false;
        applyFilters();
    });
}

function confirmDelete(item) {
    deleteId.value = item.id;
}

function deleteDynamic() {
    axios.delete('/cp/resrv/dynamicpricing', { data: { id: deleteId.value } })
        .then(() => {
            toast.success('Dynamic pricing deleted');
            deleteId.value = null;
            applyFilters();
        })
        .catch(() => {
            toast.error('Cannot delete dynamic pricing');
        });
}

function onDragChange(event) {
    if (!event.moved) return;
    const { newIndex, oldIndex } = event.moved;
    const item = event.moved.element;
    let neighbour;
    if (newIndex < oldIndex) {
        neighbour = dynamicPricings.value[newIndex + 1];
    } else if (newIndex > oldIndex) {
        neighbour = dynamicPricings.value[newIndex - 1];
    } else {
        return;
    }
    if (!neighbour) return;
    patchOrder(item.id, neighbour.order);
}

function patchOrder(id, order) {
    axios.patch('/cp/resrv/dynamicpricing/order', { id, order })
        .then(() => {
            toast.success('Dynamic pricing order changed');
            applyFilters();
        })
        .catch(() => {
            toast.error('Dynamic pricing ordering failed');
        });
}

function openMoveDialog(item, mode) {
    moveDialog.open = true;
    moveDialog.mode = mode;
    moveDialog.item = item;
    moveDialog.value = mode === 'page' ? currentPage.value : item.order;
}

function closeMoveDialog() {
    moveDialog.open = false;
    moveDialog.mode = null;
    moveDialog.item = null;
    moveDialog.value = 1;
}

function submitMove() {
    let value = Math.max(1, parseInt(moveDialog.value) || 1);
    if (moveDialog.mode === 'page') {
        value = Math.min(value, lastPage.value);
    }
    const order = moveDialog.mode === 'page' ? (value - 1) * perPage.value + 1 : value;
    const id = moveDialog.item.id;
    closeMoveDialog();
    patchOrder(id, order);
}
</script>
