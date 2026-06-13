<template>
    <div>
        <Modal :open="true" :title="__('Change availability')" icon="calendar-date" @dismissed="emit('cancel')">
            <div class="space-y-6 p-2">
                <div v-if="rate" class="text-sm text-gray-700 dark:text-gray-300">
                    <span class="text-gray-500">{{ __('For') }}:</span> {{ rate.label }}
                </div>
                <div class="text-sm text-gray-700 dark:text-gray-300">{{ __('From') }} {{ date_start }} {{ __('to') }} {{ date_end }}</div>

                <Alert v-if="canClearStuckPending" variant="warning">
                    <h5>{{ __('Stuck holds detected') }}</h5>
                    <div>{{ stuckHoldsMessage }}</div>
                    <Button class="mt-2" size="sm" variant="default" :text="__('Clear stuck holds')" :disabled="clearingPending" @click="clearStuckHolds(false)" />
                </Alert>

                <div class="grid grid-cols-2 gap-x-4 gap-y-6">
                    <Field :label="__('Available')" :errors="errors.available">
                        <Input v-model="available" @keyup="handleEnterKey" />
                    </Field>
                    <Field :label="__('Price')" :errors="errors.price">
                        <Input v-model="price" @keyup="handleEnterKey" />
                    </Field>
                </div>
            </div>

            <template #footer>
                <div class="flex items-center justify-between gap-2 p-3 border-t border-gray-200 dark:border-gray-700">
                    <Button :text="__('Delete')" variant="danger" @click="confirmDelete" />
                    <div class="flex items-center gap-2">
                        <Button :text="__('Cancel')" variant="ghost" @click="emit('cancel')" />
                        <Button :text="__('Save')" variant="primary" :disabled="disableSave" @click="save" />
                    </div>
                </div>
            </template>
        </Modal>

        <ConfirmationModal
            :open="!!deleteId"
            :title="__('Delete availability')"
            :danger="true"
            @confirm="deleteAvailability"
            @cancel="deleteId = null"
        >
            {{ __('Are you sure you want to clear availability date for those dates?') }}
        </ConfirmationModal>

        <ConfirmationModal
            :open="stillActiveIds.length > 0"
            :title="__('Force clear active holds?')"
            :danger="true"
            @confirm="clearStuckHolds(true)"
            @cancel="stillActiveIds = []"
        >
            {{ __('The following reservations are still active and will be removed from the pending list anyway: :ids. This can desynchronise inventory accounting — proceed only if those reservations have already been resolved out-of-band.', { ids: stillActiveIds.join(', ') }) }}
        </ConfirmationModal>
    </div>
</template>

<script setup>
import { Alert, Button, ConfirmationModal, Field, Input, Modal } from '@statamic/cms/ui';
import { computed, ref } from 'vue';
import axios from 'axios';
import dayjs from 'dayjs';
import { useFormHandler } from '../composables/useFormHandler.js';
import { useToast } from '../composables/useToast.js';

const props = defineProps({
    dates: {
        type: Object,
        required: true,
    },
    parentId: {
        type: String,
        required: true,
    },
    rate: {
        type: Object,
        required: false,
    },
    pendingByDate: {
        type: Object,
        default: () => ({}),
    },
});

const emit = defineEmits(['cancel', 'saved']);
const toast = useToast();

const available = ref(null);
const price = ref(null);
const deleteId = ref(null);
const stillActiveIds = ref([]);
const clearingPending = ref(false);

const date_start = computed(() => dayjs(props.dates.start).format('YYYY-MM-DD'));
const date_end = computed(() => dayjs(props.dates.end).subtract(1, 'day').format('YYYY-MM-DD'));

const datesWithPending = computed(() => {
    return Object.entries(props.pendingByDate)
        .filter(([, ids]) => Array.isArray(ids) && ids.length > 0)
        .map(([date]) => date);
});

const totalHoldsCount = computed(() => {
    return Object.values(props.pendingByDate)
        .reduce((sum, ids) => sum + (Array.isArray(ids) ? ids.length : 0), 0);
});

const canClearStuckPending = computed(() => datesWithPending.value.length > 0 && props.rate);

const stuckHoldsMessage = computed(() => {
    const holds = totalHoldsCount.value;
    const days = datesWithPending.value.length;
    if (days === 1) {
        return __(':count reservation hold(s) are recorded on this date. Inventory edits/deletes are blocked while holds exist.', { count: holds });
    }
    return __(':count reservation hold(s) across :days date(s) in this range. Inventory edits/deletes are blocked while holds exist.', { count: holds, days });
});

const submit = computed(() => {
    const fields = {
        date_start: date_start.value,
        date_end: date_end.value,
        statamic_id: props.parentId,
        price: price.value,
        available: available.value,
    };
    if (props.rate) {
        fields.rate_ids = [props.rate.code];
    }
    return fields;
});

const { disableSave, errors, save } = useFormHandler({
    submit,
    postUrl: '/cp/resrv/availability',
    method: 'post',
    successMessage: 'Availability successfully saved',
    emit,
});

function confirmDelete() {
    deleteId.value = props.parentId;
}

function deleteAvailability() {
    const deleteData = {
        statamic_id: deleteId.value,
        date_start: date_start.value,
        date_end: date_end.value,
    };
    if (props.rate) {
        deleteData.rate_ids = [props.rate.code];
    }
    axios.delete('/cp/resrv/availability', { data: deleteData })
        .then(() => {
            toast.success(__('Availability deleted'));
            deleteId.value = null;
            emit('saved');
        })
        .catch(() => {
            toast.error(__('Cannot delete availability'));
        });
}

function handleEnterKey(event) {
    if (event.key === 'Enter') {
        save();
    }
}

async function clearStuckHolds(force) {
    if (clearingPending.value) return;
    clearingPending.value = true;
    let totalCleared = 0;
    let failedCount = 0;
    const stillActive = new Set();

    try {
        // allSettled (not all) so one failed date doesn't discard the holds other dates cleared —
        // this is the recovery path, partial progress must be kept and reported.
        const results = await Promise.allSettled(
            datesWithPending.value.map((date) => axios.post('/cp/resrv/availability/clear-stuck-pending', {
                statamic_id: props.parentId,
                date,
                rate_id: props.rate.code,
                force: !!force,
            }))
        );

        for (const result of results) {
            if (result.status === 'rejected') {
                failedCount++;
                console.error(result.reason);
                continue;
            }
            const data = result.value.data || {};
            totalCleared += data.cleared || 0;
            if (!force && Array.isArray(data.still_active)) {
                data.still_active.forEach((id) => stillActive.add(id));
            }
        }

        if (force) {
            stillActiveIds.value = [];
        } else if (stillActive.size > 0) {
            stillActiveIds.value = [...stillActive];
        }

        const waitingForConfirmation = !force && stillActive.size > 0;

        if (failedCount > 0) {
            toast.error(`${totalCleared} hold(s) cleared, ${failedCount} date(s) failed`);
        } else if (waitingForConfirmation) {
            if (totalCleared > 0) {
                toast.success(`${totalCleared} stuck hold(s) cleared. Active holds need confirmation.`);
            }
        } else {
            toast.success(`${totalCleared} hold(s) cleared`);
        }

        // emit('saved') closes + refreshes the parent modal, so hold it back while we still need
        // the user to confirm active holds; otherwise refresh whenever anything actually cleared.
        if (!waitingForConfirmation && (failedCount === 0 || totalCleared > 0)) {
            emit('saved');
        }
    } catch (e) {
        console.error(e);
        toast.error(__('Failed to clear stuck holds'));
    } finally {
        clearingPending.value = false;
    }
}
</script>
