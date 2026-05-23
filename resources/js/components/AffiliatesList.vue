<template>
    <div>
        <Header :title="__('Affiliates')" icon="fieldtype-users">
            <Button :text="__('Add a new affiliate')" variant="primary" icon="plus" @click="addAffiliate" />
        </Header>

        <Card inset>
            <div v-if="affiliates.length > 0" class="p-3 space-y-2">
                <div
                    v-for="item in affiliates"
                    :key="item.id"
                    class="w-full flex flex-wrap items-center justify-between p-3 rounded-lg border bg-white shadow-ui-sm dark:bg-gray-850 dark:border-gray-700/80"
                >
                    <div class="flex items-center gap-2">
                        <StatusIndicator :status="item.published ? 'published' : 'draft'" />
                        <span class="font-medium cursor-pointer text-gray-900 dark:text-gray-200 hover:underline" v-html="item.name" @click="editAffiliate(item)"></span>
                        <span class="text-sm text-gray-600 dark:text-gray-400 ml-2">
                            {{ item.email }}
                        </span>
                        <Badge :text="`${__('Fee')}: ${item.fee}%`" size="sm" />
                    </div>
                    <div>
                        <Dropdown>
                            <DropdownMenu>
                                <DropdownItem :text="__('Edit')" icon="pencil" @click="editAffiliate(item)" />
                                <DropdownItem :text="__('Copy affiliate link')" icon="clipboard" @click="copyLink(item)" />
                                <DropdownSeparator />
                                <DropdownItem :text="__('Delete')" icon="trash" variant="destructive" @click="confirmDelete(item)" />
                            </DropdownMenu>
                        </Dropdown>
                    </div>
                </div>
            </div>
            <div v-else class="p-10 text-center text-gray-500 dark:text-gray-400">
                {{ __('No affiliates found.') }}
            </div>
        </Card>

        <AffiliatesPanel
            v-if="showPanel"
            :data="affiliate"
            @closed="togglePanel"
            @saved="togglePanel"
        />
        <confirmation-modal
            :open="deleteId !== null"
            :title="__('Delete affiliate')"
            :danger="true"
            @confirm="deleteAffiliate"
            @cancel="deleteId = null"
        >
            {{ __('Are you sure you want to delete this affiliate?') }} <strong>{{ __('This cannot be undone.') }}</strong>
        </confirmation-modal>
    </div>
</template>

<script setup>
import { Badge, Button, Card, Dropdown, DropdownItem, DropdownMenu, DropdownSeparator, Header, StatusIndicator } from '@statamic/cms/ui';
import { router } from '@statamic/cms/inertia';
import { ref } from 'vue';
import axios from 'axios';
import AffiliatesPanel from './AffiliatesPanel.vue';
import { useToast } from '../composables/useToast.js';

defineProps({
    affiliates: { type: Array, default: () => [] },
});

const toast = useToast();

const showPanel = ref(false);
const deleteId = ref(null);
const affiliate = ref({});

const emptyAffiliate = {
    name: '',
    code: '',
    email: '',
    cookie_duration: '',
    fee: '',
    allow_skipping_payment: false,
    send_reservation_email: false,
    published: true,
};

function togglePanel() {
    showPanel.value = !showPanel.value;
}

function addAffiliate() {
    affiliate.value = { ...emptyAffiliate };
    togglePanel();
}

function editAffiliate(item) {
    affiliate.value = item;
    togglePanel();
}

function copyLink(item) {
    const link = window.location.origin + '/?afid=' + item.code;
    if (navigator.clipboard) {
        navigator.clipboard.writeText(link);
        toast.success('Affiliate link copied to clipboard');
    } else {
        toast.error('Failed to copy link. Are you using SSL?');
    }
}

function refreshAffiliates() {
    router.reload({
        only: ['affiliates'],
        preserveState: true,
        preserveScroll: true,
    });
}

function confirmDelete(item) {
    deleteId.value = item.id;
}

function deleteAffiliate() {
    axios.delete(`/cp/resrv/affiliate/${deleteId.value}`)
        .then(() => {
            toast.success('Affiliate deleted');
            deleteId.value = null;
            refreshAffiliates();
        })
        .catch(() => {
            toast.error('Cannot delete affiliate');
        });
}
</script>
