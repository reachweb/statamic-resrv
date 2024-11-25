<template>
<div v-if="categoriesLoaded" ref="sections">
    <vue-draggable 
        v-model="categories"
        @change="orderCategories"
        handle=".category-drag-handle"
        filter=".ignore-element"
        :disabled="disableDrag"
        :animation="200"
    >
        <div
            class="extra-category-sections flex flex-wrap -mx-2 outline-none"
            :class="{ 'ignore-element': category.id === null }"
            v-for="category in categories"
            :key="category.id"
        >
            <div class="category-section w-full" v-if="!( insideEntry && category.extras.length === 0)">
                <div class="category-section-card card dark:bg-dark-800 p-0 h-full flex rounded-t flex-col">
                    <div class="bg-gray-200 dark:bg-dark-600 border-b dark:border-none text-sm flex rounded-t">
                        <div class="category-drag-handle w-4 border-r dark:border-dark-900" v-if="category.id !== null && ! insideEntry">
                            <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 7 17"><g fill="#B6B6B6" fill-rule="evenodd"><rect width="2" height="2" rx="1"/><rect width="2" height="2" y="5" rx="1"/><rect width="2" height="2" y="10" rx="1"/><rect width="2" height="2" y="15" rx="1"/><rect width="2" height="2" x="5" rx="1"/><rect width="2" height="2" x="5" y="5" rx="1"/><rect width="2" height="2" x="5" y="10" rx="1"/><rect width="2" height="2" x="5" y="15" rx="1"/></g></svg>
                        </div>
                        <div class="p-2 flex-1 flex items-center" v-if="category.id !== null && ! insideEntry">
                            <div class="little-dot" :class="categoryEnabled(category) ? 'bg-green-600' : 'bg-gray-400'"></div>
                            <a class="flex items-center flex-1 group ml-2" @click.prevent="editCategory(category)">
                                <div v-text="__(category.name)" />
                            </a>
                            <button @click.prevent="editCategory(category)" class="flex items-center text-gray-700 dark:text-dark-175 hover:text-gray-950 dark:hover:text-dark-100 mr-3">
                                <svg-icon class="h-4 w-4" name="pencil" />
                            </button>
                            <button @click.prevent="confirmDelete(category)" class="flex items-center text-gray-700 dark:text-dark-175 hover:text-gray-950 dark:hover:text-dark-100">
                                <svg-icon class="h-4 w-4" name="micro/trash" />
                            </button>
                        </div>
                         <div class="p-2 flex-1 flex items-center" v-else>
                            <span class="flex items-center flex-1 group">
                                <div class="ml-3 text-gray-800 dark:text-dark-150" v-text="__(category.name)" />
                            </span>
                        </div>
                    </div>
                    <div class="p-3 flex-grow">
                        <extras
                            :key="category.id"
                            :extras="category.extras"
                            :inside-entry="insideEntry"
                            :category-id="category.id"
                            :parent="parent"
                            @reload-categories="getAllCategories"
                        />            
                    </div>
                </div>
            </div>
        </div>
    </vue-draggable>
    <div class="category-add-section-container w-full mx-0 mt-4 min-h-24" v-if="! insideEntry">
        <button @click.prevent="addCategory" class="category-add-section-button dark:border-dark-200 dark:text-dark-150 dark:hover:border-dark-175 dark:hover:text-dark-100 outline-none">
            <div class="text-center flex items-center leading-none">
                <svg-icon name="micro/plus" class="h-3 w-3 mr-2" />
                <div v-text="__('Add a new category')" />
            </div>
        </button>
    </div>
    <extras-category-panel            
        v-if="showPanel"
        :data="category"
        @closed="togglePanel"
        @saved="categorySaved"
    >
    </extras-category-panel>
    <confirmation-modal
        v-if="deleteId"
        title="Delete category"
        :danger="true"
        @confirm="deleteCategory"
        @cancel="deleteId = false"
    >
        {{ __('Are you sure you want to delete this category?') }} <strong>{{ __('This cannot be undone.') }}</strong><br />
        {{  __('Any extras in this category will be moved to the uncategorized section.') }}
    </confirmation-modal>
</div>
</template>
<script>
import axios from 'axios'
import Extras from './Extras.vue'
import ExtrasCategoryPanel from './ExtrasCategoryPanel.vue'
import VueDraggable from 'vuedraggable'

export default {
    props: {
        insideEntry: {
            type: Boolean,
            default: false
        },
        parent: {
            type: String,
            required: false,
            default: null
        }
    },

    data() {
        return {
            showPanel: false,
            categories: '',
            categoryToAdd: null,
            categoriesLoaded: false,
            deleteId: false,
            disableDrag: this.insideEntry,
            category: '',
            emptyCategory: {
                name: '',
                description: '',
                slug: '',
                published: 1
            }
        }
    },

    components: {
        Extras,
        ExtrasCategoryPanel,
        VueDraggable
    },

    computed: {
        newItem() {
            if (this.parent == 'Collection') {
                return true
            }
            return false
        }
    },

    mounted() {
        this.getAllCategories()
    },

    updated() {
        if (! this.newItem) {
            this.$emit('input', this.parent)
        }
    },

    methods: {
        togglePanel() {
            this.showPanel = !this.showPanel
        },
        addCategory() {
            this.category = this.emptyCategory
            this.togglePanel()
        },
        addExtra(categoryId) {
            this.categoryToAdd = categoryId
        },
        editCategory(category) {
            this.category = category
            this.togglePanel()
        },
        categorySaved() {
            this.togglePanel()
            this.getAllCategories()
        },
        categoryEnabled(category) {
            return category.published
        },
        getAllCategories() {
            let url = '/cp/resrv/extra-category'
            if (this.insideEntry) {
                url = `/cp/resrv/extra-category/${this.parent}`
            }
            axios.get(url)
                .then(response => {
                    this.categories = response.data              
                    this.categoriesLoaded = true                    
                })
                .catch(error => {
                    this.$toast.error('Cannot retrieve categories')
                })
        },
        confirmDelete(category) {
            this.deleteId = category.id
        },
        deleteCategory() {
            axios.delete(`/cp/resrv/extra-category/${this.deleteId}`)
                .then(response => {
                    this.$toast.success('Category deleted')
                    this.deleteId = false
                })
                .catch(error => {
                    this.$toast.error('Cannot delete category')
                })
                .finally(() => {
                    this.getAllCategories()
                })
        },
        orderCategories(event) {
            if (! event.moved) return;

            this.disableDrag = true

            let item = event.moved.element;
            let order = event.moved.newIndex + 1;
            
            axios.patch('/cp/resrv/extra-category/order', {
                id: item.id, 
                order: order
            })
            .then(() => {
                this.$toast.success('Categories order changed')
            })
            .catch(() => {
                this.$toast.error('Categories ordering failed')
            })
            .finally(() => {
                this.getAllCategories()
                this.disableDrag = false
            })
        }
    }
}
</script>