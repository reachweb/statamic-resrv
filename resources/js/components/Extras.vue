<template>
    <div>
        <div
            v-for="extra in extras"
            :key="extra.id"
            class="w-full flex flex-wrap items-center justify-between p-3 shadow-sm rounded-md border transition-colors 
            bg-gray-100 dark:border-dark-900 dark:bg-dark-550 dark:shadow-dark-sm"
        >
            <div class="flex items-center space-x-2">
                <div class="little-dot" :class="extraEnabled(extra) ? 'bg-green-600' : 'bg-gray-400'"></div>
                <span class="font-medium cursor-pointer" v-html="extra.name" @click="editExtra(extra)"></span>
                <span>{{ extra.price }} <span class="text-xs text-gray-700 dark:text-dark-100" v-html="priceLabel(extra.price_type)"></span></span>
            </div>
            <div class="flex space-x-2">
                <span 
                    class="text-gray-700 dark:text-dark-100 text-sm uppercase cursor-pointer" 
                    v-html="extraEnabled(extra) ? 'Enabled' : 'Disabled'"
                    @click="associateEntryExtra(extra)"
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
            extrasLoaded: true,
            allowEntryExtraEdit: true,
            deleteId: false,
            drag: false,
            extra: '',
            category: '',
            emptyExtra: {
                name: '',
                slug: '',
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

    mounted() {
        // this.getAllExtras()        
    },

    updated() {
        if (! this.newItem) {
            this.$emit('input', this.parent)
        }
    },

    watch: {

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
        // getAllExtras() {
        //     let url = '/cp/resrv/extra/'
        //     if (this.insideEntry) {
        //         url += this.parent
        //     }
        //     axios.get(url)
        //     .then(response => {
        //         this.extras = response.data              
        //         this.extrasLoaded = true
        //     })
        //     .catch(error => {
        //         this.$toast.error('Cannot retrieve extras')
        //     })
        // },
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
                .catch(() => {
                    this.$toast.error('Extras ordering failed')
                })
        }        
    }
}
</script>