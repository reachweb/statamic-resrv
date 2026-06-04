<template>
    <div>
        <div class="space-y-2" v-if="fixedPricingLoaded">
            <div
                v-for="pricing in fixedPricings"
                :key="pricing.id"
                class="w-full flex flex-wrap items-center justify-between p-3 rounded-lg border bg-white shadow-ui-sm dark:bg-gray-850 dark:border-gray-700/80"
            >
                <div class="flex items-center gap-2 cursor-pointer" v-if="pricing.days != 0" @click="editFixedPricing(pricing)">
                    <span class="text-sm text-gray-700 dark:text-gray-300">{{ __('Days:') }}</span>
                    <span class="font-medium text-gray-900 dark:text-gray-200" v-html="pricing.days"></span>
                    <span class="text-sm text-gray-700 dark:text-gray-300">{{ pricing.price }}</span>
                </div>
                <div class="flex items-center gap-2" v-else>
                    <span class="font-medium text-gray-900 dark:text-gray-200">{{ __('Extra day:') }}</span>
                    <span class="text-sm text-gray-700 dark:text-gray-300">{{ pricing.price }}</span>
                </div>
                <Dropdown>
                    <DropdownMenu>
                        <DropdownItem :text="__('Edit')" icon="pencil" @click="editFixedPricing(pricing)" />
                        <DropdownSeparator />
                        <DropdownItem :text="__('Delete')" icon="trash" variant="destructive" @click="confirmDelete(pricing)" />
                    </DropdownMenu>
                </Dropdown>
            </div>
        </div>
        <div class="flex flex-wrap gap-2 mt-3">
            <Button :text="__('Add fixed pricing')" variant="primary" icon="plus" @click="addFixedPricing" />
            <Button v-if="!hasExtraDayPricing" :text="__('Add extra day price')" variant="default" icon="plus" @click="addFixedExtraPricing" />
        </div>
        <FixedPricingPanel
            v-if="showPanel"
            :data="fixedpricing"
            @closed="togglePanel"
            @saved="fixedPricingSaved"
        />
        <confirmation-modal
            :open="deleteId !== null"
            :title="__('Delete')"
            :danger="true"
            @confirm="deleteFixedPricing"
            @cancel="deleteId = null"
        >
            {{ __('Are you sure you want to delete this item?') }} <strong>{{ __('This cannot be undone.') }}</strong>
        </confirmation-modal>
    </div>
</template>

<script setup>
import { Button, Dropdown, DropdownItem, DropdownMenu, DropdownSeparator } from '@statamic/cms/ui';
import { computed, onMounted, onUpdated, ref } from 'vue';
import axios from 'axios';
import FixedPricingPanel from './FixedPricingPanel.vue';
import { useToast } from '../composables/useToast.js';

const props = defineProps({
    parent: { type: String, required: true },
});

const emit = defineEmits(['input']);
const toast = useToast();

const showPanel = ref(false);
const fixedPricings = ref([]);
const fixedPricingLoaded = ref(false);
const deleteId = ref(null);
const fixedpricing = ref({});

const emptyFixedPricing = {
    days: '',
    price: '',
    statamic_id: props.parent,
};
const extraFixedPricing = {
    days: '0',
    price: '',
    statamic_id: props.parent,
};

const newItem = computed(() => props.parent === 'Collection');
const hasExtraDayPricing = computed(() =>
    fixedPricings.value.some((pricing) => pricing.days == '0'),
);

onMounted(() => getFixedPricing());

onUpdated(() => {
    if (!newItem.value) {
        emit('input', props.parent);
    }
});

function togglePanel() {
    showPanel.value = !showPanel.value;
}

function addFixedPricing() {
    fixedpricing.value = { ...emptyFixedPricing };
    togglePanel();
}

function addFixedExtraPricing() {
    fixedpricing.value = { ...extraFixedPricing };
    togglePanel();
}

function editFixedPricing(pricing) {
    fixedpricing.value = pricing;
    togglePanel();
}

function fixedPricingSaved() {
    togglePanel();
    getFixedPricing();
}

function getFixedPricing() {
    axios.get('/cp/resrv/fixedpricing/' + props.parent)
        .then((response) => {
            fixedPricings.value = response.data;
            fixedPricingLoaded.value = true;
        })
        .catch(() => {
            toast.error('Cannot retrieve fixed pricing data');
        });
}

function confirmDelete(item) {
    deleteId.value = item.id;
}

function deleteFixedPricing() {
    axios.delete('/cp/resrv/fixedpricing', { data: { id: deleteId.value } })
        .then(() => {
            toast.success('Fixed pricing deleted');
            deleteId.value = null;
            getFixedPricing();
        })
        .catch(() => {
            toast.error('Cannot delete fixed pricing');
        });
}
</script>
