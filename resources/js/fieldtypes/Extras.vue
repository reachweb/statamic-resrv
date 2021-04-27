<template>
    <element-container @resized="containerWidth = $event.width">
    <div class="w-full h-full text-center my-4 text-gray-700 text-lg" v-if="newItem">
        You need to save this entry before you can add extras.
    </div>
    <div class="statamic-resrv-extras relative" v-else>
        <div class="text-sm text-gray-600">Please note that editing an extra here will change it for all entries. </div>
        <div class="w-full h-full" v-if="extrasLoaded">
            <div class="mt-4 space-y-1">
                <div
                    v-for="extra in extras"
                    :key="extra.id"
                    class="w-full flex items-center justify-between px-3 py-1 shadow rounded-md transition-colors"
                    :class="extraEnabled(extra.id) ? 'bg-green-200' : 'bg-white'"                    
                >
                    <div class="space-x-2">
                        <span class="font-medium" v-html="extra.name"></span>
                        <span>{{ extra.price }} <span class="text-xs text-gray-500" v-html="priceLabel(extra.price_type)"></span></span>
                    </div>
                    <div class="space-x-2">
                        <span 
                            class="text-gray-500 text-sm uppercase cursor-pointer" 
                            v-html="extraEnabled(extra.id) ? 'Enabled' : 'Disabled'"
                            @click="associateEntryExtra(extra.id)"
                        ></span>
                        <span class="cursor-pointer text-red-800" @click="confirmDelete(extra)">
                            <svg class="stroke-current text-red-800" xmlns="http://www.w3.org/2000/svg" version="1.1" xmlns:xlink="http://www.w3.org/1999/xlink" xmlns:svgjs="http://svgjs.com/svgjs" viewBox="0 0 18 18" width="18" height="18">
                                <g transform="matrix(0.75,0,0,0.75,0,0)">
                                    <path d="M0.5 6.507L23.5 6.507" fill="none"  stroke-linecap="round" stroke-linejoin="round"></path>                                    
                                    <path d="M20.5,6.5v15a2,2,0,0,1-2,2H5.5a2,2,0,0,1-2-2V6.5" fill="none"  stroke-linecap="round" stroke-linejoin="round"></path>
                                    <path d="M2.5,6.5v-1a2,2,0,0,1,2-2h15a2,2,0,0,1,2,2v1" fill="none"  stroke-linecap="round" stroke-linejoin="round"></path>
                                    <path d="M9,3.5a3,3,0,0,1,6,0" fill="none"  stroke-linecap="round" stroke-linejoin="round"></path>
                                    <path d="M12 10L12 19.5" fill="none"  stroke-linecap="round" stroke-linejoin="round"></path>
                                    <path d="M16.5 10L16.5 19.5" fill="none"  stroke-linecap="round" stroke-linejoin="round"></path>
                                    <path d="M7.5 10L7.5 19.5" fill="none"  stroke-linecap="round" stroke-linejoin="round"></path>
                                </g>
                            </svg>
                        </span>
                        <span class="cursor-pointer" @click="editExtra(extra)">
                            <svg class="stroke-current" xmlns="http://www.w3.org/2000/svg" version="1.1" xmlns:xlink="http://www.w3.org/1999/xlink" xmlns:svgjs="http://svgjs.com/svgjs" viewBox="0 0 18 18" width="18" height="18">
                                <g transform="matrix(0.75,0,0,0.75,0,0)">
                                    <path d="M7 21.5L0.5 23.5 2.5 17 15.33 4.169 19.83 8.669 7 21.5z" fill="none" stroke-linecap="round" stroke-linejoin="round"></path>
                                    <path d="M15.33,4.169l3.086-3.086a2.007,2.007,0,0,1,2.828,0l1.672,1.672a2,2,0,0,1,0,2.828L19.83,8.669" fill="none" stroke-linecap="round" stroke-linejoin="round"></path>
                                    <path d="M17.58 6.419L6 18" fill="none" stroke-linecap="round" stroke-linejoin="round"></path>
                                    <path d="M2.5 17L3.5 18 6 18 6 20.5 7 21.5" fill="none" stroke-linecap="round" stroke-linejoin="round"></path>
                                    <path d="M1.5 20.5L3.5 22.5" fill="none" stroke-linecap="round" stroke-linejoin="round"></path>
                                    <path d="M16.83 2.669L21.33 7.169" fill="none" stroke-linecap="round" stroke-linejoin="round"></path>
                                </g>
                            </svg>
                        </span>
                    </div>
                </div>
            </div>
        </div>
        <div class="w-full mt-4">
            <button class="px-2 py-1 bg-gray-600 hover:bg-gray-800 transition-colors text-white rounded cursor-pointer" @click="addExtra">
                Add extra
            </button>
        </div>
        <extras-panel            
            v-if="showPanel"
            :data="extra"
            @closed="togglePanel"
            @saved="extraSaved"
        >
        </extras-panel>
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
    </element-container>

</template>

<script>
import ExtrasPanel from '../components/ExtrasPanel.vue'
import Loader from '../components/Loader.vue'
import axios from 'axios'

export default {

    mixins: [Fieldtype],

    data() {
        return {
            containerWidth: null,
            showPanel: false,
            extras: '',
            entryExtras: '',
            activeExtras: [],
            extrasLoaded: false,
            allowEntryExtraEdit: true,
            deleteId: false,
            extra: '',
            emptyExtra: {
                name: '',
                slug: '',
                price: '',
                price_type: '',
                published : 1
            }
        }
    },

    components: {
        Loader,
        ExtrasPanel
    },

    computed: {
        newItem() {
            if (this.meta.parent == 'Collection') {
                return true
            }
            return false
        }
    },

    mounted() {
        this.getAllExtras()
    },

    watch: {
        extrasLoaded() {
            this.createEnabledExtrasArray()
        },
        entryExtras() {
            this.createEnabledExtrasArray()
        }
    },

    methods: {
        togglePanel() {
            this.showPanel = !this.showPanel
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
        extraSaved() {
            this.togglePanel()
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
                this.getEntryExtras()
            })
            .catch(error => {
                this.$toast.error('Cannot retrieve extras')
            })
        },
        getEntryExtras() {
            axios.get('/cp/resrv/extra/'+this.meta.parent)
            .then(response => {
                this.entryExtras = response.data
                this.extrasLoaded = true
            })
            .catch(error => {
                this.$toast.error('Cannot retrieve entry extras')
            })
        },
        enableExtra(extraId) {
            axios.post('/cp/resrv/extra/add/'+this.meta.parent, {'id': extraId})
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
            axios.post('/cp/resrv/extra/remove/'+this.meta.parent, {'id': extraId})
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
        }            
    }
}
</script>
