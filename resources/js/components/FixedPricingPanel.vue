<template>
    <stack narrow name="statamic-resrv-fixed-pricing" @closed="close">
        <div slot-scope="{ close }" class="bg-gray-100 h-full overflow-scroll overflow-x-auto flex flex-col">
            <header class="bg-white pl-6 pr-3 py-2 mb-4 border-b shadow-md text-lg font-medium flex items-center justify-between">
                {{ __('Fixed pricing') }}
                <button type="button" class="btn-close" @click="close">×</button>
            </header>            
            <div class="flex-1 overflow-auto px-1">
                <div class="px-2">
                    <div class="publish-sections">
                        <div class="publish-sections-section">
                            <div class="card">
                                <div class="pb-3">
                                    <div class="mb-1 text-sm">
                                        <label class="font-semibold" for="name">Days</label>
                                    </div>
                                    <div class="w-full">
                                        <input class="input-text" name="name" type="text" v-model="submit.days">
                                    </div>
                                    <div v-if="errors.days" class="w-full mt-2 text-sm text-red-400">
                                        {{ errors.name[0] }}
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

export default {

    props: {
        data: {
            type: Object,
            required: true
        },
        openPanel: {
            type: Boolean,
            default: false
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
            successMessage: 'Fixed pricing successfully saved',
            postUrl: '/cp/resrv/fixedpricing',
            method: 'post'           
        }
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
    }
}
</script>
