<template>
    <div>
    <div class="w-full h-full" v-if="values">
        <vue-draggable class="mt-2" v-model="values" @start="drag=true" @end="drag=false" @change="order">
            <div
                v-for="value in values"
                :key="value.id"
                class="w-full flex items-center text-sm justify-between px-3 py-2 border-t border-gray-200"
            >
                <div class="flex items-center space-x-2">
                    <div class="little-dot" :class="value.published == true ? 'bg-green-600' : 'bg-gray-400'"></div>
                    <span class="font-medium cursor-pointer" v-html="value.name" @click="edit(value)"></span>
                    <span v-if="value.price_type != 'free'">{{ value.price }} <span class="text-xs text-gray-700" v-html="priceLabel(value.price_type)"></span></span>
                    <span v-else class="text-xs text-gray-700" v-html="__('Free')"></span></span>
                </div>
                <div class="flex space-x-2">                    
                    <dropdown-list>
                        <dropdown-item :text="__('Edit')" @click="edit(value)" />
                        <dropdown-item :text="__('Delete')" @click="confirmDelete(value)" />         
                    </dropdown-list>
                </div>
            </div>
        </vue-draggable>
    </div>
    <div class="w-full mt-1">
        <button class="btn text-sm" @click="add" v-html="__('Add value')"></button>
    </div>
    <option-values-panel            
        v-if="showPanel"
        :data="value"
        :parent="parent"
        @closed="togglePanel"
        @saved="dataSaved"
    >
    </option-values-panel>
    <confirmation-modal
        v-if="deleteId"
        title="Delete value"
        :danger="true"
        @confirm="deleteValue"
        @cancel="deleteId = false"
    >
        Are you sure you want to delete this option? <strong>This cannot be undone.</strong>
    </confirmation-modal>
    </div>
</template>
<script>
import axios from 'axios'
import OptionValuesPanel from './OptionValuesPanel.vue'
import VueDraggable from 'vuedraggable'

export default {
    props: {
        values: {
            type: Object,
            required: true
        },
        parent: {
            type: String,
            required: true
        }
    },

    emits: ['saved'],

    data() {
        return {
            containerWidth: null,
            showPanel: false,            
            dataLoaded: false,
            deleteId: false,
            drag: false,
            value: '',
            emptyValue: {
                name: '',
                slug: '',
                price: '',
                price_type: '',
                option_id: this.parent,
                description: '',
                published : 1
            }
        }
    },

    components: {
        OptionValuesPanel,
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

    updated() {
        if (! this.newItem) {
            this.$emit('input', this.parent)
        }
    },

    methods: {
        togglePanel() {
            this.showPanel = !this.showPanel
        },
        priceLabel(code) {
            if (code == 'perday') {
                return '/ day'
            } else if (code == 'fixed') {
                return '/ reservation'
            }
        },
        add() {
            this.value = this.emptyValue
            this.togglePanel()
        },
        edit(value) {
            this.value = value
            this.togglePanel()
        },
        dataSaved() {
            this.togglePanel()
            this.$emit('saved')
        },
        confirmDelete(extra) {
            this.deleteId = extra.id
        },
        deleteValue() {
            axios.delete('/cp/resrv/option/value', {data: {'id': this.deleteId}})
                .then(response => {
                    this.$toast.success('Option deleted')
                    this.deleteId = false
                    this.$emit('saved')
                })
                .catch(error => {
                    this.$toast.error('Cannot delete option')
                })
        },
        order(event){
            let item = event.moved.element
            let order = event.moved.newIndex + 1
            axios.patch('/cp/resrv/option/value/order', {id: item.id, order: order})
                .then(() => {
                    this.$toast.success('Options order changed')
                    this.$emit('saved')
                })
                .catch(() => {this.$toast.error('Options ordering failed')})
        }        
    }
}
</script>