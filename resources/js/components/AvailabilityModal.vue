<template>
    <div>
        <Modal :open="true" :title="__('Change availability')" icon="calendar-date" @dismissed="emit('cancel')">
            <div class="space-y-3 p-2">
                <div v-if="rate" class="text-sm text-gray-700 dark:text-gray-300">
                    <span class="text-gray-500">{{ __('For') }}:</span> {{ rate.label }}
                </div>
                <div class="text-sm text-gray-700 dark:text-gray-300">{{ __('From') }} {{ date_start }} {{ __('to') }} {{ date_end }}</div>

                <div class="grid grid-cols-2 gap-4 pt-2">
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

        <confirmation-modal
            v-if="deleteId"
            :title="__('Delete availability')"
            :danger="true"
            @confirm="deleteAvailability"
            @cancel="deleteId = null"
        >
            {{ __('Are you sure you want to clear availability date for those dates?') }}
        </confirmation-modal>
    </div>
</template>

<script setup>
import { Button, Field, Input, Modal } from '@statamic/cms/ui';
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
});

const emit = defineEmits(['cancel', 'saved']);
const toast = useToast();

const available = ref(null);
const price = ref(null);
const deleteId = ref(null);

const date_start = computed(() => dayjs(props.dates.start).format('YYYY-MM-DD'));
const date_end = computed(() => dayjs(props.dates.end).subtract(1, 'day').format('YYYY-MM-DD'));

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
            toast.success('Availability deleted');
            deleteId.value = null;
            emit('saved');
        })
        .catch(() => {
            toast.error('Cannot delete availability');
        });
}

function handleEnterKey(event) {
    if (event.key === 'Enter') {
        save();
    }
}
</script>
