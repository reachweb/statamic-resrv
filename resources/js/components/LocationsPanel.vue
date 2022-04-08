<template>
    <stack narrow name="statamic-resrv-locations" @closed="close">
        <div slot-scope="{ close }" class="bg-white h-full flex flex-col">
            <div class="bg-grey-20 px-3 py-1 border-b border-grey-30 text-lg font-medium flex items-center justify-between">
                Add a location
                <button type="button" class="btn-close" @click="close">×</button>
            </div>            
            <div class="form-container">
                <div class="px-3 py-1">
                    <div class="font-bold mb-1 text-sm">
                        <label for="name">Name</label>
                    </div>
                    <div class="w-full">
                        <input class="w-full border border-gray-700 rounded p-1" name="name" type="text" v-model="submit.name" @input="slugify">
                    </div>
                    <div v-if="errors.name" class="w-full mt-1 text-sm text-red-400">
                        {{ errors.name[0] }}
                    </div>  
                </div>
                <div class="px-3 py-1">
                    <div class="font-bold mb-1 text-sm">
                        <label for="slug">Slug</label>
                    </div>
                    <div class="w-full">
                        <input class="w-full border border-gray-700 rounded p-1" name="slug" type="text" v-model="submit.slug">
                    </div>
                    <div v-if="errors.slug" class="w-full mt-1 text-sm text-red-400">
                        {{ errors.slug[0] }}
                    </div>  
                </div>
                <div class="px-3 py-1">
                    <div class="font-bold mb-1 text-sm">
                        <label for="price">Extra charge</label>
                    </div>
                    <div class="w-full">
                        <input class="w-full border border-gray-700 rounded p-1" name="price" type="text" v-model="submit.extra_charge">
                    </div>
                    <div v-if="errors.extra_charge" class="w-full mt-1 text-sm text-red-400">
                        {{ errors.extra_charge[0] }}
                    </div>  
                </div>                
                <div class="px-3 py-1 flex items-center">
                    <toggle-input v-model="submit.published"></toggle-input> 
                    <div class="text-sm ml-3">Published</div>
                </div>
                <div class="px-3 py-1">
                    <div class="w-full">
                        <button 
                            class="w-full px-2 py-1 bg-gray-600 hover:bg-gray-800 transition-colors text-white rounded cursor-pointer"
                            :disabled="disableSave"
                            @click="save"
                        >
                        Save
                        </button>
                    </div>
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
