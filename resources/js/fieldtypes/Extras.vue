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
                        <span class="text-sm cursor-pointer" @click="editExtra(extra)">Edit</span>
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
            
    }
}
</script>
