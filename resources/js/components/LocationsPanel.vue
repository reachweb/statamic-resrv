<template>
    <stack narrow name="statamic-resrv-locations" @closed="close">
        <div slot-scope="{ close }" class="bg-gray-100 dark:bg-dark-700 h-full overflow-scroll overflow-x-auto flex flex-col">
            <header class="bg-white dark:bg-dark-550 pl-6 pr-3 py-2 mb-4 border-b dark:border-dark-950 shadow-md text-lg font-medium flex items-center justify-between">
                Add a location
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
                                    <div v-if="errors.slug" class="w-full mt-2text-sm text-red-400">
                                        {{ errors.slug[0] }}
                                    </div>  
                                </div>
                                <div class="pb-3">
                                    <div class="mb-1 text-sm">
                                        <label class="font-semibold" for="price">Extra charge</label>
                                    </div>
                                    <div class="w-full">
                                        <input class="input-text" name="price" type="text" v-model="submit.extra_charge">
                                    </div>
                                    <div v-if="errors.extra_charge" class="w-full mt-1 text-sm text-red-400">
                                        {{ errors.extra_charge[0] }}
                                    </div>
                                </div>
                                <div class="pb-3 flex items-center">
                                    <toggle-input v-model="submit.published"></toggle-input> 
                                    <div class="text-sm ml-3">Published</div>
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

import vSelect from 'vue-select'

export default {

    props: {
        data: {
            type: Object,
            required: true
        },
        openPanel: {
            type: Boolean,
            default: false
        },
        url: {
            type: String,
            default: '/cp/resrv/location'
        }
    },

    computed: {
        method() {
            if (_.has(this.data, 'id')) {
                return 'patch'
            }
            return 'post'
        }
    },

    watch: {
        data() {
            this.createSubmit()
        }
    },

    mounted() {
        this.createSubmit()
    },

    data() {
        return {
            submit: {},
            successMessage: 'Location successfully saved',
            postUrl: '/cp/resrv/location',            
        }
    },

    mixins: [FormHandler],

    components: [vSelect],

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
