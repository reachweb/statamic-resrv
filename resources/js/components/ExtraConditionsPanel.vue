<template>
    <stack name="statamic-resrv-extra-conditions" @closed="close">
        <div slot-scope="{ close }" class="h-full overflow-scroll overflow-x-auto bg-gray-100 dark:bg-dark-600">
            <header class="flex items-center sticky top-0 inset-x-0 bg-gray-300 dark:bg-dark-600 border-b dark:border-dark-900 shadow px-8 py-2 z-1 h-13">
                <div class="flex-1 flex items-center text-xl">{{ __('Extra conditions for:') }}  <span class="ml-2 font-bold">{{ data.name }}</span></div>                
                <button type="button" class="text-gray-700 hover:text-gray-800 dark:text-dark-100 dark:hover:text-dark-175 mr-6 text-sm" @click="close">Cancel</button>
                <button 
                    class="btn-primary" 
                    :disabled="disableSave"
                    @click="save"
                >
                    {{ __('Save') }}
                </button>
            </header>
            <section class="py-4 px-3 md:px-8">
                <div class="publish-sections">
                    <div class="publish-sections-section">
                        <div class="card">
                            <extra-conditions-form 
                            :data="conditionsSafe()"
                            :extras="extras"
                            :errors="errors"
                            @updated="createSubmit"                  
                        />
                        </div>
                    </div>
                </div>
            </section>        
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
        extras: {
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
