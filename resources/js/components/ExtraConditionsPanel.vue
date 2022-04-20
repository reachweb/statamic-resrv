<template>
    <stack name="statamic-resrv-extra-conditions" @closed="close">
        <div slot-scope="{ close }" class="bg-white h-full flex flex-col">
            <div class="bg-grey-20 px-3 py-1 border-b border-grey-30 text-lg font-medium flex items-center justify-between">
                <div>{{ __('Extra conditions for:') }}  <span class="font-bold">{{ data.name }}</span></div>                
                <button type="button" class="btn-close" @click="close">×</button>
            </div>
            <div class="p-4 bg-grey-20 h-full">
                <div class="card rounded-tl-none">
                    <extra-conditions-form 
                        :data="conditionsSafe()"
                        @updated="createSubmit"                  
                    />
                    <div class="w-full mt-4">
                        <button 
                            class="w-full px-2 py-1 bg-gray-600 hover:bg-gray-800 transition-colors text-white rounded cursor-pointe disabled:opacity-30" 
                            :disabled="disableSave"
                            @click="save"
                        >
                            {{ __('Save') }}
                        </button>
                    </div>           
                </div>
            </div>
        </div>
    </stack>
</template>

<script>
import FormHandler from '../mixins/FormHandler.vue'
import ExtraConditionsForm from './ExtraConditionsForm.vue'

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
    },

    mounted() {
        this.createSubmit([])
    },

    watch: {
        submit: {
            deep: true,
            handler(submit) {
                if (submit.conditions.length > 0) {
                    this.disableSave = false
                }
            }
        }
    },

    data() {
        return {
            submit: {},
            method: 'post',
            successMessage: 'Conditions successfully saved',
            postUrl: '/cp/resrv/extra/conditions/'+this.data.id,
            disableSave: true,
        }
    },

    mixins: [FormHandler],

    components: {
        ExtraConditionsForm
    },

    methods: {
        close() {
            this.submit = {}
            this.$emit('closed')
        },
        createSubmit(conditionsForm) {
            this.submit = {}
            this.submit.conditions = conditionsForm            
        },
        conditionsSafe() {
            if (this.data.conditions) {
                return this.data.conditions.conditions
            }
            return []
        }
    }
}
</script>
