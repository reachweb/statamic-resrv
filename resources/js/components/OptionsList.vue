<template>
    <div>
        <div v-if="dataLoaded">
            <draggable
                class="space-y-2"
                v-model="options"
                item-key="id"
                @start="drag = true"
                @end="drag = false"
                @change="order"
            >
                <template #item="{ element: option }">
                    <div class="w-full flex flex-wrap items-center justify-between p-3 rounded-lg border bg-white shadow-ui-sm dark:bg-gray-850 dark:border-gray-700/80">
                        <div class="flex items-center gap-2">
                            <StatusIndicator :status="option.published ? 'published' : 'draft'" />
                            <span class="font-medium cursor-pointer text-gray-900 dark:text-gray-200 hover:underline" v-html="option.name" @click="edit(option)"></span>
                        </div>
                        <div class="flex items-center gap-2">
                            <Badge :text="option.required ? __('Required') : __('Optional')" size="sm" :variant="option.required ? 'warning' : 'default'" />
                            <Dropdown>
                                <DropdownMenu>
                                    <DropdownItem :text="__('Edit')" icon="pencil" @click="edit(option)" />
                                    <DropdownSeparator />
                                    <DropdownItem :text="__('Delete')" icon="trash" variant="destructive" @click="confirmDelete(option)" />
                                </DropdownMenu>
                            </Dropdown>
                        </div>
                        <div class="w-full mt-3">
                            <OptionValuesList :values="option.values" :parent="option.id" @saved="valueSaved" />
                        </div>
                    </div>
                </template>
            </draggable>
        </div>
        <div class="mt-3">
            <Button :text="__('Add option')" variant="primary" icon="plus" @click="add" />
        </div>
        <OptionsPanel v-if="showPanel" :data="option" @closed="togglePanel" @saved="dataSaved" />
        <confirmation-modal
            v-if="deleteId"
            :title="__('Delete option')"
            :danger="true"
            @confirm="deleteOption"
            @cancel="deleteId = null"
        >
            {{ __('Are you sure you want to delete this option?') }} <strong>{{ __('This cannot be undone.') }}</strong>
        </confirmation-modal>
    </div>
</template>

<script setup>
import { Badge, Button, Dropdown, DropdownItem, DropdownMenu, DropdownSeparator, StatusIndicator } from '@statamic/cms/ui';
import draggable from 'vuedraggable';
import { computed, onMounted, onUpdated, ref } from 'vue';
import axios from 'axios';
import OptionsPanel from './OptionsPanel.vue';
import OptionValuesList from './OptionValuesList.vue';
import { useToast } from '../composables/useToast.js';

const props = defineProps({
    parent: { type: String, required: false },
});

const emit = defineEmits(['input']);
const toast = useToast();

const showPanel = ref(false);
const options = ref([]);
const dataLoaded = ref(false);
const deleteId = ref(null);
const drag = ref(false);
const option = ref({});

const emptyOption = {
    name: '',
    slug: '',
    item_id: props.parent,
    description: '',
    required: false,
    published: true,
};

const newItem = computed(() => props.parent === 'Collection');

onMounted(() => getOptions());

onUpdated(() => {
    if (!newItem.value) {
        emit('input', props.parent);
    }
});

function togglePanel() {
    showPanel.value = !showPanel.value;
}

function add() {
    option.value = { ...emptyOption };
    togglePanel();
}

function edit(item) {
    option.value = item;
    togglePanel();
}

function dataSaved() {
    togglePanel();
    getOptions();
}

function valueSaved() {
    getOptions();
}

function getOptions() {
    axios.get('/cp/resrv/option/' + props.parent)
        .then((response) => {
            options.value = response.data;
            dataLoaded.value = true;
        })
        .catch(() => {
            toast.error('Cannot retrieve options');
        });
}

function confirmDelete(item) {
    deleteId.value = item.id;
}

function deleteOption() {
    axios.delete('/cp/resrv/option', { data: { id: deleteId.value } })
        .then(() => {
            toast.success('Option deleted');
            deleteId.value = null;
            getOptions();
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
    axios.patch('/cp/resrv/option/order', { id: item.id, order: newOrder })
        .then(() => {
            toast.success('Options order changed');
            getOptions();
        })
        .catch(() => {
            toast.error('Options ordering failed');
        });
}
</script>
