<template>
    <stack narrow name="statamic-resrv-extras" @closed="close">
        <div slot-scope="{ close }" class="bg-gray-100 h-full flex flex-col">
            <header class="bg-white pl-6 pr-3 py-2 mb-4 border-b shadow-md text-lg font-medium flex items-center justify-between">
                Add an extra
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
                                        <label class="font-semibold" for="price">Price</label>
                                    </div>
                                    <div class="w-full">
                                        <input class="input-text" name="price" type="text" v-model="submit.price">
                                    </div>
                                    <div v-if="errors.price" class="w-full mt-1 text-sm text-red-400">
                                        {{ errors.price[0] }}
                                    </div>  
                                </div>
                                <div class="pb-3">
                                    <div class="mb-1 text-sm">
                                        <label class="font-semibold" for="price_type">Price type</label>
                                    </div>
                                    <div class="w-full">
                                        <v-select v-model="submit.price_type" :options="priceType" :reduce="type => type.code" />
                                    </div>
                                    <div v-if="errors.price_type" class="w-full mt-2 text-sm text-red-400">
                                        {{ errors.price_type[0] }}
                                    </div>  
                                </div>
                                <div class="pb-3 flex items-center">
                                    <toggle-input v-model="submit.allow_multiple"></toggle-input> 
                                    <div class="text-sm ml-3">Can add more than 1</div>
                                </div>
                                <div class="pb-3" v-if="submit.allow_multiple">
                                    <div class="mb-1 text-sm">
                                        <label class="font-semibold" for="name">Maximum number for 1 reservation</label>
                                    </div>
                                    <div class="w-full">
                                        <input class="input-text" name="name" type="text" v-model="submit.maximum">
                                    </div>
                                    <div v-if="errors.maximum" class="w-full mt-2 text-sm text-red-400">
                                        {{ errors.maximum[0] }}
                                    </div>  
                                </div>
                                <div class="pb-3">
                                    <div class="mb-1 text-sm">
                                        <label class="font-semibold" for="slug">Description</label>
                                    </div>
                                    <div class="w-full">
                                        <textarea class="input-text" name="description" type="text" v-model="submit.description"></textarea>
                                    </div>
                                    <div v-if="errors.description" class="w-full mt-1 text-sm text-red-400">
                                        {{ errors.description[0] }}
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
            <div class="bg-gray-200 p-4 border-t flex items-center justify-between">
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
            default: '/cp/resrv/extra'
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
            successMessage: 'Extra successfully saved',
            postUrl: '/cp/resrv/extra',
            priceType: [
                {
                    code: "perday",
                    label: "Per day"
                },
                {
                    code: "fixed",
                    label: "Fixed"
                },
                {
                    code: "relative",
                    label: "Relative to the reservation price"
                }
            ]
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
