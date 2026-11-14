<script setup>
import { computed } from 'vue';

const props = defineProps({
    facts: { type: Object, default: null },
    amountLabel: { type: String, default: null },
});

const customer = computed(() => {
    const { customerName, customerEmail } = props.facts ?? {};
    if (customerName && customerEmail) return `${customerName} (${customerEmail})`;
    return customerName || customerEmail || null;
});

const dates = computed(() => {
    const { dateStart, dateEnd } = props.facts ?? {};
    if (dateStart && dateEnd) return __(':start to :end', { start: dateStart, end: dateEnd });
    return dateStart || null;
});

const showTotal = computed(() => props.facts?.total && props.facts.total !== props.facts.amount);
</script>

<template>
    <dl v-if="facts" class="resrv-action-summary mb-4 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 p-3 text-sm">
        <dt class="text-gray-600 dark:text-gray-400">{{ __('Reservation') }}</dt>
        <dd class="font-medium break-words">#{{ facts.id }}<template v-if="facts.reference"> · {{ facts.reference }}</template></dd>

        <template v-if="customer">
            <dt class="text-gray-600 dark:text-gray-400">{{ __('Customer') }}</dt>
            <dd class="break-words">{{ customer }}</dd>
        </template>

        <template v-if="facts.entryTitle">
            <dt class="text-gray-600 dark:text-gray-400">{{ __('Item') }}</dt>
            <dd class="break-words">{{ facts.entryTitle }}</dd>
        </template>

        <template v-if="dates">
            <dt class="text-gray-600 dark:text-gray-400">{{ __('Dates') }}</dt>
            <dd>{{ dates }}</dd>
        </template>

        <template v-if="facts.amount">
            <dt class="text-gray-600 dark:text-gray-400">{{ amountLabel ?? __('Amount requested') }}</dt>
            <dd>
                <span class="whitespace-nowrap">{{ facts.amount }}</span>
                <span v-if="facts.surcharge" class="ms-1 text-gray-600 dark:text-gray-400">
                    {{ __('(+ :amount surcharge)', { amount: facts.surcharge }) }}
                </span>
            </dd>
        </template>

        <template v-if="showTotal">
            <dt class="text-gray-600 dark:text-gray-400">{{ __('Total') }}</dt>
            <dd class="whitespace-nowrap">{{ facts.total }}</dd>
        </template>

        <template v-if="facts.method">
            <dt class="text-gray-600 dark:text-gray-400">{{ __('Payment method') }}</dt>
            <dd class="break-words">{{ facts.method }}</dd>
        </template>
    </dl>
</template>
