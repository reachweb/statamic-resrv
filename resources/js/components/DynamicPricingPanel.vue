<template>
    <stack name="statamic-resrv-dynamic-pricing" @closed="close">
        <div slot-scope="{ close }" class="h-full overflow-scroll overflow-x-auto bg-gray-100 dark:bg-dark-600">
            <header class="flex items-center sticky top-0 inset-x-0 bg-gray-300 dark:bg-dark-600 border-b dark:border-dark-900 shadow px-8 py-2 z-1 h-13">
                <div class="flex-1 flex items-center text-xl">Add dynamic pricing</div>
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
                            <div class="publish-fields w-full">
                                <div class="form-group w-full">
                                    <div class="mb-1 text-sm">
                                        <label class="font-semibold" for="name">Title</label>
                                    </div>
                                    <div class="w-full">
                                        <input class="input-text" name="name" type="text" v-model="submit.title">
                                    </div>
                                    <div v-if="errors.title" class="w-full mt-2 text-sm text-red-400">
                                        {{ errors.title[0] }}
                                    </div>  
                                </div>
                                <div class="form-group w-full xl:!w-1/3">
                                    <div class="mb-1 text-sm">
                                        <label class="font-semibold" for="name">Amount</label>
                                        <div class="text-sm font-light"><p>Amount or percentage without the % character.</p></div>
                                    </div>
                                    <div class="w-full">
                                        <input class="input-text" name="name" type="text" v-model="submit.amount">
                                    </div>
                                    <div v-if="errors.amount" class="w-full mt-2 text-sm text-red-400">
                                        {{ errors.amount[0] }}
                                    </div>  
                                </div>
                                <div class="form-group w-full xl:!w-1/3">
                                    <div class="mb-1 text-sm">
                                        <label class="font-semibold" for="name">Operation</label>
                                        <div class="text-sm font-light"><p>Select if the base price will be decreased or increased.</p></div>
                                    </div>
                                    <div class="w-full">
                                        <v-select v-model="submit.amount_operation" :options="amountOperation" :reduce="type => type.code" />
                                    </div>
                                    <div v-if="errors.amount_operation" class="w-full mt-2 text-sm text-red-400">
                                        {{ errors.amount_operation[0] }}
                                    </div>  
                                </div>
                                <div class="form-group w-full xl:!w-1/3">
                                    <div class="mb-1 text-sm">
                                        <label class="font-semibold" for="name">Type</label>
                                        <div class="text-sm font-light"><p>Percentage or fixed price.</p></div>
                                    </div>
                                    <div class="w-full">
                                        <v-select v-model="submit.amount_type" :options="amountType" :reduce="type => type.code" />
                                    </div>
                                    <div v-if="errors.amount_type" class="w-full mt-2 text-sm text-red-400">
                                        {{ errors.amount_type[0] }}
                                    </div>  
                                </div>
                                
                                <div class="form-group w-full xl:!w-1/2">
                                    <div class="mb-1 text-sm">
                                        <label class="font-semibold" for="name">Date condition</label>
                                        <div class="text-sm font-light"><p>Add a date condition.</p></div>
                                    </div>
                                    <div class="w-full">
                                        <v-select v-model="submit.date_include" :options="dateCondition" :reduce="type => type.code" @input="removeDate" />
                                    </div>
                                    <div v-if="errors.date_include" class="w-full mt-2 text-sm text-red-400">
                                        {{ errors.date_include[0] }}
                                    </div>  
                                </div>
                                <div class="form-group w-full xl:!w-1/2">
                                    <div class="mb-1 text-sm">
                                        <label class="font-semibold" for="name">Date range</label>
                                        <div class="text-sm font-light"><p>Select the range of the date condition.</p></div>
                                    </div>
                                    <div class="w-full">
                                        <div class="date-container input-group w-full">
                                            <v-date-picker
                                                v-model="date"
                                                :model-config="modelConfig"
                                                :popover="{ visibility: 'click' }"
                                                :masks="{ input: 'YYYY-MM-DD' }"
                                                :mode="'date'"
                                                :columns="$screens({ default: 1, lg: 2 })"
                                                is-range
                                                >
                                                <template v-slot="{ inputValue, inputEvents }">
                                                    <div class="w-full flex items-center">
                                                    <div class="input-group">
                                                        <div class="input-group-prepend flex items-center">
                                                            <svg-icon name="light/calendar" class="w-4 h-4" />
                                                        </div>
                                                        <div class="input-text border border-grey-50 border-l-0" :class="{ 'read-only': isReadOnly }">
                                                            <input
                                                                class="input-text-minimal p-0 bg-transparent leading-none"
                                                                :value="inputValue.start"
                                                                v-on="inputEvents.start"
                                                            />
                                                        </div>
                                                    </div>
                                                    <div class="icon icon-arrow-right my-sm mx-1 text-grey-60" />
                                                    <div class="input-group">
                                                        <div class="input-group-prepend flex items-center">
                                                            <svg-icon name="light/calendar" class="w-4 h-4" />
                                                        </div>
                                                        <div class="input-text border border-grey-50 border-l-0" :class="{ 'read-only': isReadOnly }">
                                                            <input
                                                                class="input-text-minimal p-0 bg-transparent leading-none"
                                                                :value="inputValue.end"
                                                                v-on="inputEvents.end"
                                                            />
                                                        </div>
                                                    </div>
                                                </div>
                                                </template>
                                            </v-date-picker>
                                        </div>
                                    </div>
                                    <div v-if="errors.date_start" class="w-full mt-2 text-sm text-red-400">
                                        {{ errors.date_start[0] }}
                                    </div>  
                                    <div v-if="errors.date_end" class="w-full mt-2 text-sm text-red-400">
                                        {{ errors.date_end[0] }}
                                    </div>  
                                </div>
                                <div class="form-group w-full xl:!w-1/3">
                                    <div class="mb-1 text-sm">
                                        <label class="font-semibold" for="name">Reservation condition</label>
                                        <div class="text-sm font-light"><p>Apply the dynamic pricing when...</p></div>
                                    </div>
                                    <div class="w-full">
                                        <v-select v-model="submit.condition_type" :options="conditionType" :reduce="type => type.code" />
                                    </div>
                                    <div v-if="errors.condition_type" class="w-full mt-2 text-sm text-red-400">
                                        {{ errors.condition_type[0] }}
                                    </div>  
                                </div>
                                <div class="form-group w-full xl:!w-1/3">
                                    <div class="mb-1 text-sm">
                                        <label for="name">Comparison</label>
                                        <div class="text-sm font-light"><p>Select the comparion operator</p></div>
                                    </div>
                                    <div class="w-full">
                                        <v-select v-model="submit.condition_comparison" :options="conditionComparison" :reduce="type => type.code" />
                                    </div>
                                    <div v-if="errors.condition_comparison" class="w-full mt-2 text-sm text-red-400">
                                        {{ errors.condition_comparison[0] }}
                                    </div>  
                                </div>
                                <div class="form-group w-full xl:!w-1/3">
                                    <div class="mb-1 text-sm">
                                        <label class="font-semibold" for="name">Value</label>
                                        <div class="text-sm font-light"><p>The value to compare to (days or price).</p></div>
                                    </div>
                                    <div class="w-full">
                                        <input class="input-text" name="name" type="text" v-model="submit.condition_value">
                                    </div>
                                    <div v-if="errors.condition_value" class="w-full mt-1 text-sm text-red-400">
                                        {{ errors.condition_value[0] }}
                                    </div>  
                                </div>

                                <div class="form-group w-full 2xl:!w-1/2">
                                    <div class="flex items-center justify-between mb-1">
                                        <div class="mr-2">
                                            <label class="font-semibold" for="name">Entries</label>
                                            <div class="text-sm font-light"><p>Select the entries that this dynamic pricing applies to</p></div>
                                        </div>
                                    <div class="flex justify-end cursor-pointer mt-2">
                                            <span class="text-xs text-gray-700" @click="selectAllEntries()">Select all</span>
                                            <span class="text-xs text-gray-700 mx-1">|</span>
                                            <span class="text-xs text-gray-700" @click="clearAllEntries()">Clear</span>
                                        </div>
                                    </div>
                                    <div class="w-full">
                                        <v-select 
                                            v-model="submit.entries"
                                            label="title"
                                            multiple="multiple"
                                            :close-on-select="false"
                                            :deselectFromDropdown="true"
                                            :options="entries" 
                                            :searchable="true"
                                            :reduce="type => type.item_id" 
                                        >
                                            <template #selected-option-container><i class="hidden"></i></template>
                                            <template #footer="{ deselect }" v-if="entriesLoaded">
                                                <div class="vs__selected-options-outside flex flex-wrap">
                                                    <span v-for="id in submit.entries" :key="id" class="vs__selected mt-1">
                                                        {{ getEntryTitle(id) }}
                                                        <button 
                                                            @click="() => submit.entries = submit.entries.filter(entry => entry !== id)" 
                                                            type="button" 
                                                            :aria-label="__('Deselect option')" 
                                                            class="vs__deselect"
                                                        >
                                                            <span>×</span>
                                                        </button>
                                                    </span>
                                                </div>
                                            </template>
                                        </v-select>
                                    </div>
                                    <div v-if="errors.entries" class="w-full mt-2 text-sm text-red-400">
                                        {{ errors.entries[0] }}
                                    </div>  
                                </div>
                                
                                <div class="form-group w-full 2xl:!w-1/2">
                                    <div class="flex items-center justify-between mb-1">
                                        <div class="mr-2">
                                            <label class="font-semibold" for="name">Extras</label>
                                            <div class="text-sm font-light"><p>Select the extras that this dynamic pricing applies to</p></div>
                                        </div>
                                        <div class="flex justify-end cursor-pointer mt-2">
                                            <span class="text-xs text-gray-700" @click="selectAllExtras()">Select all</span>
                                            <span class="text-xs text-gray-700 mx-1">|</span>
                                            <span class="text-xs text-gray-700" @click="clearAllExtras()">Clear</span>
                                        </div>
                                    </div>
                                    <div class="w-full">
                                        <v-select 
                                            v-model="submit.extras" 
                                            label="name"
                                            multiple="multiple"
                                            :close-on-select="false"
                                            :deselectFromDropdown="true"
                                            :options="extras" 
                                            :searchable="true"
                                            :reduce="type => type.id" 
                                        >
                                            <template #selected-option-container><i class="hidden"></i></template>
                                            <template #footer="{ deselect }" v-if="extrasLoaded">
                                                <div class="vs__selected-options-outside flex flex-wrap">
                                                    <span v-for="id in submit.extras" :key="id" class="vs__selected mt-1">
                                                        {{ getExtraTitle(id) }}
                                                        <button @click="deselect(id)" type="button" :aria-label="__('Deselect option')" class="vs__deselect">
                                                            <span>×</span>
                                                        </button>                 
                                                    </span>
                                                </div>
                                            </template>
                                        </v-select>
                                    </div>
                                    <div v-if="errors.extras" class="w-full mt-2 text-sm text-red-400">
                                        {{ errors.extras[0] }}
                                    </div>  
                                </div>

                                <div class="form-group w-full 2xl:!w-1/2">
                                    <div class="mb-1 text-sm">
                                        <label class="font-semibold" for="name">Coupon</label>
                                        <div class="text-sm font-light"><p>Dynamic pricing applied only if coupon is applied during checkout. Coupons get applied even if a another policy is set as overriding.</p></div>
                                    </div>
                                    <div class="w-full">
                                        <input class="input-text" name="name" type="text" v-model="submit.coupon">
                                    </div>
                                    <div v-if="errors.coupon" class="w-full mt-2 text-sm text-red-400">
                                        {{ errors.coupon[0] }}
                                    </div>  
                                </div>

                                <div class="form-group w-full 2xl:!w-1/2">
                                    <div class="mb-1 text-sm">
                                        <label class="font-semibold" for="name">Expire at</label>
                                        <div class="text-sm font-light"><p>Select a date / time that his dynamic pricing will expire.</p></div>
                                    </div>
                                    <div class="w-full">
                                        <div class="date-container input-group w-full">
                                            <v-date-picker
                                                v-model="submit.expire_at"
                                                :model-config="modelConfig"
                                                :popover="{ visibility: 'click' }"
                                                :masks="{ input: 'YYYY-MM-DD' }"
                                                :mode="'dateTime'"
                                                :timezone="timezone"
                                                is24hr
                                                >
                                                <template v-slot="{ inputValue, inputEvents }">
                                                    <div class="input-group">
                                                    <div class="input-group-prepend flex items-center">
                                                        <svg-icon name="light/calendar" class="w-4 h-4" />
                                                    </div>
                                                    <div class="input-text border border-grey-50 border-l-0">
                                                        <input
                                                            class="input-text-minimal p-0 bg-transparent leading-none"
                                                            :value="inputValue"
                                                            v-on="inputEvents"
                                                        />
                                                    </div>
                                                </div>
                                                </template>
                                            </v-date-picker>
                                        </div>
                                    </div>
                                    <div v-if="errors.expire_at" class="w-full mt-2 text-sm text-red-400">
                                        {{ errors.expire_at[0] }}
                                    </div>  
                                </div>

                                <div class="form-group field-w-full">
                                    <div class="flex items-center">
                                        <toggle-input v-model="submit.overrides_all"></toggle-input> 
                                        <div class="text-sm ml-3">{{ __('Overrides all other dynamic pricing policies') }}</div>
                                    </div>
                                </div>                 
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </stack>
</template>

<script>
import axios from 'axios'
import FormHandler from '../mixins/FormHandler.vue'
import vSelect from 'vue-select'

export default {

    props: {
        data: {
            type: Object,
            required: true
        },
        timezone: {
            type: String,
            required: true
        },
        openPanel: {
            type: Boolean,
            default: false
        }
    },

    computed: {
        method() {
            if (_.has(this.data, 'id')) {
                return 'patch'
            }
            return 'post'
        }
    },  

    data() {
        return {
            submit: {},
            successMessage: 'Dynamic pricing successfully saved',
            postUrl: '/cp/resrv/dynamicpricing',
            amountOperation: [
                {
                    code: "decrease",
                    label: "Decrease"
                },
                {
                    code: "increase",
                    label: "Increase"
                }
            ],
            amountType: [
                {
                    code: "percent",
                    label: "Percent"
                },
                {
                    code: "fixed",
                    label: "Fixed"
                }
            ],
            conditionType: [
                {
                    code: "reservation_duration",
                    label: "The duration of the reservation is"
                },
                {
                    code: "reservation_price",
                    label: "The total price of the reservation is"
                },
                {
                    code: "days_to_reservation",
                    label: "Reservation start date compared to reservation made date"
                }
            ],
            conditionComparison: [
                {
                    code: "==",
                    label: "Equal to"
                },
                {
                    code: "!=",
                    label: "Not equal to"
                },
                {
                    code: ">",
                    label: "Greater than"
                },
                {
                    code: "<",
                    label: "Less than"
                },
                {
                    code: ">=",
                    label: "Greater or equal to"
                },
                {
                    code: "<=",
                    label: "Less or equal to"
                }
            ],
            dateCondition: [
                {
                    code: "all",
                    label: "Reservation dates must be inside this date range"
                },
                {
                    code: "start",
                    label: "Reservation starting date must be inside this date range"
                },
                {
                    code: "most",
                    label: "Most of the reservation dates must be inside this date range"
                }
            ],
            date: {
                start: '',
                end: ''
            },
            entries: '',
            entriesLoaded: false, 
            extras: '',
            extrasLoaded: false,            
        }
    },

    mixins: [FormHandler],

    components: [vSelect],

      watch: {
        data() {
            this.createSubmit()
        },
        date() {
            if (this.date) {
                this.submit.date_start = Vue.moment(this.date.start).format('YYYY-MM-DD')
                this.submit.date_end = Vue.moment(this.date.end).format('YYYY-MM-DD')
            } else {
                this.submit.date_start = ''
                this.submit.date_end = ''
            }
        }
    },

    mounted() {
        this.createSubmit()
    },

    created() {
        this.getEntries()
        this.getExtras()
    },

    methods: {
        close() {
            this.submit = {}
            this.$emit('closed')
        },
        createSubmit() {
            this.submit = {}
            _.forEach(this.data, (value, name) => {
                this.$set(this.submit, name, value)
            })
            if (this.data.date_start && this.data.date_end) {
                this.date = {
                    start: Vue.moment(this.data.date_start).toDate(),
                    end: Vue.moment(this.data.date_end).toDate()
                }
            }            
            if (_.has(this.data, 'id')) {
                this.postUrl = '/cp/resrv/dynamicpricing/'+this.data.id
            } else {
                this.postUrl = '/cp/resrv/dynamicpricing'
            }
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
        getExtras() {
            axios.get('/cp/resrv/extra')
            .then(response => {
                this.extras = response.data
                this.extrasLoaded = true
            })
            .catch(error => {
                this.$toast.error('Cannot retrieve the extras')
            })
        },
        selectAllExtras() {
            this.submit.extras = _.map(this.extras, (item) => item.id)
        },
        selectAllEntries() {
            this.submit.entries = _.map(this.entries, (item) => item.item_id)
        },
        clearAllExtras() {
            this.submit.extras = []
        },
        clearAllEntries() {
            this.submit.entries = []
        },
        getEntryTitle(id) {
            return this.entries.find(item => item.item_id == id).title
        },
        getExtraTitle(id) {
            return this.extras.find(item => item.id == id).name
        },
        removeDate(val) {
            if (val == null) {
                this.date = null
            }            
        }
    }
}
</script>
