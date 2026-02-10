<template>
    <stack name="statamic-resrv-rate" @closed="close">
        <div slot-scope="{ close }" class="h-full overflow-scroll overflow-x-auto bg-gray-100 dark:bg-dark-600">
            <header class="flex items-center sticky top-0 inset-x-0 bg-gray-300 dark:bg-dark-600 border-b dark:border-dark-900 shadow px-8 py-2 z-1 h-13">
                <div class="flex-1 flex items-center text-xl">{{ _.has(data, 'id') ? 'Edit rate' : 'Add rate' }}</div>
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
                                <!-- Basic Info -->
                                <div class="form-group w-full">
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

                                <!-- Pricing -->
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

                                <!-- Availability -->
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

                                <!-- Restrictions -->
                                <div class="form-group w-full xl:!w-1/2">
                                    <div class="mb-1 text-sm">
                                        <label class="font-semibold" for="date_start">Date start</label>
                                        <div class="text-sm font-light"><p>Rate is available from this date.</p></div>
                                    </div>
                                    <div class="w-full">
                                        <input class="input-text" name="date_start" type="date" v-model="submit.date_start">
                                    </div>
                                    <div v-if="errors.date_start" class="w-full mt-2 text-sm text-red-400">
                                        {{ errors.date_start[0] }}
                                    </div>
                                </div>
                                <div class="form-group w-full xl:!w-1/2">
                                    <div class="mb-1 text-sm">
                                        <label class="font-semibold" for="date_end">Date end</label>
                                        <div class="text-sm font-light"><p>Rate is available until this date.</p></div>
                                    </div>
                                    <div class="w-full">
                                        <input class="input-text" name="date_end" type="date" v-model="submit.date_end">
                                    </div>
                                    <div v-if="errors.date_end" class="w-full mt-2 text-sm text-red-400">
                                        {{ errors.date_end[0] }}
                                    </div>
                                </div>
                                <div class="form-group w-full xl:!w-1/3">
                                    <div class="mb-1 text-sm">
                                        <label class="font-semibold" for="min_days_before">Min days before booking</label>
                                        <div class="text-sm font-light"><p>Minimum days before check-in that this rate can be booked.</p></div>
                                    </div>
                                    <div class="w-full">
                                        <input class="input-text" name="min_days_before" type="number" v-model="submit.min_days_before">
                                    </div>
                                    <div v-if="errors.min_days_before" class="w-full mt-2 text-sm text-red-400">
                                        {{ errors.min_days_before[0] }}
                                    </div>
                                </div>
                                <div class="form-group w-full xl:!w-1/3">
                                    <div class="mb-1 text-sm">
                                        <label class="font-semibold" for="min_stay">Min stay</label>
                                        <div class="text-sm font-light"><p>Minimum number of nights for this rate.</p></div>
                                    </div>
                                    <div class="w-full">
                                        <input class="input-text" name="min_stay" type="number" v-model="submit.min_stay">
                                    </div>
                                    <div v-if="errors.min_stay" class="w-full mt-2 text-sm text-red-400">
                                        {{ errors.min_stay[0] }}
                                    </div>
                                </div>
                                <div class="form-group w-full xl:!w-1/3">
                                    <div class="mb-1 text-sm">
                                        <label class="font-semibold" for="max_stay">Max stay</label>
                                        <div class="text-sm font-light"><p>Maximum number of nights for this rate.</p></div>
                                    </div>
                                    <div class="w-full">
                                        <input class="input-text" name="max_stay" type="number" v-model="submit.max_stay">
                                    </div>
                                    <div v-if="errors.max_stay" class="w-full mt-2 text-sm text-red-400">
                                        {{ errors.max_stay[0] }}
                                    </div>
                                </div>

                                <!-- Settings -->
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
        }
    },

    computed: {
        method() {
            if (_.has(this.data, 'id')) {
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
            successMessage: 'Rate successfully saved',
            postUrl: '/cp/resrv/rate',
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

    components: [vSelect],

    watch: {
        data() {
            this.createSubmit()
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
            if (_.has(this.data, 'id')) {
                this.postUrl = '/cp/resrv/rate/' + this.data.id
            } else {
                this.postUrl = '/cp/resrv/rate'
            }
        },
        slugify() {
            this.submit.slug = this.$slugify(this.submit.title)
        }
    }
}
</script>
