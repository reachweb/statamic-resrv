<script setup>
import { computed } from 'vue';
import { Head } from '@statamic/cms/inertia';
import { Card, Icon } from '@statamic/cms/ui';

const props = defineProps({
    reservation: { type: Object, required: true },
    fields: { type: Object, default: () => ({}) },
    currencySymbol: { type: String, default: '' },
    maximumQuantity: { type: Number, default: 1 },
    backUrl: { type: String, required: true },
    refundUrl: { type: String, required: true },
});

const isParent = computed(() => props.reservation.type === 'parent');
const showQuantityColumn = computed(() => props.maximumQuantity > 1);

const fieldLabel = (handle) => props.fields[handle] ?? handle;
const statusLabel = computed(() => props.reservation.status?.toUpperCase() ?? '');
</script>

<template>
    <div class="max-w-page mx-auto">
        <Head :title="`${__('Reservation')} #${reservation.id}`" />

        <div class="flex">
            <a
                :href="backUrl"
                class="flex-initial flex p-2 -m-1 items-center text-xs text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-gray-100"
            >
                <Icon name="chevron-left" class="size-4" />
                <span>{{ __('Back') }}</span>
            </a>
        </div>

        <header class="mt-1 mb-6">
            <div class="flex flex-col md:flex-row md:items-center justify-between">
                <h1>
                    {{ __('Reservation') }} #{{ reservation.id }} - {{ reservation.entry?.title }}
                </h1>
                <div class="mt-1 md:mt-0 font-semibold">{{ reservation.created_at }}</div>
            </div>
        </header>

        <section>
            <div class="mb-2 content flex">
                <h2 class="text-base">{{ __('Reservation Details') }}</h2>
            </div>
            <Card class="px-6 py-4 mb-8 divide-y">
                <div class="grid grid-cols-3 my-2">
                    <div>
                        <div class="font-bold mb-2">{{ __('Reservation ID') }}</div>
                        <div># {{ reservation.id }}</div>
                    </div>
                    <div>
                        <div class="font-bold mb-2">{{ __('Reference') }}</div>
                        <div>{{ reservation.reference }}</div>
                    </div>
                    <div>
                        <div class="font-bold mb-2">{{ __('Status') }}</div>
                        <div>{{ statusLabel }}</div>
                    </div>
                </div>

                <div v-if="!isParent" class="grid grid-cols-2 my-2 pt-2">
                    <div>
                        <div class="font-bold mb-2">{{ __('Start date') }}</div>
                        <div>{{ reservation.date_start }}</div>
                    </div>
                    <div>
                        <div class="font-bold mb-2">{{ __('End date') }}</div>
                        <div>{{ reservation.date_end }}</div>
                    </div>
                </div>

                <div v-if="!isParent" class="grid grid-cols-2 my-2 pt-2">
                    <div v-if="showQuantityColumn">
                        <div class="font-bold mb-2">{{ __('Quantity') }}</div>
                        <div>x {{ reservation.quantity }}</div>
                    </div>
                    <div v-if="reservation.rate_id">
                        <div class="font-bold mb-2">{{ __('Rate') }}</div>
                        <div>{{ reservation.rate_label }}</div>
                    </div>
                </div>
            </Card>
        </section>

        <section v-if="isParent && reservation.childs.length">
            <div class="mb-2 content flex">
                <h2 class="text-base">{{ __('Related reservations') }}</h2>
            </div>
            <Card v-for="child in reservation.childs" :key="child.id" class="px-6 py-4 mb-6 divide-y">
                <div class="grid grid-cols-2 my-2">
                    <div>
                        <div class="font-bold mb-2">{{ __('Start date') }}</div>
                        <div>{{ child.date_start }}</div>
                    </div>
                    <div>
                        <div class="font-bold mb-2">{{ __('End date') }}</div>
                        <div>{{ child.date_end }}</div>
                    </div>
                </div>
                <div class="grid grid-cols-2 my-2 pt-2">
                    <div v-if="showQuantityColumn">
                        <div class="font-bold mb-2">{{ __('Quantity') }}</div>
                        <div>x {{ child.quantity }}</div>
                    </div>
                    <div v-if="child.rate_id">
                        <div class="font-bold mb-2">{{ __('Rate') }}</div>
                        <div>{{ child.rate_label }}</div>
                    </div>
                </div>
            </Card>
        </section>

        <section v-if="Object.keys(reservation.customer_data).length">
            <div class="mb-2 content">
                <h2 class="text-base">{{ __('Checkout data') }}</h2>
            </div>
            <Card class="px-6 py-4 mb-6">
                <div class="grid grid-cols-2 xl:grid-cols-3 mt-2">
                    <div
                        v-for="(value, handle) in reservation.customer_data"
                        :key="handle"
                        class="mb-2"
                    >
                        <div class="font-bold mb-2">{{ fieldLabel(handle) }}</div>
                        <div>{{ value }}</div>
                    </div>
                </div>
            </Card>
        </section>

        <section v-if="reservation.options.length">
            <div class="mb-2 content">
                <h2 class="text-base">{{ __('Options') }}</h2>
            </div>
            <Card class="px-6 py-4 mb-6">
                <div
                    v-for="option in reservation.options"
                    :key="option.id"
                    class="mb-2 border-b border-gray flex justify-between w-full p-2"
                >
                    <div>{{ option.name }}</div>
                    <div>{{ option.value_name }}</div>
                    <div v-if="option.price_formatted" class="font-bold">
                        {{ option.price_formatted }}
                        <span class="font-normal">
                            <template v-if="option.price_type === 'fixed'">
                                / {{ __('reservation') }}
                            </template>
                            <template v-if="option.price_type === 'perday'">
                                / {{ __('day') }}
                            </template>
                        </span>
                    </div>
                </div>
            </Card>
        </section>

        <section v-if="reservation.extras.length">
            <div class="mb-2 content">
                <h2 class="text-base">{{ __('Extras') }}</h2>
            </div>
            <Card class="px-6 py-4 mb-6">
                <div
                    v-for="extra in reservation.extras"
                    :key="extra.id"
                    class="mb-2 border-b border-gray flex justify-between w-full p-2"
                >
                    <div>{{ extra.name }} x{{ extra.quantity }}</div>
                    <div class="font-bold">
                        {{ currencySymbol }} {{ extra.price_formatted }}
                    </div>
                </div>
            </Card>
        </section>

        <section v-if="reservation.affiliate">
            <div class="mb-2 content">
                <h2 class="text-base">{{ __('Affiliate') }}</h2>
            </div>
            <Card class="px-6 py-4 mb-6">
                <div class="mb-2 border-b border-gray flex justify-between w-full p-2">
                    <div>{{ reservation.affiliate.name }}</div>
                    <div class="font-bold">{{ reservation.affiliate.email }}</div>
                </div>
                <div class="mb-2 border-b border-gray flex justify-between w-full p-2">
                    <div>{{ __('Fee at the time of reservation') }}</div>
                    <div class="font-bold">{{ reservation.affiliate.fee }}%</div>
                </div>
                <div class="mb-2 border-b border-gray flex justify-between w-full p-2">
                    <div>{{ __('Preliminary fee to be paid') }}</div>
                    <div class="font-bold">
                        <span v-if="reservation.affiliate.commission_cancelled" class="text-red-500">
                            {{ __('Commission cancelled') }}
                        </span>
                        <span v-else>
                            {{ currencySymbol }} {{ reservation.affiliate.fee_amount_formatted }}
                        </span>
                    </div>
                </div>
            </Card>
        </section>

        <section v-if="reservation.dynamic_pricings.length">
            <div class="mb-2 content">
                <h2 class="text-base">{{ __('Dynamic pricing policies applied') }}</h2>
            </div>
            <Card class="px-6 py-4 mb-6">
                <div
                    v-for="pricing in reservation.dynamic_pricings"
                    :key="pricing.id"
                    class="mb-2 border-b border-gray grid grid-cols-2 p-2"
                >
                    <div>
                        <div class="mb-1">{{ __('Title') }}</div>
                        <div class="font-bold">
                            <span class="mr-1 text-sm font-light">{{ pricing.order }}</span>
                            {{ pricing.title }}
                        </div>
                    </div>
                    <div>
                        <div class="mb-1">{{ __('Amount') }}</div>
                        <div class="font-bold">
                            {{ pricing.amount }}
                            <template v-if="pricing.amount_type === 'fixed'">{{ currencySymbol }}</template>
                            <template v-else-if="pricing.amount_type === 'percent'">%</template>
                            <span class="ml-3 text-sm font-light">{{ pricing.amount_operation }}</span>
                        </div>
                    </div>
                </div>
            </Card>
        </section>

        <section>
            <div class="mb-2 content">
                <h2 class="text-base">{{ __('Payment information') }}</h2>
            </div>
            <Card class="px-6 py-4 mb-6">
                <div v-if="reservation.payment_gateway" class="mb-2 border-b border-gray flex justify-between w-full p-2">
                    <div>{{ __('Payment method') }}</div>
                    <div class="font-bold">{{ reservation.payment_gateway_label }}</div>
                </div>
                <div class="mb-2 border-b border-gray flex justify-between w-full p-2">
                    <div>{{ __('Payment') }}</div>
                    <div class="font-bold">
                        {{ currencySymbol }} {{ reservation.payment_formatted }}
                    </div>
                </div>
                <template v-if="!reservation.payment_surcharge_is_zero">
                    <div class="mb-2 border-b border-gray flex justify-between w-full p-2">
                        <div>{{ __('Payment surcharge') }}</div>
                        <div class="font-bold">
                            {{ currencySymbol }} {{ reservation.payment_surcharge_formatted }}
                        </div>
                    </div>
                    <div class="mb-2 border-b border-gray flex justify-between w-full p-2">
                        <div>{{ __('Total charged') }}</div>
                        <div class="font-bold">
                            {{ currencySymbol }} {{ reservation.total_to_charge_formatted }}
                        </div>
                    </div>
                </template>
                <div class="mb-2 border-b border-gray flex justify-between w-full p-2">
                    <div>{{ __('Reservation price') }}</div>
                    <div class="font-bold">
                        {{ currencySymbol }} {{ reservation.price_formatted }}
                    </div>
                </div>
                <div class="mb-2 border-b border-gray flex justify-between w-full p-2">
                    <div class="font-bold text-xl">{{ __('Total price (including extras & options)') }}</div>
                    <div class="font-bold text-xl">
                        {{ currencySymbol }} {{ reservation.total_formatted }}
                    </div>
                </div>
            </Card>
        </section>
    </div>
</template>
