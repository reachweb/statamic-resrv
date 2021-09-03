<template>
    <div>
    <div class="w-full h-full" v-if="dataLoaded">
        <vue-draggable class="mt-4 space-y-1" v-model="options" @start="drag=true" @end="drag=false" @change="order">
            <div
                v-for="option in options"
                :key="option.id"
                class="w-full flex flex-wrap items-center justify-between px-3 py-2 shadow rounded-md transition-colors bg-white"
            >
                <div class="flex items-center space-x-2">
                    <div class="little-dot" :class="option.published == true ? 'bg-green-600' : 'bg-gray-400'"></div>
                    <span class="font-medium cursor-pointer" v-html="option.name" @click="edit(option)"></span>
                </div>
                <div class="flex space-x-2">
                    <span 
                        class="text-gray-500 text-sm uppercase" 
                        v-html="option.required ? 'Required' : 'Optional'"
                    ></span>
                    <dropdown-list>
                        <dropdown-item :text="__('Edit')" @click="edit(option)" />
                        <dropdown-item :text="__('Delete')" @click="confirmDelete(option)" />         
                    </dropdown-list>
                </div>
                <div class="w-full">
                    <option-values-list
                        :values="option.values"
                        :parent="option.id"
                        @saved="valueSaved"                    
                    >
                    </option-values-list>
                </div>
            </div>
        </vue-draggable>
    </div>
    <div class="w-full mt-4">
        <button class="btn-primary" @click="add" v-html="__('Add option')"></button>
    </div>
    <options-panel            
        v-if="showPanel"
        :data="option"
        @closed="togglePanel"
        @saved="dataSaved"
    >
    </options-panel>
    <confirmation-modal
        v-if="deleteId"
        title="Delete option"
        :danger="true"
        @confirm="deleteOption"
        @cancel="deleteId = false"
    >
        Are you sure you want to delete this option? <strong>This cannot be undone.</strong>
    </confirmation-modal>
    </div>
</template>
<script>
import axios from 'axios'
import OptionsPanel from './OptionsPanel.vue'
import OptionValuesList from './OptionValuesList.vue'
import VueDraggable from 'vuedraggable'

export default {
    props: {
        parent: {
            type: String,
            required: false
        }
    },

    data() {
        return {
            containerWidth: null,
            showPanel: false,
            options: '',
            dataLoaded: false,
            deleteId: false,
            drag: false,
            option: '',
            emptyOption: {
                name: '',
                slug: '',
                item_id: this.parent,
                description: '',
                required : 0,
                published : 1
            }
        }
    },

    components: {
        OptionsPanel,
        OptionValuesList,
        VueDraggable
    },

    computed: {
        newItem() {
            if (this.parent == 'Collection') {
                return true
            }
            return false
        }
    },

    mounted() {
        this.getOptions()        
    },

    updated() {
        if (! this.newItem) {
            this.$emit('input', this.parent)
        }
    },

    methods: {
        togglePanel() {
            this.showPanel = !this.showPanel
        },
        add() {
            this.option = this.emptyOption
            this.togglePanel()
        },
        edit(option) {
            this.option = option
            this.togglePanel()
        },
        dataSaved() {
            this.togglePanel()
            this.getOptions()
        },
        valueSaved() {
            this.getOptions()
        },
        getOptions() {
            axios.get('/cp/resrv/option/'+this.parent)
            .then(response => {
                this.options = response.data
                this.dataLoaded = true
            })
            .catch(error => {
                this.$toast.error('Cannot retrieve options')
            })
        },
        confirmDelete(extra) {
            this.deleteId = extra.id
        },
        deleteOption() {
            axios.delete('/cp/resrv/option', {data: {'id': this.deleteId}})
                .then(response => {
                    this.$toast.success('Option deleted')
                    this.deleteId = false
                    this.getOptions()
                })
                .catch(error => {
                    this.$toast.error('Cannot delete option')
                })
        },
        order(event){
            let item = event.moved.element
            let order = event.moved.newIndex + 1
            axios.patch('/cp/resrv/option/order', {id: item.id, order: order})
                .then(() => {
                    this.$toast.success('Options order changed')
                    this.getOptions()
                })
                .catch(() => {this.$toast.error('Options ordering failed')})
        }        
    }
}
</script>