<template>
    <div>
        <Header :title="__('Rates')" icon="money-cashier-price-tag">
            <Button v-if="selectedCollection" :text="__('Add rate')" variant="primary" icon="add" @click="add" />
        </Header>

        <Card class="mb-6">
            <Field :label="__('Collection')" :instructions="__('Choose a collection to manage rates for.')">
                <Select
                    v-model="selectedCollection"
                    :options="collectionOptions"
                    :clearable="false"
                    :placeholder="__('Select a collection...')"
                    @update:modelValue="collectionChanged"
                />
            </Field>
        </Card>

        <Card v-if="dataLoaded && selectedCollection" inset>
            <div v-if="rates.length > 0" class="p-3">
                <draggable
                    class="space-y-2"
                    v-model="rates"
                    item-key="id"
                    @start="drag = true"
                    @end="drag = false"
                    @change="order"
                >
                    <template #item="{ element: rate }">
                        <div class="w-full flex flex-wrap items-center justify-between p-3 rounded-lg border bg-white shadow-ui-sm dark:bg-gray-850 dark:border-gray-700/80 cursor-move">
                            <div class="flex items-center gap-2">
                                <StatusIndicator :status="rate.published ? 'published' : 'draft'" />
                                <span class="font-medium cursor-pointer text-gray-900 dark:text-gray-200 hover:underline" v-html="rate.title" @click="edit(rate)"></span>
                                <Badge v-if="rate.apply_to_all" :text="__('All entries')" size="sm" />
                            </div>
                            <div class="flex items-center gap-2">
                                <Badge v-if="rate.pricing_type === 'relative'" :text="__('Relative')" size="sm" variant="info" />
                                <Badge v-if="rate.availability_type === 'shared'" :text="__('Shared')" size="sm" variant="info" />
                                <Dropdown>
                                    <DropdownMenu>
                                        <DropdownItem :text="__('Edit')" icon="pencil" @click="edit(rate)" />
                                        <DropdownSeparator />
                                        <DropdownItem :text="__('Delete')" icon="trash" variant="destructive" @click="confirmDelete(rate)" />
                                    </DropdownMenu>
                                </Dropdown>
                            </div>
                        </div>
                    </template>
                </draggable>
            </div>
            <div v-else class="p-10 text-center text-gray-500 dark:text-gray-400">
                {{ __('No rates found for this collection. Add one to get started.') }}
            </div>
        </Card>

        <RatePanel
            v-if="showPanel"
            :data="rate"
            :all-rates="rates"
            :collections="collections"
            :selected-collection="selectedCollection"
            @closed="togglePanel"
            @saved="dataSaved"
        />
        <confirmation-modal
            v-if="deleteId"
            :title="__('Delete rate')"
            :danger="true"
            @confirm="deleteRate"
            @cancel="deleteId = null"
        >
            {{ __('Are you sure you want to delete this rate?') }} <strong>{{ __('This cannot be undone.') }}</strong>
        </confirmation-modal>
    </div>
</template>

<script setup>
import { Badge, Button, Card, Dropdown, DropdownItem, DropdownMenu, DropdownSeparator, Field, Header, Select, StatusIndicator } from '@statamic/cms/ui';
import draggable from 'vuedraggable';
import { computed, onMounted, ref } from 'vue';
import axios from 'axios';
import RatePanel from './RatePanel.vue';
import { useToast } from '../composables/useToast.js';

const toast = useToast();

const showPanel = ref(false);
const rates = ref([]);
const collections = ref([]);
const selectedCollection = ref(null);
const dataLoaded = ref(false);
const deleteId = ref(null);
const drag = ref(false);
const rate = ref({});

const collectionOptions = computed(() =>
    collections.value.map((c) => ({ value: c.handle, label: c.title })),
);

onMounted(() => getCollections());

function togglePanel() {
    showPanel.value = !showPanel.value;
}

function add() {
    rate.value = {
        collection: selectedCollection.value,
        apply_to_all: true,
        entries: [],
        title: '',
        slug: '',
        description: '',
        pricing_type: 'independent',
        base_rate_id: null,
        modifier_type: null,
        modifier_operation: null,
        modifier_amount: null,
        availability_type: 'independent',
        require_price_override: false,
        max_available: null,
        date_start: null,
        date_end: null,
        min_days_before: null,
        max_days_before: null,
        min_stay: null,
        max_stay: null,
        refundable: true,
        published: true,
    };
    togglePanel();
}

function edit(item) {
    rate.value = item;
    togglePanel();
}

function dataSaved() {
    togglePanel();
    getRates();
}

function getCollections() {
    axios.get('/cp/resrv/rates/collections')
        .then((response) => {
            collections.value = response.data;
            if (collections.value.length > 0) {
                selectedCollection.value = collections.value[0].handle;
                getRates();
            }
        })
        .catch(() => {
            toast.error('Cannot retrieve collections');
        });
}

function collectionChanged() {
    if (selectedCollection.value) {
        getRates();
    }
}

function getRates() {
    axios.get('/cp/resrv/rates/index', { params: { collection: selectedCollection.value } })
        .then((response) => {
            rates.value = response.data;
            dataLoaded.value = true;
        })
        .catch(() => {
            toast.error('Cannot retrieve rates');
        });
}

function confirmDelete(item) {
    deleteId.value = item.id;
}

function deleteRate() {
    axios.delete('/cp/resrv/rate/' + deleteId.value)
        .then(() => {
            toast.success('Rate deleted');
            deleteId.value = null;
            getRates();
        })
        .catch((error) => {
            if (error.response && error.response.status === 422) {
                toast.error(error.response.data.message);
            } else {
                toast.error('Cannot delete rate');
            }
            deleteId.value = null;
        });
}

function order() {
    const orderData = rates.value.map((r, index) => ({ id: r.id, order: index + 1 }));
    axios.post('/cp/resrv/rate/order', orderData)
        .then(() => {
            toast.success('Rates order changed');
            getRates();
        })
        .catch(() => {
            toast.error('Rates ordering failed');
        });
}
</script>
