<template>
    <div>
    <div class="w-full flex justify-end mb-4">
        <button class="btn-primary" @click="addExtra">
            Add extra
        </button>
    </div>
    <div class="w-full h-full" v-if="extrasLoaded">
        <vue-draggable class="mt-4 space-y-2" v-model="extras" @start="drag=true" @end="drag=false" @change="order">
            <div
                v-for="extra in extras"
                :key="extra.id"
                class="w-full flex items-center justify-between p-3 shadow rounded-md transition-colors bg-gray-100"
            >
                <div class="flex items-center space-x-2">
                    <div class="little-dot" :class="greenDot(extra) ? 'bg-green-600' : 'bg-gray-400'"></div>
                    <span class="font-medium cursor-pointer" v-html="extra.name" @click="editExtra(extra)"></span>
                    <span>{{ extra.price }} <span class="text-xs text-gray-700" v-html="priceLabel(extra.price_type)"></span></span>
                </div>
                <div class="flex space-x-2">
                    <span 
                        class="text-gray-700 text-sm uppercase cursor-pointer" 
                        v-html="extraEnabled(extra.id) ? 'Enabled' : 'Disabled'"
                        @click="associateEntryExtra(extra.id)"
                        v-if="insideEntry"
                    ></span>
                    <dropdown-list>
                        <dropdown-item :text="__('Edit')" @click="editExtra(extra)" />
                        <dropdown-item :text="__('Mass assign')" @click="massAssign(extra)" />
                        <dropdown-item :text="__('Conditions')" @click="editConditions(extra)" />
                        <dropdown-item :text="__('Delete')" @click="confirmDelete(extra)" />         
                    </dropdown-list>
                </div>
            </div>
        </vue-draggable>
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
        :extras="extras"
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
        insideEntry: {
            type: Boolean,
            default: false
        },
        parent: {
            type: String,
            required: false
        }
    },

    data() {
        return {
            containerWidth: null,
            showPanel: false,
            showConditionsPanel: false,
            showMassAssignPanel: false,
            extras: '',
            entryExtras: '',
            activeExtras: [],
            extrasLoaded: false,
            allowEntryExtraEdit: true,
            deleteId: false,
            drag: false,
            extra: '',
            emptyExtra: {
                name: '',
                slug: '',
                price: '',
                price_type: '',
                allow_multiple : 0,
                maximum: 0,
                published : 1
            }
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

    mounted() {
        this.getAllExtras()        
    },

    updated() {
        if (! this.newItem) {
            this.$emit('input', this.parent)
        }
    },

    watch: {
        extrasLoaded() {
            if (this.insideEntry) {
                this.createEnabledExtrasArray()
            }            
        },
        entryExtras() {
            this.createEnabledExtrasArray()
        }
    },

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
        associateEntryExtra(extraId) {
            this.toggleEntryExtraEditing()
            if (this.extraEnabled(extraId)) {
                this.disableExtra(extraId)
            } else {
                this.enableExtra(extraId)
            }
        },
        toggleEntryExtraEditing() {
            this.allowEntryExtraEdit = !this.allowEntryExtraEdit
        },
        addExtra() {
            this.extra = this.emptyExtra
            this.togglePanel()
        },
        editExtra(extra) {
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
        createEnabledExtrasArray() {
            this.activeExtras = []
            this.entryExtras.forEach((item) => {
                this.activeExtras.push(parseInt(item.id))
            })
        },
        extraEnabled(extraId) {
            return this.activeExtras.includes(extraId)
        },
        greenDot(extra) {
            if (this.insideEntry) {
                if (this.extraEnabled(extra.id)) {
                    return true;
                }
            } else {
                if (extra.published == true) {
                    return true;
                }
            }
            return false
        },
        priceLabel(code) {
            if (code == 'perday') {
                return '/ day'
            } else if (code == 'fixed') {
                return '/ reservation'
            }
        },
        getAllExtras() {
            axios.get('/cp/resrv/extra')
            .then(response => {
                this.extras = response.data
                if (this.insideEntry) {
                    this.getEntryExtras()
                } else {
                    this.extrasLoaded = true
                }
                
            })
            .catch(error => {
                this.$toast.error('Cannot retrieve extras')
            })
        },
        getEntryExtras() {
            axios.get('/cp/resrv/extra/'+this.parent)
            .then(response => {
                this.entryExtras = response.data
                this.extrasLoaded = true
            })
            .catch(error => {
                this.$toast.error('Cannot retrieve entry extras')
            })
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
        order(event){
            let item = event.moved.element
            let order = event.moved.newIndex + 1
            axios.patch('/cp/resrv/extra/order', {id: item.id, order: order})
                .then(() => {
                    this.$toast.success('Extras order changed')
                    this.getAllExtras()
                })
                .catch(() => {this.$toast.error('Extras ordering failed')})
        }        
    }
}
</script>