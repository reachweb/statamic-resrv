<template>
    <div v-if="categoriesLoaded" ref="sections">
        <Header v-if="!insideEntry" :title="__('Extras')" icon="add-item">
            <Button :text="__('Add a new category')" variant="primary" icon="plus" @click.prevent="addCategory" />
        </Header>

        <draggable
            v-model="categories"
            item-key="id"
            @change="orderCategories"
            handle=".category-drag-handle"
            filter=".ignore-element"
            :disabled="disableDrag"
            :animation="200"
        >
            <template #item="{ element: category }">
                <div
                    class="mb-4 outline-none"
                    :class="{ 'ignore-element': category.id === null }"
                >
                    <div class="w-full" v-if="!(insideEntry && category.extras.length === 0)">
                        <Card inset>
                            <header class="flex items-center gap-2 border-b border-gray-200 dark:border-gray-700/80 rounded-t-xl bg-gray-100 dark:bg-gray-900/50 px-3 py-2">
                                <button
                                    type="button"
                                    class="category-drag-handle cursor-move text-gray-400 hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300"
                                    v-if="category.id !== null && !insideEntry"
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
                                <StatusIndicator v-if="category.id !== null" :status="categoryEnabled(category) ? 'published' : 'draft'" />
                                <a
                                    v-if="category.id !== null && !insideEntry"
                                    class="flex-1 font-medium text-gray-900 dark:text-gray-200 hover:underline cursor-pointer"
                                    @click.prevent="editCategory(category)"
                                    v-text="__(category.name)"
                                />
                                <span v-else class="flex-1 font-medium text-gray-800 dark:text-gray-300" v-text="__(category.name)" />
                                <template v-if="category.id !== null && !insideEntry">
                                    <Button icon="pencil" variant="ghost" size="sm" :aria-label="__('Edit')" @click.prevent="editCategory(category)" />
                                    <Button icon="trash" variant="ghost" size="sm" :aria-label="__('Delete')" @click.prevent="confirmDelete(category)" />
                                </template>
                            </header>
                            <div class="p-3">
                                <Extras
                                    :key="category.id"
                                    :extras="category.extras"
                                    :inside-entry="insideEntry"
                                    :category-id="category.id"
                                    :parent="parent"
                                    @reload-categories="getAllCategories"
                                />
                            </div>
                        </Card>
                    </div>
                </div>
            </template>
        </draggable>

        <ExtrasCategoryPanel
            v-if="showPanel"
            :data="category"
            @closed="togglePanel"
            @saved="categorySaved"
        />
        <confirmation-modal
            :open="deleteId !== null"
            :title="__('Delete category')"
            :danger="true"
            @confirm="deleteCategory"
            @cancel="deleteId = null"
        >
            {{ __('Are you sure you want to delete this category?') }} <strong>{{ __('This cannot be undone.') }}</strong><br />
            {{ __('Any extras in this category will be moved to the uncategorized section.') }}
        </confirmation-modal>
    </div>
</template>

<script setup>
import { Button, Card, Header, StatusIndicator } from '@statamic/cms/ui';
import draggable from 'vuedraggable';
import { onMounted, ref } from 'vue';
import axios from 'axios';
import Extras from './Extras.vue';
import ExtrasCategoryPanel from './ExtrasCategoryPanel.vue';
import { useToast } from '../composables/useToast.js';

const props = defineProps({
    insideEntry: { type: Boolean, default: false },
    parent: { type: String, required: false, default: null },
});

const toast = useToast();

const showPanel = ref(false);
const categories = ref([]);
const categoriesLoaded = ref(false);
const deleteId = ref(null);
const disableDrag = ref(props.insideEntry);
const category = ref({});

const emptyCategory = {
    name: '',
    description: '',
    slug: '',
    published: true,
};

onMounted(() => getAllCategories());

function togglePanel() {
    showPanel.value = !showPanel.value;
}

function addCategory() {
    category.value = { ...emptyCategory };
    togglePanel();
}

function editCategory(item) {
    category.value = item;
    togglePanel();
}

function categorySaved() {
    togglePanel();
    getAllCategories();
}

function categoryEnabled(item) {
    return item.published;
}

function getAllCategories() {
    let url = '/cp/resrv/extra-category';
    if (props.insideEntry) {
        url = `/cp/resrv/extra-category/${props.parent}`;
    }
    axios.get(url)
        .then((response) => {
            categories.value = response.data;
            categoriesLoaded.value = true;
        })
        .catch(() => {
            toast.error('Cannot retrieve categories');
        });
}

function confirmDelete(item) {
    deleteId.value = item.id;
}

function deleteCategory() {
    axios.delete(`/cp/resrv/extra-category/${deleteId.value}`)
        .then(() => {
            toast.success('Category deleted');
            deleteId.value = null;
        })
        .catch(() => {
            toast.error('Cannot delete category');
        })
        .finally(() => {
            getAllCategories();
        });
}

function orderCategories(event) {
    if (!event.moved) {
        return;
    }
    disableDrag.value = true;
    const item = event.moved.element;
    const newOrder = event.moved.newIndex + 1;
    axios.patch('/cp/resrv/extra-category/order', { id: item.id, order: newOrder })
        .then(() => {
            toast.success('Categories order changed');
        })
        .catch(() => {
            toast.error('Categories ordering failed');
        })
        .finally(() => {
            getAllCategories();
            disableDrag.value = false;
        });
}
</script>
