<template>
<div>
    <vue-draggable 
        v-model="localExtras"
        group="extras"
        @change="handleChange"
        class="space-y-2"
        :ghost-class="'ghost'"
        filter=".ignore-element"
        :animation="200"
        :disabled="disableDrag"
    >
        <div
            v-text="__('Add or drag extras here')"
            v-if="localExtras.length == 0"
            class="ignore-element text-2xs text-gray-600 dark:text-dark-150 text-center border dark:border-dark-200 border-dashed rounded mb-2 p-2"
        >
        </div>
        <template v-else class="w-full">
            <div 
                class="w-full flex flex-wrap items-center justify-between p-3 shadow-sm rounded-md border transition-colors 
                bg-gray-100 dark:border-dark-900 dark:bg-dark-550 dark:shadow-dark-sm" 
                v-for="extra in localExtras" 
                :key="extra.id"
            >
                <div class="flex items-center space-x-2">
                    <div class="little-dot" :class="extraEnabled(extra) ? 'bg-green-600' : 'bg-gray-400'"></div>
                    <span class="font-medium" :class="{'cursor-pointer' : ! insideEntry}" v-html="extra.name" @click="editExtra(extra)"></span>
                    <span>{{ extra.price }} <span class="text-xs text-gray-700 dark:text-dark-100" v-html="priceLabel(extra.price_type)"></span></span>
                </div>
                <div class="flex items-center space-x-2">
                    <div class="flex items-center" v-if="extraHasConditions(extra)" v-tooltip="__('This extra has conditions.')">
                        <svg xmlns="http://www.w3.org/2000/svg" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1"><path d="M7 18m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0"></path> <path d="M7 6m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0"></path> <path d="M17 6m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0"></path> <path d="M7 8l0 8"></path> <path d="M9 18h6a2 2 0 0 0 2 -2v-5"></path> <path d="M14 14l3 -3l3 3"></path></svg> 
                    </div>
                    <span 
                        class="rounded px-2 py-[4px] text-xs uppercase cursor-pointer"
                        :class="extraEnabled(extra) ? 'bg-green-600 text-white' : 'bg-gray-300 text-gray-800'" 
                        v-html="extraEnabled(extra) ? 'Enabled' : 'Disabled'"
                        @click="associateEntryExtra(extra)"
                        v-if="insideEntry"
                    ></span>
                    <dropdown-list v-if="! insideEntry">
                        <dropdown-item :text="__('Edit')" @click="editExtra(extra)" />
                        <dropdown-item :text="__('Mass assign')" @click="massAssign(extra)" />
                        <dropdown-item :text="__('Conditions')" @click="editConditions(extra)" />
                        <dropdown-item :text="__('Delete')" @click="confirmDelete(extra)" />         
                    </dropdown-list>
                </div>
            </div>
        </template>
    </vue-draggable>
    <div class="category-section-field-actions flex mt-2 -mx-1" v-if="! insideEntry">
        <div class="px-1">
            <button class="btn w-full flex justify-center items-center" @click="addExtra">
                <svg-icon name="light/toggle" class="mr-2 w-4 h-4" />
                {{ __('Add extra') }}
            </button>
        </div>
    </div>  
    <extras-panel            
        v-if="showPanel"
        :data="extra"
        @closed="togglePanel"
        @saved="extraSaved"
    >
    </extras-panel>
    <extra-conditions-panel            
        v-if="showConditionsPanel"
        :data="extra"
        @closed="toggleConditionsPanel"
        @saved="extraConditionsSaved"
    >
    </extra-conditions-panel>
    <extra-mass-assign-panel            
        v-if="showMassAssignPanel"
        :data="extra"
        @closed="toggleMassAssignPanel"
        @saved="toggleMassAssignPanel"
    >
    </extra-mass-assign-panel>
    <confirmation-modal
        v-if="deleteId"
        title="Delete extra"
        :danger="true"
        @confirm="deleteExtra"
        @cancel="deleteId = false"
    >
        Are you sure you want to delete this extra? <strong>This cannot be undone.</strong>
    </confirmation-modal>
</div>
</template>

<script>
import axios from 'axios'
import ExtrasPanel from './ExtrasPanel.vue'
import ExtraConditionsPanel from './ExtraConditionsPanel.vue'
import ExtraMassAssignPanel from './ExtraMassAssignPanel.vue'
import VueDraggable from 'vuedraggable'

export default {
    props: {
        extras: {
            type: Array,
            required: true
        },
        insideEntry: {
            type: Boolean,
            default: false
        },
        parent: {
            type: String,
            required: true
        },
        categoryId: {
            type: Number,
            required: false,
            default: null
        }
    },

    data() {
        return {
            localExtras: this.extras,
            containerWidth: null,
            showPanel: false,
            showConditionsPanel: false,
            showMassAssignPanel: false,
            extras: '',
            allowEntryExtraEdit: true,
            deleteId: false,
            disableDrag: this.insideEntry,
            extra: '',
            category: '',
            emptyExtra: {
                name: '',
                slug: '',
                category_id: null,
                price: '',
                price_type: '',
                allow_multiple : 0,                
                custom: '',
                override_label: '',
                maximum: 0,
                published : 1
            },
        }
    },

    components: {
        ExtrasPanel,
        ExtraConditionsPanel,
        ExtraMassAssignPanel,
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

    watch: {
        extras: {
            handler(value) {
                this.localExtras = value;
            },
            deep: true
        }
    },

    emits: ['reload-categories'],

    methods: {
        togglePanel() {
            this.showPanel = !this.showPanel
        },
        toggleConditionsPanel() {
            this.showConditionsPanel = !this.showConditionsPanel
        },
        toggleMassAssignPanel() {
            this.showMassAssignPanel = !this.showMassAssignPanel
        },
        associateEntryExtra(extra) {
            this.toggleEntryExtraEditing()
            if (this.extraEnabled(extra)) {
                this.disableExtra(extra.id)
            } else {
                this.enableExtra(extra.id)
            }
        },
        toggleEntryExtraEditing() {
            this.allowEntryExtraEdit = ! this.allowEntryExtraEdit
        },
        addExtra() {
            if (this.categoryId) {
                this.emptyExtra.category_id = this.categoryId
            }
            this.extra = this.emptyExtra
            this.togglePanel()
        },
        editExtra(extra) {
            if (this.insideEntry) {
                return
            }
            this.extra = extra
            this.togglePanel()
        },
        editConditions(extra) {
            this.extra = extra
            this.toggleConditionsPanel()
        },
        massAssign(extra) {
            this.extra = extra
            this.toggleMassAssignPanel()
        },
        extraSaved() {
            this.togglePanel()
            this.getAllExtras()
        },
        extraConditionsSaved() {
            this.toggleConditionsPanel()
            this.getAllExtras()
        },
        extraEnabled(extra) {
            if (this.insideEntry) {
                return extra.enabled
            } else {
                if (extra.published == true) {
                    return true;
                }
            }
            return false
        },
        extraHasConditions(extra) {
            return extra.conditions?.conditions.length > 0
        },
        priceLabel(code) {
            if (code == 'perday') {
                return '/ day'
            } else if (code == 'fixed') {
                return '/ reservation'
            } else if (code == 'relative') {
                return 'relative'
            } else if (code == 'custom') {
                return 'custom'
            }
        },
        getAllExtras() {
            this.$emit('reload-categories')
        },
        enableExtra(extraId) {
            axios.post('/cp/resrv/extra/add/'+this.parent, {'id': extraId})
            .then(response => {
                this.$toast.success('Extra added to this entry')
                this.toggleEntryExtraEditing()
                this.getAllExtras()
            })
            .catch(error => {
                this.$toast.error('Cannot add extra to entry')
            })
        },
        disableExtra(extraId) {
            axios.post('/cp/resrv/extra/remove/'+this.parent, {'id': extraId})
            .then(response => {
                this.$toast.success('Extra removed from this entry')
                this.toggleEntryExtraEditing()
                this.getAllExtras()
            })
            .catch(error => {
                this.$toast.error('Cannot remove extra to entry')
            })
        },
        confirmDelete(extra) {
            this.deleteId = extra.id
        },
        deleteExtra() {
            axios.delete('/cp/resrv/extra', {data: {'id': this.deleteId}})
                .then(response => {
                    this.$toast.success('Extra deleted')
                    this.deleteId = false
                    this.getAllExtras()
                })
                .catch(error => {
                    this.$toast.error('Cannot delete extra')
                })
        },
        handleChange(event) {
            if (event.added) {
                // Handle moving between categories
                this.disableDrag = true
                let item = event.added.element
                
                axios.patch(`/cp/resrv/extra/move/${item.id}`, {
                    category_id: this.categoryId,
                    order: event.added.newIndex + 1
                })
                .then(() => {
                    this.$toast.success('Extra moved successfully')
                })
                .catch(() => {
                    this.$toast.error('Failed to move extra')
                })
                .finally(() => {
                    this.getAllExtras()
                    this.disableDrag = false
                })
            } else if (event.moved) {
                // Handle reordering within same category
                this.disableDrag = true
                let item = event.moved.element;
                let order = event.moved.newIndex + 1;
                
                axios.patch(`/cp/resrv/extra/order/${item.id}`, {
                    order: order
                })
                .then(() => {
                    this.$toast.success('Extras order changed')
                })
                .catch(() => {
                    this.$toast.error('Extras ordering failed')
                })
                .finally(() => {
                    this.getAllExtras()
                    this.disableDrag = false
                })
            }
        }        
    }
}
</script>

<style>
.ghost {
    opacity: 0.5;
    background: #c8ebfb;
}
</style>