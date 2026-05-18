<template>
    <div>
        <div v-if="localValues">
            <draggable
                v-model="localValues"
                item-key="id"
                @start="drag = true"
                @end="drag = false"
                @change="order"
            >
                <template #item="{ element: value }">
                    <div class="w-full flex items-center text-sm justify-between px-3 py-2 border-t border-gray-200 dark:border-gray-700/80">
                        <div class="flex items-center gap-2">
                            <StatusIndicator :status="value.published ? 'published' : 'draft'" />
                            <span class="font-medium cursor-pointer text-gray-900 dark:text-gray-200 hover:underline" v-html="value.name" @click="edit(value)"></span>
                            <span v-if="value.price_type !== 'free'" class="text-gray-700 dark:text-gray-400">
                                {{ value.price }}
                                <span class="text-xs text-gray-500" v-html="priceLabel(value.price_type)"></span>
                            </span>
                            <Badge v-else :text="__('Free')" size="sm" variant="success" />
                        </div>
                        <Dropdown>
                            <DropdownMenu>
                                <DropdownItem :text="__('Edit')" icon="pencil" @click="edit(value)" />
                                <DropdownSeparator />
                                <DropdownItem :text="__('Delete')" icon="trash" variant="destructive" @click="confirmDelete(value)" />
                            </DropdownMenu>
                        </Dropdown>
                    </div>
                </template>
            </draggable>
        </div>
        <div class="mt-2">
            <Button size="sm" :text="__('Add value')" variant="default" icon="plus" @click="add" />
        </div>
        <OptionValuesPanel
            v-if="showPanel"
            :data="value"
            :parent="parent"
            @closed="togglePanel"
            @saved="dataSaved"
        />
        <confirmation-modal
            v-if="deleteId"
            :title="__('Delete value')"
            :danger="true"
            @confirm="deleteValue"
            @cancel="deleteId = null"
        >
            {{ __('Are you sure you want to delete this option?') }} <strong>{{ __('This cannot be undone.') }}</strong>
        </confirmation-modal>
    </div>
</template>

<script setup>
import { Badge, Button, Dropdown, DropdownItem, DropdownMenu, DropdownSeparator, StatusIndicator } from '@statamic/cms/ui';
import draggable from 'vuedraggable';
import { ref, watch } from 'vue';
import axios from 'axios';
import OptionValuesPanel from './OptionValuesPanel.vue';
import { useToast } from '../composables/useToast.js';

const props = defineProps({
    values: { type: [Array, Object], required: true },
    parent: { type: String, required: true },
});

const emit = defineEmits(['saved']);
const toast = useToast();

const showPanel = ref(false);
const deleteId = ref(null);
const drag = ref(false);
const value = ref({});

const localValues = ref(initialValues());

const emptyValue = {
    name: '',
    slug: '',
    price: '',
    price_type: '',
    option_id: props.parent,
    description: '',
    published: true,
};

function initialValues() {
    if (Array.isArray(props.values)) {
        return [...props.values];
    }
    return Object.values(props.values || {});
}

watch(() => props.values, () => {
    localValues.value = initialValues();
}, { deep: true });

function togglePanel() {
    showPanel.value = !showPanel.value;
}

function priceLabel(code) {
    if (code === 'perday') {
        return '/ day';
    }
    if (code === 'fixed') {
        return '/ reservation';
    }
    return '';
}

function add() {
    value.value = { ...emptyValue };
    togglePanel();
}

function edit(item) {
    value.value = item;
    togglePanel();
}

function dataSaved() {
    togglePanel();
    emit('saved');
}

function confirmDelete(item) {
    deleteId.value = item.id;
}

function deleteValue() {
    axios.delete('/cp/resrv/option/value', { data: { id: deleteId.value } })
        .then(() => {
            toast.success('Option deleted');
            deleteId.value = null;
            emit('saved');
        })
        .catch(() => {
            toast.error('Cannot delete option');
        });
}

function order(event) {
    if (!event.moved) {
        return;
    }
    const item = event.moved.element;
    const newOrder = event.moved.newIndex + 1;
    axios.patch('/cp/resrv/option/value/order', { id: item.id, order: newOrder })
        .then(() => {
            toast.success('Options order changed');
            emit('saved');
        })
        .catch(() => {
            toast.error('Options ordering failed');
        });
}
</script>
