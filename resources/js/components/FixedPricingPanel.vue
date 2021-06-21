<template>
    <stack narrow name="statamic-resrv-fixed-pricing" @closed="close">
        <div slot-scope="{ close }" class="bg-white h-full flex flex-col">
            <div class="bg-grey-20 px-3 py-1 border-b border-grey-30 text-lg font-medium flex items-center justify-between">
                {{ __('Fixed pricing') }}
                <button type="button" class="btn-close" @click="close">×</button>
            </div>            
            <div class="form-container">
                <div class="px-3 py-1" v-if="!(data.days === '0')">
                    <div class="font-bold mb-1 text-sm">
                        <label for="name">Days</label>
                    </div>
                    <div class="w-full">
                        <input class="w-full border border-gray-700 rounded p-1" name="name" type="text" v-model="submit.days">
                    </div>
                    <div v-if="errors.days" class="w-full mt-1 text-sm text-red-400">
                        {{ errors.name[0] }}
                    </div>  
                </div>                
                <div class="px-3 py-1">
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
