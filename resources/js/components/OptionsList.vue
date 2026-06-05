<template>
    <div>
        <div v-if="dataLoaded">
            <draggable
                class="space-y-2"
                v-model="options"
                item-key="id"
                :disabled="disableDrag"
                @start="drag = true"
                @end="drag = false"
                @change="order"
            >
                <template #item="{ element: option }">
                    <div class="w-full flex flex-wrap items-center justify-between p-3 rounded-lg border bg-white shadow-ui-sm dark:bg-gray-850 dark:border-gray-700/80">
                        <div class="flex items-center gap-2">
                            <StatusIndicator :status="option.published ? 'published' : 'draft'" />
                            <span class="font-medium cursor-pointer text-gray-900 dark:text-gray-200 hover:underline" v-text="option.name" @click="edit(option)"></span>
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
            :open="deleteId !== null"
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
import { onMounted, ref } from 'vue';
import axios from 'axios';
import OptionsPanel from './OptionsPanel.vue';
import OptionValuesList from './OptionValuesList.vue';
import { useToast } from '../composables/useToast.js';

const props = defineProps({
    parent: { type: String, required: false },
});

const toast = useToast();

const showPanel = ref(false);
const options = ref([]);
const dataLoaded = ref(false);
const deleteId = ref(null);
const drag = ref(false);
const disableDrag = ref(false);
const option = ref({});

const emptyOption = {
    name: '',
    slug: '',
    item_id: props.parent,
    description: '',
    required: false,
    published: true,
};

onMounted(() => getOptions());

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
            toast.error(__('Cannot retrieve options'));
        });
}

function confirmDelete(item) {
    deleteId.value = item.id;
}

function deleteOption() {
    axios.delete('/cp/resrv/option', { data: { id: deleteId.value } })
        .then(() => {
            toast.success(__('Option deleted'));
            deleteId.value = null;
            getOptions();
        })
        .catch(() => {
            toast.error(__('Cannot delete option'));
        });
}

function order(event) {
    if (!event.moved) {
        return;
    }
    disableDrag.value = true;
    const item = event.moved.element;
    const newOrder = event.moved.newIndex + 1;
    axios.patch('/cp/resrv/option/order', { id: item.id, order: newOrder })
        .then(() => {
            toast.success(__('Options order changed'));
        })
        .catch(() => {
            toast.error(__('Options ordering failed'));
        })
        .finally(() => {
            getOptions();
            disableDrag.value = false;
        });
}
</script>
