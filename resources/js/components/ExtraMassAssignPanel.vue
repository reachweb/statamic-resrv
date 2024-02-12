<template>
    <stack name="statamic-resrv-extra-mass-assign" @closed="close">
        <div slot-scope="{ close }" class="h-full overflow-scroll overflow-x-auto bg-gray-300">
            <header class="flex items-center sticky top-0 inset-x-0 bg-white shadow px-8 py-2 z-1 h-13">
                <div class="flex-1 flex items-center text-xl">{{ __('Mass assign') }}  <span class="font-bold ml-2">{{ data.name }}</span></div>                
                <button type="button" class="text-gray-700 hover:text-gray-800 mr-6 text-sm" @click="close">Cancel</button>
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
                            <div class="w-full">
                                <div class="mb-1 text-sm">
                                    <label class="font-semibold" for="name">Entries</label>
                                    <div class="text-sm font-light"><p>Select the entries that this extra should apply to.</p></div>
                                </div>
                                <div class="w-full" v-if="entriesLoaded && selectedEntriesLoaded">
                                    <v-select 
                                        v-model="submit.entries" 
                                        label="title"
                                        multiple="multiple"
                                        :close-on-select="false"
                                        :options="entries" 
                                        :searchable="true"
                                        :reduce="type => type.id" 
                                    >
                                        <template #selected-option-container><i class="hidden"></i></template>
                                        <template #footer="{ deselect }">                    
                                            <div class="vs__selected-options-outside flex flex-wrap">
                                                <span v-for="id in submit.entries" :key="id" class="vs__selected mt-1">
                                                    {{ getEntryTitle(id) }}
                                                    <button @click="deselect(id)" type="button" :aria-label="__('Deselect option')" class="vs__deselect">
                                                        <span>×</span>
                                                    </button>                 
                                                </span>
                                            </div>
                                        </template>
                                    </v-select>                            
                                </div>
                                <div v-if="errors.entries" class="w-full mt-1 text-sm text-red-400">
                                    {{ errors.entries[0] }}
                                </div>  
                            </div>
                            <div class="flex mt-4">
                                <button class="btn-flat text-sm" @click="selectAll">{{ __('Select all') }}</button>
                                <button class="btn-flat text-sm ml-2" @click="removeAll">{{ __('Remove all') }}</button>
                            </div>
                        </div>
                    </div>            
                </div>
            </section>
        </div>
    </stack>
</template>

<script>
import FormHandler from '../mixins/FormHandler.vue'
import axios from 'axios'

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

    created() {
        this.getSelectedEntries()
        this.getEntries()
    },

    watch: {
        selectedEntriesLoaded() {
            this.submit.entries = _.map(this.selectedEntries, (item) => item.id)
        },
        submit: {
            deep: true,
            handler(submit) {
                if (submit.entries.length > 0) {
                    this.disableSave = false
                }
            }
        }
    },

    data() {
        return {
            submit: {
                entries: []
            },
            entries: false,
            selectedEntries: false,
            entriesLoaded: false,
            selectedEntriesLoaded: false,
            method: 'post',
            successMessage: 'Extra successfully assigned',
            postUrl: '/cp/resrv/extra/massadd/'+this.data.id,
            disableSave: true,
        }
    },

    mixins: [FormHandler],

    methods: {
        close() {
            this.submit = {}
            this.$emit('closed')
        },
        selectAll() {
            this.submit.entries = _.map(this.entries, (item) => item.id)
        },
        removeAll() {
            this.submit.entries = []
        },
        entriesSafe() {
            if (this.selectedEntriesLoaded) {
                return this.selectedEntries
            }
            return []
        },
        getSelectedEntries() {
            axios.get('/cp/resrv/extra/entries/'+this.data.id)
                .then(response => {
                    this.selectedEntries = response.data
                    this.selectedEntriesLoaded = true         
                })
                .catch(error => {
                    this.$toast.error('Cannot retrieve entries for this extra')
                })            
        },
        getEntries() {
            axios.get('/cp/resrv/utility/entries')
            .then(response => {
                this.entries = response.data
                this.entriesLoaded = true
            })
            .catch(error => {
                this.$toast.error('Cannot retrieve the entries')
            })
        },
        getEntryTitle(id) {
            return this.entries.find(item => item.id == id).title
        },
    }
}
</script>
