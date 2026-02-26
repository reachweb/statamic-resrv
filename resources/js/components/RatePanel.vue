<template>
    <stack name="statamic-resrv-rate" @closed="close">
        <div slot-scope="{ close }" class="h-full overflow-scroll overflow-x-auto bg-gray-100 dark:bg-dark-600">
            <header class="flex items-center sticky top-0 inset-x-0 bg-gray-300 dark:bg-dark-600 border-b dark:border-dark-900 shadow px-8 py-2 z-1 h-13">
                <div class="flex-1 flex items-center text-xl">{{ isEditing ? 'Edit rate' : 'Add rate' }}</div>
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

                    <!-- General -->
                    <div class="publish-sections-section">
                        <div class="text-base mb-2 font-bold">General</div>
                        <div class="card">
                            <div class="publish-fields w-full">
                                <div class="form-group w-full xl:!w-1/2">
                                    <div class="mb-1 text-sm">
                                        <label class="font-semibold" for="collection">Collection</label>
                                        <div class="text-sm font-light"><p>The collection this rate applies to.</p></div>
                                    </div>
                                    <div class="w-full">
                                        <v-select v-model="submit.collection" :options="collections" label="title" :reduce="c => c.handle" :clearable="false" :disabled="isEditing" />
                                    </div>
                                    <div v-if="errors.collection" class="w-full mt-2 text-sm text-red-400">
                                        {{ errors.collection[0] }}
                                    </div>
                                </div>
                                <div class="form-group w-full">
                                    <div class="flex items-center">
                                        <toggle-input v-model="submit.apply_to_all"></toggle-input>
                                        <div class="text-sm ml-3">{{ __('Apply to all entries in collection') }}</div>
                                    </div>
                                </div>
                                <div class="form-group w-full" v-if="!submit.apply_to_all">
                                    <div class="mb-1 text-sm">
                                        <label class="font-semibold" for="entries">Entries</label>
                                        <div class="text-sm font-light"><p>Select the entries this rate should apply to.</p></div>
                                    </div>
                                    <div class="w-full" v-if="entriesLoaded">
                                        <v-select
                                            v-model="submit.entries"
                                            label="title"
                                            multiple="multiple"
                                            :close-on-select="false"
                                            :options="collectionEntries"
                                            :searchable="true"
                                            :reduce="entry => entry.id"
                                        >
                                            <template #selected-option-container><i class="hidden"></i></template>
                                            <template #footer="{ deselect }">
                                                <div class="vs__selected-options-outside flex flex-wrap">
                                                    <span v-for="id in submit.entries" :key="id" class="vs__selected mt-1">
                                                        {{ getEntryTitle(id) }}
                                                        <button @click="deselect(id)" type="button" :aria-label="__('Deselect option')" class="vs__deselect">
                                                            <span>&times;</span>
                                                        </button>
                                                    </span>
                                                </div>
                                            </template>
                                        </v-select>
                                    </div>
                                    <div class="flex mt-2" v-if="entriesLoaded">
                                        <button class="btn-flat text-sm" @click="selectAllEntries">{{ __('Select all') }}</button>
                                        <button class="btn-flat text-sm ml-2" @click="removeAllEntries">{{ __('Remove all') }}</button>
                                    </div>
                                    <div v-if="errors.entries" class="w-full mt-2 text-sm text-red-400">
                                        {{ errors.entries[0] }}
                                    </div>
                                </div>
                                <div class="form-group w-full xl:!w-1/2">
                                    <div class="mb-1 text-sm">
                                        <label class="font-semibold" for="title">Title</label>
                                    </div>
                                    <div class="w-full">
                                        <input class="input-text" name="title" type="text" v-model="submit.title" @input="slugify">
                                    </div>
                                    <div v-if="errors.title" class="w-full mt-2 text-sm text-red-400">
                                        {{ errors.title[0] }}
                                    </div>
                                </div>
                                <div class="form-group w-full xl:!w-1/2">
                                    <div class="mb-1 text-sm">
                                        <label class="font-semibold" for="slug">Slug</label>
                                    </div>
                                    <div class="w-full">
                                        <input class="input-text" name="slug" type="text" v-model="submit.slug">
                                    </div>
                                    <div v-if="errors.slug" class="w-full mt-2 text-sm text-red-400">
                                        {{ errors.slug[0] }}
                                    </div>
                                </div>
                                <div class="form-group w-full">
                                    <div class="mb-1 text-sm">
                                        <label class="font-semibold" for="description">Description</label>
                                    </div>
                                    <div class="w-full">
                                        <textarea class="input-text" name="description" v-model="submit.description"></textarea>
                                    </div>
                                    <div v-if="errors.description" class="w-full mt-2 text-sm text-red-400">
                                        {{ errors.description[0] }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Pricing -->
                    <div class="publish-sections-section">
                        <div class="text-base mb-2 font-bold">Pricing</div>
                        <div class="card">
                            <div class="publish-fields w-full">
                                <div class="form-group w-full xl:!w-1/2">
                                    <div class="mb-1 text-sm">
                                        <label class="font-semibold" for="pricing_type">Pricing type</label>
                                        <div class="text-sm font-light"><p>Independent rates have their own pricing. Relative rates derive pricing from a base rate.</p></div>
                                    </div>
                                    <div class="w-full">
                                        <v-select v-model="submit.pricing_type" :options="pricingTypes" :reduce="t => t.code" />
                                    </div>
                                    <div v-if="errors.pricing_type" class="w-full mt-2 text-sm text-red-400">
                                        {{ errors.pricing_type[0] }}
                                    </div>
                                </div>
                                <div class="form-group w-full xl:!w-1/2" v-if="submit.pricing_type === 'relative'">
                                    <div class="mb-1 text-sm">
                                        <label class="font-semibold" for="base_rate_id">Base rate</label>
                                        <div class="text-sm font-light"><p>The rate to derive pricing from.</p></div>
                                    </div>
                                    <div class="w-full">
                                        <v-select v-model="submit.base_rate_id" :options="availableBaseRates" :reduce="r => r.code" />
                                    </div>
                                    <div v-if="errors.base_rate_id" class="w-full mt-2 text-sm text-red-400">
                                        {{ errors.base_rate_id[0] }}
                                    </div>
                                </div>
                                <div class="form-group w-full xl:!w-1/3" v-if="submit.pricing_type === 'relative'">
                                    <div class="mb-1 text-sm">
                                        <label class="font-semibold" for="modifier_type">Modifier type</label>
                                        <div class="text-sm font-light"><p>Percentage or fixed amount.</p></div>
                                    </div>
                                    <div class="w-full">
                                        <v-select v-model="submit.modifier_type" :options="modifierTypes" :reduce="t => t.code" />
                                    </div>
                                    <div v-if="errors.modifier_type" class="w-full mt-2 text-sm text-red-400">
                                        {{ errors.modifier_type[0] }}
                                    </div>
                                </div>
                                <div class="form-group w-full xl:!w-1/3" v-if="submit.pricing_type === 'relative'">
                                    <div class="mb-1 text-sm">
                                        <label class="font-semibold" for="modifier_operation">Modifier operation</label>
                                        <div class="text-sm font-light"><p>Increase or decrease from base rate.</p></div>
                                    </div>
                                    <div class="w-full">
                                        <v-select v-model="submit.modifier_operation" :options="modifierOperations" :reduce="t => t.code" />
                                    </div>
                                    <div v-if="errors.modifier_operation" class="w-full mt-2 text-sm text-red-400">
                                        {{ errors.modifier_operation[0] }}
                                    </div>
                                </div>
                                <div class="form-group w-full xl:!w-1/3" v-if="submit.pricing_type === 'relative'">
                                    <div class="mb-1 text-sm">
                                        <label class="font-semibold" for="modifier_amount">Modifier amount</label>
                                        <div class="text-sm font-light"><p>Amount or percentage without the % character.</p></div>
                                    </div>
                                    <div class="w-full">
                                        <input class="input-text" name="modifier_amount" type="text" v-model="submit.modifier_amount">
                                    </div>
                                    <div v-if="errors.modifier_amount" class="w-full mt-2 text-sm text-red-400">
                                        {{ errors.modifier_amount[0] }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Availability -->
                    <div class="publish-sections-section">
                        <div class="text-base mb-2 font-bold">Availability</div>
                        <div class="card">
                            <div class="publish-fields w-full">
                                <div class="form-group w-full xl:!w-1/2">
                                    <div class="mb-1 text-sm">
                                        <label class="font-semibold" for="availability_type">Availability type</label>
                                        <div class="text-sm font-light"><p>Independent rates have their own inventory. Shared rates share inventory with the base rate.</p></div>
                                    </div>
                                    <div class="w-full">
                                        <v-select v-model="submit.availability_type" :options="availabilityTypes" :reduce="t => t.code" />
                                    </div>
                                    <div v-if="errors.availability_type" class="w-full mt-2 text-sm text-red-400">
                                        {{ errors.availability_type[0] }}
                                    </div>
                                </div>
                                <div class="form-group w-full xl:!w-1/2" v-if="submit.availability_type === 'shared'">
                                    <div class="mb-1 text-sm">
                                        <label class="font-semibold" for="max_available">Max available</label>
                                        <div class="text-sm font-light"><p>Maximum number of units available for this rate.</p></div>
                                    </div>
                                    <div class="w-full">
                                        <input class="input-text" name="max_available" type="number" v-model="submit.max_available">
                                    </div>
                                    <div v-if="errors.max_available" class="w-full mt-2 text-sm text-red-400">
                                        {{ errors.max_available[0] }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Restrictions -->
                    <div class="publish-sections-section">
                        <div class="text-base mb-2 font-bold">Restrictions</div>
                        <div class="card">
                            <div class="publish-fields w-full">
                                <div class="form-group w-full">
                                    <div class="mb-1 text-sm">
                                        <label class="font-semibold">Date range</label>
                                        <div class="text-sm font-light"><p>Rate is available within this date range.</p></div>
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
                                                            <div class="input-text border border-grey-50 border-l-0">
                                                                <input
                                                                    class="input-text-minimal p-0 bg-transparent leading-none"
                                                                    :value="inputValue.start"
                                                                    v-on="inputEvents.start"
                                                                    placeholder="Start date"
                                                                />
                                                            </div>
                                                        </div>
                                                        <div class="icon icon-arrow-right my-sm mx-1 text-grey-60" />
                                                        <div class="input-group">
                                                            <div class="input-group-prepend flex items-center">
                                                                <svg-icon name="light/calendar" class="w-4 h-4" />
                                                            </div>
                                                            <div class="input-text border border-grey-50 border-l-0">
                                                                <input
                                                                    class="input-text-minimal p-0 bg-transparent leading-none"
                                                                    :value="inputValue.end"
                                                                    v-on="inputEvents.end"
                                                                    placeholder="End date"
                                                                />
                                                            </div>
                                                        </div>
                                                        <button v-if="date" class="btn-flat text-sm ml-2" @click="clearDate">Clear</button>
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
                                <div class="form-group w-full xl:!w-1/4">
                                    <div class="mb-1 text-sm">
                                        <label class="font-semibold" for="min_days_before">Min days before</label>
                                        <div class="text-sm font-light"><p>Minimum advance booking days.</p></div>
                                    </div>
                                    <div class="w-full">
                                        <input class="input-text" name="min_days_before" type="number" v-model="submit.min_days_before">
                                    </div>
                                    <div v-if="errors.min_days_before" class="w-full mt-2 text-sm text-red-400">
                                        {{ errors.min_days_before[0] }}
                                    </div>
                                </div>
                                <div class="form-group w-full xl:!w-1/4">
                                    <div class="mb-1 text-sm">
                                        <label class="font-semibold" for="max_days_before">Max days before</label>
                                        <div class="text-sm font-light"><p>Maximum advance booking days.</p></div>
                                    </div>
                                    <div class="w-full">
                                        <input class="input-text" name="max_days_before" type="number" v-model="submit.max_days_before">
                                    </div>
                                    <div v-if="errors.max_days_before" class="w-full mt-2 text-sm text-red-400">
                                        {{ errors.max_days_before[0] }}
                                    </div>
                                </div>
                                <div class="form-group w-full xl:!w-1/4">
                                    <div class="mb-1 text-sm">
                                        <label class="font-semibold" for="min_stay">Min stay</label>
                                        <div class="text-sm font-light"><p>Minimum number of nights.</p></div>
                                    </div>
                                    <div class="w-full">
                                        <input class="input-text" name="min_stay" type="number" v-model="submit.min_stay">
                                    </div>
                                    <div v-if="errors.min_stay" class="w-full mt-2 text-sm text-red-400">
                                        {{ errors.min_stay[0] }}
                                    </div>
                                </div>
                                <div class="form-group w-full xl:!w-1/4">
                                    <div class="mb-1 text-sm">
                                        <label class="font-semibold" for="max_stay">Max stay</label>
                                        <div class="text-sm font-light"><p>Maximum number of nights.</p></div>
                                    </div>
                                    <div class="w-full">
                                        <input class="input-text" name="max_stay" type="number" v-model="submit.max_stay">
                                    </div>
                                    <div v-if="errors.max_stay" class="w-full mt-2 text-sm text-red-400">
                                        {{ errors.max_stay[0] }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Settings -->
                    <div class="publish-sections-section">
                        <div class="text-base mb-2 font-bold">Settings</div>
                        <div class="card">
                            <div class="publish-fields w-full">
                                <div class="form-group w-full">
                                    <div class="flex items-center">
                                        <toggle-input v-model="submit.refundable"></toggle-input>
                                        <div class="text-sm ml-3">{{ __('Refundable') }}</div>
                                    </div>
                                </div>
                                <div class="form-group w-full">
                                    <div class="flex items-center">
                                        <toggle-input v-model="submit.published"></toggle-input>
                                        <div class="text-sm ml-3">{{ __('Published') }}</div>
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
import FormHandler from '../mixins/FormHandler.vue'
import axios from 'axios'
import vSelect from 'vue-select'

export default {

    props: {
        data: {
            type: Object,
            required: true
        },
        allRates: {
            type: Array,
            default: () => []
        },
        collections: {
            type: Array,
            default: () => []
        },
        selectedCollection: {
            type: String,
            default: null
        }
    },

    computed: {
        isEditing() {
            return _.has(this.data, 'id')
        },
        method() {
            if (this.isEditing) {
                return 'patch'
            }
            return 'post'
        },
        availableBaseRates() {
            return this.allRates
                .filter(rate => rate.id !== this.data.id)
                .map(rate => ({ code: rate.id, label: rate.title }))
        }
    },

    data() {
        return {
            submit: {},
            collectionEntries: [],
            entriesLoaded: false,
            successMessage: 'Rate successfully saved',
            postUrl: '/cp/resrv/rate',
            date: null,
            skipDateWatch: false,
            pricingTypes: [
                { code: 'independent', label: 'Independent' },
                { code: 'relative', label: 'Relative' }
            ],
            modifierTypes: [
                { code: 'percent', label: 'Percent' },
                { code: 'fixed', label: 'Fixed' }
            ],
            modifierOperations: [
                { code: 'increase', label: 'Increase' },
                { code: 'decrease', label: 'Decrease' }
            ],
            availabilityTypes: [
                { code: 'independent', label: 'Independent' },
                { code: 'shared', label: 'Shared' }
            ],
        }
    },

    mixins: [FormHandler],

    components: { vSelect },

    watch: {
        data() {
            this.createSubmit()
        },
        date() {
            if (this.skipDateWatch) {
                this.skipDateWatch = false
                return
            }
            if (this.date) {
                this.submit.date_start = Vue.moment(this.date.start).format('YYYY-MM-DD')
                this.submit.date_end = Vue.moment(this.date.end).format('YYYY-MM-DD')
            } else {
                this.submit.date_start = null
                this.submit.date_end = null
            }
        },
        'submit.collection'(newVal) {
            if (newVal) {
                this.getCollectionEntries(newVal)
            }
        },
        'submit.apply_to_all'(newVal) {
            if (newVal) {
                this.submit.entries = []
            }
        }
    },

    mounted() {
        this.createSubmit()
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
            if (!_.has(this.submit, 'entries')) {
                this.$set(this.submit, 'entries', [])
            }
            if (this.data.date_start && this.data.date_end) {
                this.date = {
                    start: Vue.moment(this.data.date_start).toDate(),
                    end: Vue.moment(this.data.date_end).toDate()
                }
            } else {
                this.skipDateWatch = true
                this.date = null
                this.submit.date_start = this.data.date_start || null
                this.submit.date_end = this.data.date_end || null
            }
            if (this.isEditing) {
                this.postUrl = '/cp/resrv/rate/' + this.data.id
                this.loadAssignedEntries()
            } else {
                this.postUrl = '/cp/resrv/rate'
            }
        },
        slugify() {
            this.submit.slug = this.$slugify(this.submit.title)
        },
        clearDate() {
            this.date = null
        },
        getCollectionEntries(collection) {
            axios.get('/cp/resrv/rates/entries/' + collection)
            .then(response => {
                this.collectionEntries = response.data
                this.entriesLoaded = true
            })
            .catch(error => {
                this.$toast.error('Cannot retrieve entries')
            })
        },
        loadAssignedEntries() {
            if (this.data.entries && this.data.entries.length > 0) {
                this.submit.entries = this.data.entries.map(e => e.item_id || e.id)
            }
        },
        getEntryTitle(id) {
            let entry = this.collectionEntries.find(item => item.id == id)
            return entry ? entry.title : id
        },
        selectAllEntries() {
            this.submit.entries = this.collectionEntries.map(e => e.id)
        },
        removeAllEntries() {
            this.submit.entries = []
        }
    }
}
</script>
