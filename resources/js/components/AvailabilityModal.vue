<template>
    <div>
        <Modal :open="true" :title="__('Change availability')" icon="calendar-date" @dismissed="emit('cancel')">
            <div class="space-y-6 p-2">
                <div v-if="rate" class="space-y-2">
                    <div class="flex flex-wrap items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                        <span class="text-gray-500">{{ __('For') }}:</span>
                        <span class="font-medium">{{ rate.label }}</span>
                        <Badge v-for="tag in editability.tags" :key="tag" :text="tag" size="sm" variant="info" />
                    </div>
                    <Alert v-if="editability.notice" variant="default" :text="editability.notice" />
                </div>
                <div class="text-sm text-gray-700 dark:text-gray-300">{{ __('From') }} {{ date_start }} {{ __('to') }} {{ date_end }}</div>

                <Alert v-if="canClearStuckPending" variant="warning">
                    <h5>{{ __('Stuck holds detected') }}</h5>
                    <div>{{ stuckHoldsMessage }}</div>
                    <Button class="mt-2" size="sm" variant="default" :text="__('Clear stuck holds')" :disabled="clearingPending" @click="clearStuckHolds(false)" />
                </Alert>

                <div class="grid grid-cols-2 gap-x-4 gap-y-6">
                    <Field :label="__('Available')" :errors="errors.available" :instructions="editability.availabilityReason">
                        <Input v-model="available" :disabled="!editability.availability" @keyup="handleEnterKey" />
                    </Field>
                    <Field :label="__('Price')" :errors="errors.price" :instructions="editability.priceReason">
                        <Input v-model="price" :disabled="!editability.price" @keyup="handleEnterKey" />
                    </Field>
                </div>
            </div>

            <template #footer>
                <div class="flex items-center justify-between gap-2 p-3 border-t border-gray-200 dark:border-gray-700">
                    <Button :text="__('Delete')" variant="danger" @click="confirmDelete" />
                    <div class="flex items-center gap-2">
                        <Button :text="__('Cancel')" variant="ghost" @click="emit('cancel')" />
                        <Button :text="__('Save')" variant="primary" :disabled="saveDisabled" @click="save" />
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
            :open="expirableIds.length > 0"
            :title="__('Expire abandoned checkouts?')"
            :danger="true"
            @confirm="clearStuckHolds(true)"
            @cancel="expirableIds = []"
        >
            {{ __('These reservations look like abandoned checkouts — still pending but past their hold window: :ids. Continuing will expire them and release their inventory. Confirmed bookings and checkouts still within their hold window are never touched.', { ids: expirableIds.join(', ') }) }}
        </ConfirmationModal>
    </div>
</template>

<script setup>
import { Alert, Badge, Button, ConfirmationModal, Field, Input, Modal } from '@statamic/cms/ui';
import { computed, ref } from 'vue';
import axios from 'axios';
import dayjs from 'dayjs';
import { useFormHandler } from '../composables/useFormHandler.js';
import { useToast } from '../composables/useToast.js';
import { rateEditability } from '../composables/useRateEditability.js';

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
    stuckByDate: {
        type: Object,
        default: () => ({}),
    },
});

const emit = defineEmits(['cancel', 'saved']);
const toast = useToast();

const editability = computed(() => rateEditability(props.rate));

const available = ref(null);
const price = ref(null);
const deleteId = ref(null);
const expirableIds = ref([]);
const clearingPending = ref(false);

const date_start = computed(() => dayjs(props.dates.start).format('YYYY-MM-DD'));
const date_end = computed(() => dayjs(props.dates.end).subtract(1, 'day').format('YYYY-MM-DD'));

// Holds backed by confirmed bookings or in-progress checkouts are the normal state of a
// booked date — only genuinely stuck holds (classified by the backend) surface here.
const datesWithStuck = computed(() => {
    return Object.entries(props.stuckByDate)
        .filter(([, count]) => count > 0)
        .map(([date]) => date);
});

const totalStuckCount = computed(() => {
    return Object.values(props.stuckByDate)
        .reduce((sum, count) => sum + (count || 0), 0);
});

const canClearStuckPending = computed(() => datesWithStuck.value.length > 0 && props.rate);

const stuckHoldsMessage = computed(() => {
    const holds = totalStuckCount.value;
    const days = datesWithStuck.value.length;
    if (days === 1) {
        return __(':count hold(s) on this date look stuck — their reservations are missing, already finished, or abandoned past the hold window. Clearing them releases the inventory they still hold.', { count: holds });
    }
    return __(':count stuck hold(s) across :days date(s) in this range — their reservations are missing, already finished, or abandoned past the hold window. Clearing them releases the inventory they still hold.', { count: holds, days });
});

const submit = computed(() => {
    // Never send a locked field — the backend would reject (shared+relative price) or ignore it.
    const fields = {
        date_start: date_start.value,
        date_end: date_end.value,
        statamic_id: props.parentId,
        price: editability.value.price ? price.value : null,
        available: editability.value.availability ? available.value : null,
    };
    if (props.rate) {
        fields.rate_ids = [props.rate.code];
    }
    return fields;
});

const { disableSave, errors, save } = useFormHandler({
    submit,
    postUrl: cp_url('resrv/availability'),
    method: 'post',
    successMessage: 'Availability successfully saved',
    emit,
});

// Both fields locked (a rate that mirrors its base) leaves nothing to submit — Save would post
// two nulls and trip the backend's required_without validation. Block it; Delete stays available.
const saveDisabled = computed(() => disableSave.value || (!editability.value.price && !editability.value.availability));

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
    axios.delete(cp_url('resrv/availability'), { data: deleteData })
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
    const expirable = new Set();

    try {
        // allSettled (not all) so one failed date doesn't discard the holds other dates cleared —
        // this is the recovery path, partial progress must be kept and reported.
        const results = await Promise.allSettled(
            datesWithStuck.value.map((date) => axios.post(cp_url('resrv/availability/clear-stuck-pending'), {
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
            if (Array.isArray(data.still_active)) {
                data.still_active.forEach((id) => stillActive.add(id));
            }
            if (Array.isArray(data.expirable)) {
                data.expirable.forEach((id) => expirable.add(id));
            }
        }

        // Only expirable holds (abandoned checkouts past their hold window) warrant the force
        // confirmation — asking about holds that belong to confirmed bookings or in-window
        // checkouts would offer an action that does nothing. Force closes the dialog.
        const waitingForConfirmation = !force && expirable.size > 0;
        expirableIds.value = waitingForConfirmation ? [...expirable] : [];

        if (failedCount > 0) {
            toast.error(`${totalCleared} hold(s) cleared, ${failedCount} date(s) failed`);
        } else if (waitingForConfirmation) {
            if (totalCleared > 0) {
                toast.success(`${totalCleared} stuck hold(s) cleared. Abandoned checkouts need confirmation.`);
            }
        } else if (stillActive.size > 0) {
            toast.success(`${totalCleared} hold(s) cleared. ${stillActive.size} hold(s) belong to active reservations and were left in place.`);
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
