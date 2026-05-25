<template>
    <div>
        <draggable
            v-model="localExtras"
            item-key="id"
            group="extras"
            @change="handleChange"
            class="space-y-2"
            :ghost-class="'ghost'"
            filter=".ignore-element"
            :animation="200"
            :disabled="disableDrag"
        >
            <template #header>
                <div
                    v-if="localExtras.length === 0"
                    v-text="__('Add or drag extras here')"
                    class="ignore-element text-xs text-gray-500 dark:text-gray-400 text-center border border-dashed border-gray-300 dark:border-gray-700 rounded-md mb-2 p-3"
                ></div>
            </template>
            <template #item="{ element: extra }">
                <div class="w-full flex flex-wrap items-center justify-between p-3 rounded-lg border bg-white shadow-ui-sm dark:bg-gray-850 dark:border-gray-700/80">
                    <div class="flex items-center gap-2">
                        <StatusIndicator :status="extraEnabled(extra) ? 'published' : 'draft'" />
                        <span class="font-medium text-gray-900 dark:text-gray-200" :class="{ 'cursor-pointer hover:underline': !insideEntry }" v-html="extra.name" @click="editExtra(extra)"></span>
                        <span class="text-sm text-gray-700 dark:text-gray-400">{{ extra.price }} <span class="text-xs text-gray-500" v-html="priceLabel(extra.price_type)"></span></span>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="flex items-center text-gray-500 dark:text-gray-400" v-if="extraHasConditions(extra)" v-tooltip="__('This extra has conditions.')">
                            <svg xmlns="http://www.w3.org/2000/svg" class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5">
                                <path d="M7 18m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0"></path>
                                <path d="M7 6m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0"></path>
                                <path d="M17 6m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0"></path>
                                <path d="M7 8l0 8"></path>
                                <path d="M9 18h6a2 2 0 0 0 2 -2v-5"></path>
                                <path d="M14 14l3 -3l3 3"></path>
                            </svg>
                        </div>
                        <Badge
                            v-if="insideEntry"
                            :text="extraEnabled(extra) ? __('Enabled') : __('Disabled')"
                            :variant="extraEnabled(extra) ? 'success' : 'default'"
                            size="sm"
                            class="cursor-pointer"
                            @click="associateEntryExtra(extra)"
                        />
                        <Dropdown v-if="!insideEntry">
                            <DropdownMenu>
                                <DropdownItem :text="__('Edit')" icon="pencil" @click="editExtra(extra)" />
                                <DropdownItem :text="__('Mass assign')" icon="duplicate" @click="massAssign(extra)" />
                                <DropdownItem :text="__('Conditions')" icon="workflow" @click="editConditions(extra)" />
                                <DropdownSeparator />
                                <DropdownItem :text="__('Delete')" icon="trash" variant="destructive" @click="confirmDelete(extra)" />
                            </DropdownMenu>
                        </Dropdown>
                    </div>
                </div>
            </template>
        </draggable>
        <div class="mt-3" v-if="!insideEntry">
            <Button variant="default" :text="__('Add extra')" icon="plus" @click="addExtra" />
        </div>
        <ExtrasPanel
            v-if="showPanel"
            :data="extra"
            @closed="togglePanel"
            @saved="extraSaved"
        />
        <ExtraConditionsPanel
            v-if="showConditionsPanel"
            :data="extra"
            @closed="toggleConditionsPanel"
            @saved="extraConditionsSaved"
        />
        <ExtraMassAssignPanel
            v-if="showMassAssignPanel"
            :data="extra"
            @closed="toggleMassAssignPanel"
            @saved="toggleMassAssignPanel"
        />
        <confirmation-modal
            :open="deleteId !== null"
            :title="__('Delete extra')"
            :danger="true"
            @confirm="deleteExtra"
            @cancel="deleteId = null"
        >
            {{ __('Are you sure you want to delete this extra?') }} <strong>{{ __('This cannot be undone.') }}</strong>
        </confirmation-modal>
    </div>
</template>

<script setup>
import { Badge, Button, Dropdown, DropdownItem, DropdownMenu, DropdownSeparator, StatusIndicator } from '@statamic/cms/ui';
import draggable from 'vuedraggable';
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import ExtrasPanel from './ExtrasPanel.vue';
import ExtraConditionsPanel from './ExtraConditionsPanel.vue';
import ExtraMassAssignPanel from './ExtraMassAssignPanel.vue';
import { useToast } from '../composables/useToast.js';

const props = defineProps({
    extras: { type: Array, required: true },
    insideEntry: { type: Boolean, default: false },
    parent: { type: String, required: true },
    categoryId: { type: Number, required: false, default: null },
});

const emit = defineEmits(['reload-categories']);
const toast = useToast();

const localExtras = ref([...props.extras]);
const showPanel = ref(false);
const showConditionsPanel = ref(false);
const showMassAssignPanel = ref(false);
const allowEntryExtraEdit = ref(true);
const deleteId = ref(null);
const disableDrag = ref(props.insideEntry);
const extra = ref({});

const emptyExtra = {
    name: '',
    slug: '',
    category_id: null,
    price: '',
    price_type: '',
    allow_multiple: false,
    custom: '',
    override_label: '',
    maximum: 0,
    published: true,
};

const newItem = computed(() => props.parent === 'Collection');

watch(() => props.extras, (value) => {
    localExtras.value = [...value];
}, { deep: true });

function togglePanel() {
    showPanel.value = !showPanel.value;
}

function toggleConditionsPanel() {
    showConditionsPanel.value = !showConditionsPanel.value;
}

function toggleMassAssignPanel() {
    showMassAssignPanel.value = !showMassAssignPanel.value;
}

function associateEntryExtra(item) {
    toggleEntryExtraEditing();
    if (extraEnabled(item)) {
        disableExtra(item.id);
    } else {
        enableExtra(item.id);
    }
}

function toggleEntryExtraEditing() {
    allowEntryExtraEdit.value = !allowEntryExtraEdit.value;
}

function addExtra() {
    if (props.categoryId) {
        emptyExtra.category_id = props.categoryId;
    }
    extra.value = { ...emptyExtra };
    togglePanel();
}

function editExtra(item) {
    if (props.insideEntry) {
        return;
    }
    extra.value = item;
    togglePanel();
}

function editConditions(item) {
    extra.value = item;
    toggleConditionsPanel();
}

function massAssign(item) {
    extra.value = item;
    toggleMassAssignPanel();
}

function extraSaved() {
    togglePanel();
    getAllExtras();
}

function extraConditionsSaved() {
    toggleConditionsPanel();
    getAllExtras();
}

function extraEnabled(item) {
    if (props.insideEntry) {
        return item.enabled;
    }
    return !!item.published;
}

function extraHasConditions(item) {
    return item.conditions?.conditions.length > 0;
}

function priceLabel(code) {
    if (code === 'perday') return '/ day';
    if (code === 'fixed') return '/ reservation';
    if (code === 'relative') return 'relative';
    if (code === 'custom') return 'custom';
    return '';
}

function getAllExtras() {
    emit('reload-categories');
}

function enableExtra(extraId) {
    axios.post('/cp/resrv/extra/add/' + props.parent, { id: extraId })
        .then(() => {
            toast.success('Extra added to this entry');
            toggleEntryExtraEditing();
            getAllExtras();
        })
        .catch(() => {
            toast.error('Cannot add extra to entry');
        });
}

function disableExtra(extraId) {
    axios.post('/cp/resrv/extra/remove/' + props.parent, { id: extraId })
        .then(() => {
            toast.success('Extra removed from this entry');
            toggleEntryExtraEditing();
            getAllExtras();
        })
        .catch(() => {
            toast.error('Cannot remove extra to entry');
        });
}

function confirmDelete(item) {
    deleteId.value = item.id;
}

function deleteExtra() {
    axios.delete('/cp/resrv/extra', { data: { id: deleteId.value } })
        .then(() => {
            toast.success('Extra deleted');
            deleteId.value = null;
            getAllExtras();
        })
        .catch(() => {
            toast.error('Cannot delete extra');
        });
}

function handleChange(event) {
    if (event.added) {
        disableDrag.value = true;
        const item = event.added.element;
        axios.patch(`/cp/resrv/extra/move/${item.id}`, {
            category_id: props.categoryId,
            order: event.added.newIndex + 1,
        })
            .then(() => {
                toast.success('Extra moved successfully');
            })
            .catch(() => {
                toast.error('Failed to move extra');
            })
            .finally(() => {
                getAllExtras();
                disableDrag.value = false;
            });
    } else if (event.moved) {
        disableDrag.value = true;
        const item = event.moved.element;
        const newOrder = event.moved.newIndex + 1;
        axios.patch(`/cp/resrv/extra/order/${item.id}`, { order: newOrder })
            .then(() => {
                toast.success('Extras order changed');
            })
            .catch(() => {
                toast.error('Extras ordering failed');
            })
            .finally(() => {
                getAllExtras();
                disableDrag.value = false;
            });
    }
}
</script>

<style>
.ghost {
    opacity: 0.5;
    background: #c8ebfb;
}
</style>
