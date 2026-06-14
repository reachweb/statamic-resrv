<template>
    <div>
        <Header :title="__('Surcharges')">
            <Button :text="__('Add a new surcharge')" variant="primary" icon="plus" @click="addSurcharge" />
        </Header>

        <Card inset>
            <div v-if="surcharges.length > 0" class="p-3 space-y-2">
                <div
                    v-for="item in surcharges"
                    :key="item.id"
                    class="w-full flex flex-wrap items-center justify-between p-3 rounded-lg border bg-white shadow-ui-sm dark:bg-gray-850 dark:border-gray-700/80"
                >
                    <div class="flex items-center gap-2">
                        <StatusIndicator :status="item.published ? 'published' : 'draft'" />
                        <span class="font-medium cursor-pointer text-gray-900 dark:text-gray-200 hover:underline" @click="editSurcharge(item)">{{ item.name }}</span>
                        <span class="text-sm text-gray-600 dark:text-gray-400 ml-2">
                            {{ optionName(item.first_option) }}
                            {{ item.comparison === 'matches' ? '=' : '≠' }}
                            {{ optionName(item.second_option) }}
                        </span>
                        <Badge :text="item.price" size="sm" />
                    </div>
                    <div>
                        <Dropdown>
                            <DropdownMenu>
                                <DropdownItem :text="__('Edit')" icon="pencil" @click="editSurcharge(item)" />
                                <DropdownSeparator />
                                <DropdownItem :text="__('Delete')" icon="trash" variant="destructive" @click="confirmDelete(item)" />
                            </DropdownMenu>
                        </Dropdown>
                    </div>
                </div>
            </div>
            <div v-else class="p-10 text-center text-gray-500 dark:text-gray-400">
                {{ __('No surcharges found.') }}
            </div>
        </Card>

        <SurchargesPanel
            v-if="showPanel"
            :data="surcharge"
            :options="options"
            @closed="togglePanel"
            @saved="togglePanel"
        />
        <confirmation-modal
            :open="deleteId !== null"
            :title="__('Delete surcharge')"
            :danger="true"
            @confirm="deleteSurcharge"
            @cancel="deleteId = null"
        >
            {{ __('Are you sure you want to delete this surcharge?') }} <strong>{{ __('This cannot be undone.') }}</strong>
        </confirmation-modal>
    </div>
</template>

<script setup>
import { Badge, Button, Card, Dropdown, DropdownItem, DropdownMenu, DropdownSeparator, Header, StatusIndicator } from '@statamic/cms/ui';
import { router } from '@statamic/cms/inertia';
import { ref } from 'vue';
import axios from 'axios';
import SurchargesPanel from './SurchargesPanel.vue';
import { useToast } from '../composables/useToast.js';

defineProps({
    surcharges: { type: Array, default: () => [] },
    options: { type: Array, default: () => [] },
});

const toast = useToast();

const showPanel = ref(false);
const deleteId = ref(null);
const surcharge = ref({});

const emptySurcharge = {
    name: '',
    first_option_id: null,
    second_option_id: null,
    comparison: 'differs',
    price: '',
    published: true,
};

function optionName(option) {
    return option ? option.name : __('Unknown');
}

function togglePanel() {
    showPanel.value = !showPanel.value;
}

function addSurcharge() {
    surcharge.value = { ...emptySurcharge };
    togglePanel();
}

function editSurcharge(item) {
    // Clone so the panel never mutates the Inertia-owned prop array element in place.
    surcharge.value = { ...item };
    togglePanel();
}

function refreshSurcharges() {
    router.reload({
        only: ['surcharges'],
        preserveState: true,
        preserveScroll: true,
    });
}

function confirmDelete(item) {
    deleteId.value = item.id;
}

function deleteSurcharge() {
    axios.delete(`/cp/resrv/surcharge/${deleteId.value}`)
        .then(() => {
            toast.success(__('Surcharge deleted'));
            deleteId.value = null;
            refreshSurcharges();
        })
        .catch(() => {
            toast.error(__('Cannot delete surcharge'));
        });
}
</script>
