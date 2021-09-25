<template>
    <stack narrow name="statamic-resrv-option-value" @closed="close">
        <div slot-scope="{ close }" class="bg-white h-full flex flex-col">
            <div class="bg-grey-20 px-3 py-1 border-b border-grey-30 text-lg font-medium flex items-center justify-between">
                Add an option value
                <button type="button" class="btn-close" @click="close">×</button>
            </div>            
            <div class="form-container">
                <div class="px-3 py-1">
                    <div class="font-bold mb-1 text-sm">
                        <label for="name">Name</label>
                    </div>
                    <div class="w-full">
                        <input class="w-full border border-gray-700 rounded p-1" name="name" type="text" v-model="submit.name">
                    </div>
                    <div v-if="errors.name" class="w-full mt-1 text-sm text-red-400">
                        {{ errors.name[0] }}
                    </div>  
                </div>
                <div class="px-3 py-1">
                    <div class="font-bold mb-1 text-sm">
                        <label for="price_type">Price type</label>
                    </div>
                    <div class="w-full">
                        <v-select v-model="submit.price_type" :options="priceType" :reduce="type => type.code" @input="togglePrice()" />
                    </div>
                    <div v-if="errors.price_type" class="w-full mt-1 text-sm text-red-400">
                        {{ errors.price_type[0] }}
                    </div>  
                </div>   
                <div class="px-3 py-1" v-if="showPrice">
                    <div class="font-bold mb-1 text-sm">
                        <label for="price">Price</label>
                    </div>
                    <div class="w-full">
                        <input class="w-full border border-gray-700 rounded p-1" name="price" type="text" v-model="submit.price">
                    </div>
                    <div v-if="errors.price" class="w-full mt-1 text-sm text-red-400">
                        {{ errors.price[0] }}
                    </div>  
                </div>  
                <div class="px-3 py-1">
                    <div class="font-bold mb-1 text-sm">
                        <label for="slug">Description (optional)</label>
                    </div>
                    <div class="w-full">
                        <textarea class="w-full border border-gray-700 rounded p-1" name="description" v-model="submit.description"></textarea>
                    </div>
                    <div v-if="errors.description" class="w-full mt-1 text-sm text-red-400">
                        {{ errors.description[0] }}
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
        parent: {
            type: String,
            required: true
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
        if (_.has(this.data, 'id')) {
            if (this.data.price_type != 'free') {
                this.showPrice = true
            }            
        }
    },

    data() {
        return {
            submit: {},
            successMessage: 'Option successfully saved',
            postUrl: '/cp/resrv/option/'+this.parent,
            showPrice: false,
            priceType: [
                {
                    code: "free",
                    label: "Free"
                },
                {
                    code: "perday",
                    label: "Per day"
                },
                {
                    code: "fixed",
                    label: "Fixed"
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
            this.submit.slug = _.kebabCase(this.submit.name)
        },
        togglePrice() {
            if (this.submit.price_type == 'free') {
                this.showPrice = false
                this.submit.price = '0'
            } else {
                this.showPrice = true
                this.submit.price = ''
            }            
        }
    }
}
</script>
