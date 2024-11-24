
<template>
    <stack narrow name="statamic-resrv-category" @closed="close">
        <div slot-scope="{ close }" class="bg-gray-100 dark:bg-dark-700 h-full overflow-scroll overflow-x-auto flex flex-col">
            <header class="bg-white dark:bg-dark-550 pl-6 pr-3 py-2 mb-4 border-b dark:border-dark-950 shadow-md text-lg font-medium flex items-center justify-between">
                {{ isEditing ? 'Edit category' : 'Add category' }}
                <button type="button" class="btn-close" @click="close">×</button>
            </header>
            <div class="flex-1 overflow-auto px-1">
                <div class="px-2">
                    <div class="publish-sections">
                        <div class="publish-sections-section">
                            <div class="card">
                                <div class="pb-3">
                                    <div class="mb-1 text-sm">
                                        <label class="font-semibold" for="name">Name</label>
                                    </div>
                                    <div class="w-full">
                                        <input class="input-text" name="name" type="text" v-model="submit.name" @input="slugify">
                                    </div>
                                    <div v-if="errors.name" class="w-full mt-2 text-sm text-red-400">
                                        {{ errors.name[0] }}
                                    </div>  
                                </div>
                                <div class="pb-3">
                                    <div class="mb-1 text-sm">
                                        <label class="font-semibold" for="slug">Slug</label>
                                    </div>
                                    <div class="w-full">
                                        <input class="input-text" name="slug" type="text" v-model="submit.slug">
                                    </div>
                                    <div v-if="errors.slug" class="w-full mt-2 text-sm text-red-400">
                                        {{ errors.slug[0] }}
                                    </div>  
                                </div>
                                <div class="pb-3">
                                    <div class="mb-1 text-sm">
                                        <label class="font-semibold" for="description">Description</label>
                                    </div>
                                    <div class="w-full">
                                        <textarea class="input-text" name="description" v-model="submit.description"></textarea>
                                    </div>
                                    <div v-if="errors.description" class="w-full mt-2 text-sm text-red-400">
                                        {{ errors.description[0] }}
                                    </div>  
                                </div>
                                <div class="pb-3">
                                    <div class="flex items-center">
                                        <toggle-input v-model="submit.published"></toggle-input> 
                                        <div class="text-sm ml-3">Published</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="bg-gray-200 dark:bg-dark-500 p-4 border-t dark:border-dark-900 flex items-center justify-between">
                <div class="w-full">
                    <button 
                        class="btn-primary w-full"
                        :disabled="disableSave"
                        @click="save"
                    >
                    Save
                    </button>
                </div>
            </div>
        </div>
    </stack>
</template>

<script>
import FormHandler from '../mixins/FormHandler.vue'

export default {

    props: {
        data: {
            type: Object,
            required: true
        },
    },

    data() {
        return {
            submit: {},
            successMessage: 'Category successfully saved',
            postUrl: '/cp/resrv/extra-category',
        }
    },

    computed: {
        isEditing() {
            return _.has(this.data, 'id')
        },
        method() {
            return this.isEditing ? 'patch' : 'post'
        },
    },

    watch: {
        data() {
            this.createSubmit()
        }
    },

    mounted() {
        this.createSubmit()
    },

    mixins: [FormHandler],

    methods: {
        close() {
            this.submit = {}
            this.$emit('closed')
        },
        createSubmit() {
            this.submit = {}
            _.forEach(this.data, (value, name) => {
                this.$set(this.submit, name, value)
            })
        },
        slugify() {
            this.submit.slug = this.$slugify(this.submit.name)
        }
    }
}
</script>