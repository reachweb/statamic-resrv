<template>
<div v-if="categoriesLoaded" ref="sections">
    <div
        class="extra-category-sections flex flex-wrap -mx-2 outline-none"
        v-for="category in categories"
    >
        <div class="category-section" >
            <div class="category-section-card card dark:bg-dark-800 p-0 h-full flex rounded-t flex-col">
                <div class="bg-gray-200 dark:bg-dark-600 border-b dark:border-none text-sm flex rounded-t">
                    <div class="category-drag-handle category-section-drag-handle w-4 border-r dark:border-dark-900">
                        <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 7 17"><g fill="#B6B6B6" fill-rule="evenodd"><rect width="2" height="2" rx="1"/><rect width="2" height="2" y="5" rx="1"/><rect width="2" height="2" y="10" rx="1"/><rect width="2" height="2" y="15" rx="1"/><rect width="2" height="2" x="5" rx="1"/><rect width="2" height="2" x="5" y="5" rx="1"/><rect width="2" height="2" x="5" y="10" rx="1"/><rect width="2" height="2" x="5" y="15" rx="1"/></g></svg>
                    </div>
                    <div class="p-2 flex-1 flex items-center" v-if="category.id !== 0">
                        <a class="flex items-center flex-1 group" @click="editCategory(category)">
                            <div v-text="__(category.name)" />
                        </a>
                        <button class="flex items-center text-gray-700 dark:text-dark-175 hover:text-gray-950 dark:hover:text-dark-100 mr-3" @click="editCategory(category)">
                            <svg-icon class="h-4 w-4" name="pencil" />
                        </button>
                        <button @click.prevent="$emit('deleted')" class="flex items-center text-gray-700 dark:text-dark-175 hover:text-gray-950 dark:hover:text-dark-100">
                            <svg-icon class="h-4 w-4" name="micro/trash" />
                        </button>
                    </div>
                     <div class="p-2 flex-1 flex items-center" v-else>
                        <span class="flex items-center flex-1 group">
                            <div class="ml-3 text-gray-800 dark:text-dark-150" v-text="__(category.name)" />
                        </span>
                    </div>
                </div>
                <div class="p-2">
                    <extras
                        :key="category.id"
                        :extras="category.extras"
                        :inside-entry="insideEntry"
                    />
                </div>
            </div>
        </div>
    </div>
    <div class="category-add-section-container w-full mx-0 mt-4">
        <button class="category-add-section-button dark:border-dark-200 dark:text-dark-150 dark:hover:border-dark-175 dark:hover:text-dark-100 outline-none" @click="addCategory">
            <div class="text-center flex items-center leading-none">
                <svg-icon name="micro/plus" class="h-3 w-3 mr-2" />
                <div v-text="__('Add a new category')" />
            </div>

            <div class="category-section-draggable-zone outline-none" />
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
        {{ __('Are you sure you want to delete this category?') }} <strong>{{ __('This cannot be undone.') }}'</strong>
    </confirmation-modal>
</div>
</template>
<script>
import axios from 'axios'
import Extras from './Extras.vue'
import ExtrasCategoryPanel from './ExtrasCategoryPanel.vue'

export default {
    props: {
        insideEntry: {
            type: Boolean,
            default: false
        },
        parent: {
            type: String,
            required: false
        }
    },

    data() {
        return {
            containerWidth: null,
            showPanel: false,
            categories: '',
            categoriesLoaded: false,
            deleteId: false,
            sortableCategories: null,
            currentCategory: '',
            lastInteractedCategory: null,
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

    watch: {

    },

    methods: {
        togglePanel() {
            this.showPanel = !this.showPanel
        },
        addCategory() {
            this.category = this.emptyCategory
            this.togglePanel()
        },
        editCategory(category) {
            this.category = category
            this.togglePanel()
        },
        categorySaved() {
            this.togglePanel()
            this.getAllCategories()
        },
        getAllCategories() {
            let url = '/cp/resrv/extra-category'
            axios.get(url)
                .then(response => {
                    this.categories = response.data              
                    this.categoriesLoaded = true                    
                })
                .catch(error => {
                    this.$toast.error('Cannot retrieve categories')
                })
                .finally(() => {
                    
                })
        },
      
    }
}
</script>