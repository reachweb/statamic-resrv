<template>
    <stack narrow name="statamic-resrv-affiliates" @closed="close">
        <div slot-scope="{ close }" class="bg-gray-100 dark:bg-dark-700 h-full overflow-scroll overflow-x-auto flex flex-col">
            <header class="bg-white dark:bg-dark-550 pl-6 pr-3 py-2 mb-4 border-b dark:border-dark-950 shadow-md text-lg font-medium flex items-center justify-between">
                {{ title }}
                <button type="button" class="btn-close" @click="close">×</button>
            </header>
            <div class="flex-1 overflow-auto px-1">
                <div class="px-2">
                    <div class="publish-sections">
                        <div class="publish-sections-section">
                            <div class="card">
                                <div class="pb-3">
                                    <div class="mb-1 text-sm">
                                        <label class="font-semibold" for="name">{{ __('Name') }}</label>
                                    </div>
                                    <div class="w-full">
                                        <input class="input-text" name="name" type="text" v-model="submit.name">
                                    </div>
                                    <div v-if="errors.name" class="w-full mt-2 text-sm text-red-400">
                                        {{ errors.name[0] }}
                                    </div>  
                                </div>
                                <div class="pb-3">
                                    <div class="mb-1 text-sm">
                                        <label class="font-semibold" for="code">{{ __('Code') }}</label>
                                    </div>
                                    <div class="w-full">
                                        <input class="input-text" name="code" type="text" v-model="submit.code">
                                    </div>
                                    <div v-if="errors.code" class="w-full mt-2 text-sm text-red-400">
                                        {{ errors.code[0] }}
                                    </div>  
                                </div>
                                <div class="pb-3">
                                    <div class="mb-1 text-sm">
                                        <label class="font-semibold" for="email">{{ __('Email') }}</label>
                                    </div>
                                    <div class="w-full">
                                        <input class="input-text" name="email" type="text" v-model="submit.email">
                                    </div>
                                    <div v-if="errors.email" class="w-full mt-1 text-sm text-red-400">
                                        {{ errors.email[0] }}
                                    </div>  
                                </div>
                                <div class="pb-3">
                                    <div class="mb-1 text-sm">
                                        <label class="font-semibold" for="cookie_duration">{{ __('Cookie duration in days') }}</label>
                                    </div>
                                    <div class="w-full">
                                        <input class="input-text" name="cookie_duration" type="numeric" v-model="submit.cookie_duration">
                                    </div>
                                    <div v-if="errors.cookie_duration" class="w-full mt-2 text-sm text-red-400">
                                        {{ errors.cookie_duration[0] }}
                                    </div>  
                                </div>
                                <div class="pb-3">
                                    <div class="mb-1 text-sm">
                                        <label class="font-semibold" for="fee">{{ __('Fee') }}</label>
                                    </div>
                                    <div class="w-full">
                                        <input class="input-text" name="fee" type="text" v-model="submit.fee">
                                    </div>
                                    <div v-if="errors.fee" class="w-full mt-2 text-sm text-red-400">
                                        {{ errors.fee[0] }}
                                    </div>
                                </div>
                                <div class="pb-3">
                                    <div class="flex items-center justify-between mb-1">
                                        <div class="mr-2">
                                            <label class="font-semibold" for="coupons">{{ __('Coupons') }}</label>
                                            <div class="text-sm font-light"><p>{{ __('Select any coupons that would make a reservation credited to this affiliate.') }}</p></div>
                                        </div>
                                        <div class="flex justify-end cursor-pointer mt-2">
                                            <span class="text-xs text-gray-700" @click="clearAllCoupons()">{{ __('Clear') }}</span>
                                        </div>
                                    </div>
                                    <div class="w-full">
                                        <v-select
                                            v-model="submit.coupons"
                                            label="title"
                                            multiple="multiple"
                                            :close-on-select="false"
                                            :deselectFromDropdown="true"
                                            :options="couponsWithCouponCode"
                                            :searchable="true"
                                            :reduce="type => type.id"
                                        >
                                            <template #selected-option-container><i class="hidden"></i></template>
                                            <template #footer="{ deselect }" v-if="couponsLoaded">
                                                <div class="vs__selected-options-outside flex flex-wrap">
                                                    <span v-for="id in submit.coupons" :key="id" class="vs__selected mt-1">
                                                        {{ getCouponTitle(id) }}
                                                        <button
                                                            @click="() => submit.coupons = submit.coupons.filter(coupon => coupon !== id)"
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
                                    <div v-if="errors.coupons" class="w-full mt-2 text-sm text-red-400">
                                        {{ errors.coupons[0] }}
                                    </div>
                                </div>
                                <div class="pb-3 flex items-center">
                                    <toggle-input v-model="submit.allow_skipping_payment"></toggle-input>
                                    <div class="text-sm ml-3">{{ __('Allow skipping payment') }}</div>
                                </div>
                                <div class="pb-3 flex items-center">
                                    <toggle-input v-model="submit.send_reservation_email"></toggle-input>
                                    <div class="text-sm ml-3">{{ __('Send reservation email') }}</div>
                                </div>
                                <div class="pb-3 flex items-center">
                                    <toggle-input v-model="submit.published"></toggle-input>
                                    <div class="text-sm ml-3">{{ __('Published') }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="bg-gray-200 dark:bg-dark-500 p-4 border-t dark:border-dark-900 flex items-center justify-between">
                <div class="w-full">
                    <button 
                        class="btn-primary w-full"
                        :disabled="disableSave"
                        @click="save"
                    >
                    Save
                    </button>
                </div>
            </div>
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
        openPanel: {
            type: Boolean,
            default: false
        },
    },

    computed: {
        method() {
            if (_.has(this.data, 'id')) {
                return 'patch'
            }
            return 'post'
        },
        title() {
            if (_.has(this.data, 'id')) {
                return 'Edit affiliate'
            }
            return 'Add a new affiliate'
        },
        couponsWithCouponCode() {
            return this.coupons.filter(coupon => coupon.coupon != null && coupon.coupon !== '')
        }
    },

    watch: {
        data() {
            this.createSubmit()
        },
    },

    mounted() {
        this.createSubmit()
    },

    created() {
        this.getCoupons()
    },

    data() {
        return {
            submit: {},
            successMessage: 'Affiliate successfully saved',
            postUrl: '/cp/resrv/affiliate',
            coupons: [],
            couponsLoaded: false,
        }
    },

    mixins: [FormHandler],

    components: [vSelect],

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
            // Initialize coupons array if it doesn't exist
            if (!this.submit.coupons) {
                this.$set(this.submit, 'coupons', this.data.coupons_ids || [])
            }
            if (_.has(this.data, 'id')) {
                this.postUrl = '/cp/resrv/affiliate/' + this.data.id
            } else {
                this.postUrl = '/cp/resrv/affiliate'
            }
        },
        getCoupons() {
            axios.get('/cp/resrv/dynamicpricing/index')
            .then(response => {
                this.coupons = response.data
                this.couponsLoaded = true
            })
            .catch(error => {
                this.$toast.error('Cannot retrieve the coupons')
            })
        },
        clearAllCoupons() {
            this.submit.coupons = []
        },
        getCouponTitle(id) {
            const coupon = this.coupons.find(item => item.id == id)
            return coupon ? `${coupon.title} (${coupon.coupon})` : ''
        }
    }
}
</script>
