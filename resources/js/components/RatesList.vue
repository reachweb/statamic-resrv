<template>
    <div>
        <Header :title="__('Rates')" icon="money-cashier-price-tag">
            <Button v-if="selectedCollection" :text="__('Add rate')" variant="primary" icon="plus" @click="add" />
        </Header>

        <Card class="mb-6">
            <Field :label="__('Collection')" :instructions="__('Choose a collection to manage rates for.')">
                <Select
                    v-model="selectedCollection"
                    :options="collectionOptions"
                    :clearable="false"
                    :placeholder="__('Select a collection...')"
                    @update:modelValue="collectionChanged"
                />
            </Field>
        </Card>

        <div v-if="selectedCollection">
            <draggable
                v-if="tree.length > 0"
                v-model="tree"
                item-key="id"
                :group="{ name: 'rate-parents', pull: false, put: false }"
                handle=".rate-drag-handle"
                :animation="200"
                ghost-class="ghost"
                :disabled="disableDrag"
                class="space-y-2"
                @change="reorderRate"
            >
                <template #item="{ element: parent }">
                    <div>
                        <div class="w-full flex flex-wrap items-center justify-between p-3 rounded-lg border bg-white shadow-ui-sm dark:bg-gray-850 dark:border-gray-700/80">
                            <div class="flex items-center gap-2">
                                <button
                                    type="button"
                                    class="rate-drag-handle cursor-move text-gray-400 hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300"
                                    :aria-label="__('Drag to reorder')"
                                >
                                    <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 7 17">
                                        <g fill="currentColor" fill-rule="evenodd">
                                            <rect width="2" height="2" rx="1"/>
                                            <rect width="2" height="2" y="5" rx="1"/>
                                            <rect width="2" height="2" y="10" rx="1"/>
                                            <rect width="2" height="2" y="15" rx="1"/>
                                            <rect width="2" height="2" x="5" rx="1"/>
                                            <rect width="2" height="2" x="5" y="5" rx="1"/>
                                            <rect width="2" height="2" x="5" y="10" rx="1"/>
                                            <rect width="2" height="2" x="5" y="15" rx="1"/>
                                        </g>
                                    </svg>
                                </button>
                                <StatusIndicator :status="parent.published ? 'published' : 'draft'" />
                                <span class="font-medium cursor-pointer text-gray-900 dark:text-gray-200 hover:underline" v-html="parent.title" @click="edit(parent)"></span>
                                <Badge v-if="parent.apply_to_all" :text="__('All entries')" size="sm" />
                            </div>
                            <div class="flex items-center gap-2">
                                <Badge
                                    v-if="parent.children.length > 0"
                                    :text="__n(':count derived rate|:count derived rates', parent.children.length, { count: parent.children.length })"
                                    size="sm"
                                />
                                <Dropdown>
                                    <DropdownMenu>
                                        <DropdownItem :text="__('Edit')" icon="pencil" @click="edit(parent)" />
                                        <DropdownSeparator />
                                        <DropdownItem :text="__('Delete')" icon="trash" variant="destructive" @click="confirmDelete(parent)" />
                                    </DropdownMenu>
                                </Dropdown>
                            </div>
                        </div>
                        <div v-if="parent.children.length > 0" class="ms-8 mt-2">
                            <draggable
                                v-model="parent.children"
                                item-key="id"
                                :group="{ name: 'rate-children-' + parent.id, pull: false, put: false }"
                                handle=".rate-drag-handle"
                                :animation="200"
                                ghost-class="ghost"
                                :disabled="disableDrag"
                                class="space-y-2"
                                @change="reorderRate"
                            >
                                <template #item="{ element: child }">
                                    <div class="w-full flex flex-wrap items-center justify-between p-3 rounded-lg border bg-white shadow-ui-sm dark:bg-gray-850 dark:border-gray-700/80">
                                        <div class="flex items-center gap-2">
                                            <button
                                                type="button"
                                                class="rate-drag-handle cursor-move text-gray-400 hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300"
                                                :aria-label="__('Drag to reorder')"
                                            >
                                                <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 7 17">
                                                    <g fill="currentColor" fill-rule="evenodd">
                                                        <rect width="2" height="2" rx="1"/>
                                                        <rect width="2" height="2" y="5" rx="1"/>
                                                        <rect width="2" height="2" y="10" rx="1"/>
                                                        <rect width="2" height="2" y="15" rx="1"/>
                                                        <rect width="2" height="2" x="5" rx="1"/>
                                                        <rect width="2" height="2" x="5" y="5" rx="1"/>
                                                        <rect width="2" height="2" x="5" y="10" rx="1"/>
                                                        <rect width="2" height="2" x="5" y="15" rx="1"/>
                                                    </g>
                                                </svg>
                                            </button>
                                            <StatusIndicator :status="child.published ? 'published' : 'draft'" />
                                            <span class="font-medium cursor-pointer text-gray-900 dark:text-gray-200 hover:underline" v-html="child.title" @click="edit(child)"></span>
                                            <Badge v-if="child.apply_to_all" :text="__('All entries')" size="sm" />
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <Badge v-if="child.pricing_type === 'relative'" :text="__('Relative')" size="sm" variant="info" />
                                            <Badge v-if="child.availability_type === 'shared'" :text="__('Shared')" size="sm" variant="info" />
                                            <Dropdown>
                                                <DropdownMenu>
                                                    <DropdownItem :text="__('Edit')" icon="pencil" @click="edit(child)" />
                                                    <DropdownSeparator />
                                                    <DropdownItem :text="__('Delete')" icon="trash" variant="destructive" @click="confirmDelete(child)" />
                                                </DropdownMenu>
                                            </Dropdown>
                                        </div>
                                    </div>
                                </template>
                            </draggable>
                        </div>
                    </div>
                </template>
            </draggable>

            <Card v-else inset>
                <div class="p-10 text-center text-gray-500 dark:text-gray-400">
                    {{ __('No rates found for this collection. Add one to get started.') }}
                </div>
            </Card>
        </div>

        <RatePanel
            v-if="showPanel"
            :data="rate"
            :all-rates="props.rates"
            :collections="props.collections"
            :selected-collection="selectedCollection"
            @closed="togglePanel"
            @saved="togglePanel"
        />
        <confirmation-modal
            :open="deleteId !== null"
            :title="__('Delete rate')"
            :danger="true"
            @confirm="deleteRate"
            @cancel="deleteId = null"
        >
            {{ __('Are you sure you want to delete this rate?') }} <strong>{{ __('This cannot be undone.') }}</strong>
        </confirmation-modal>
    </div>
</template>

<script setup>
import { Badge, Button, Card, Dropdown, DropdownItem, DropdownMenu, DropdownSeparator, Field, Header, Select, StatusIndicator } from '@statamic/cms/ui';
import { router } from '@statamic/cms/inertia';
import draggable from 'vuedraggable';
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import RatePanel from './RatePanel.vue';
import { useToast } from '../composables/useToast.js';

const props = defineProps({
    collections: { type: Array, required: true },
    initialSelectedCollection: { type: String, default: null },
    rates: { type: Array, default: () => [] },
});

const toast = useToast();

const showPanel = ref(false);
const tree = ref([]);
const selectedCollection = ref(props.initialSelectedCollection);
const deleteId = ref(null);
const disableDrag = ref(false);
const rate = ref({});

const collectionOptions = computed(() =>
    props.collections.map((c) => ({ value: c.handle, label: c.title })),
);

watch(() => props.rates, buildTree, { immediate: true });

function togglePanel() {
    showPanel.value = !showPanel.value;
}

function add() {
    rate.value = {
        collection: selectedCollection.value,
        apply_to_all: true,
        entries: [],
        title: '',
        slug: '',
        description: '',
        pricing_type: 'independent',
        base_rate_id: null,
        modifier_type: null,
        modifier_operation: null,
        modifier_amount: null,
        availability_type: 'independent',
        require_price_override: false,
        max_available: null,
        date_start: null,
        date_end: null,
        min_days_before: null,
        max_days_before: null,
        min_stay: null,
        max_stay: null,
        refundable: true,
        published: true,
    };
    togglePanel();
}

function edit(item) {
    rate.value = item;
    togglePanel();
}

function collectionChanged() {
    if (selectedCollection.value) {
        refreshRates();
    }
}

function refreshRates() {
    router.reload({
        only: ['rates'],
        data: { collection: selectedCollection.value },
        preserveState: true,
        preserveScroll: true,
    });
}

function buildTree() {
    const topLevel = (props.rates ?? [])
        .filter((r) => !r.base_rate_id)
        .sort((a, b) => a.order - b.order);
    tree.value = topLevel.map((parent) => ({
        ...parent,
        children: (props.rates ?? [])
            .filter((r) => String(r.base_rate_id) === String(parent.id))
            .sort((a, b) => a.order - b.order),
    }));
}

function confirmDelete(item) {
    deleteId.value = item.id;
}

function deleteRate() {
    axios.delete('/cp/resrv/rate/' + deleteId.value)
        .then(() => {
            toast.success('Rate deleted');
            deleteId.value = null;
            refreshRates();
        })
        .catch((error) => {
            if (error.response && error.response.status === 422) {
                toast.error(error.response.data.message);
            } else {
                toast.error('Cannot delete rate');
            }
            deleteId.value = null;
        });
}

function reorderRate(event) {
    if (!event.moved) {
        return;
    }
    disableDrag.value = true;
    const item = event.moved.element;
    const newOrder = event.moved.newIndex + 1;
    axios.patch(`/cp/resrv/rate/order/${item.id}`, { order: newOrder })
        .then(() => {
            toast.success('Rates order changed');
        })
        .catch(() => {
            toast.error('Rates ordering failed');
        })
        .finally(() => {
            refreshRates();
            disableDrag.value = false;
        });
}
</script>

<style scoped>
.ghost {
    opacity: 0.5;
    background: #c8ebfb;
}
</style>
