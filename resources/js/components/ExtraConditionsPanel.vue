<template>
    <Stack
        :open="true"
        :title="__('Extra conditions for') + ': ' + data.name"
        icon="workflow"
        size="full"
        @closed="onClosed"
    >
        <template #header-actions>
            <Button :text="__('Save')" variant="primary" :disabled="disableSave" @click="save" />
        </template>
        <template #default>
            <Card>
                <ExtraConditionsForm
                    :data="conditionsSafe()"
                    :extras="extras"
                    :errors="errors"
                    @updated="createSubmit"
                />
            </Card>
        </template>
    </Stack>
</template>

<script setup>
import { Button, Card, Stack } from '@statamic/cms/ui';
import { computed, onMounted, ref, watch } from 'vue';
import axios from 'axios';
import ExtraConditionsForm from './ExtraConditionsForm.vue';
import { useFormHandler } from '../composables/useFormHandler.js';
import { useToast } from '../composables/useToast.js';

const props = defineProps({
    data: { type: Object, required: true },
    openPanel: { type: Boolean, default: false },
});

const emit = defineEmits(['closed', 'saved']);
const toast = useToast();

const submit = ref({ conditions: [] });
const extras = ref([]);

const postUrl = computed(() => cp_url('resrv/extra/conditions/' + props.data.id));

const { disableSave, errors, save } = useFormHandler({
    submit,
    postUrl,
    method: 'post',
    successMessage: 'Conditions successfully saved',
    emit,
});

disableSave.value = true;

// Enable-only: never re-disable on empty, so clearing all conditions stays saveable.
watch(submit, (value) => {
    if (value.conditions && value.conditions.length > 0) {
        disableSave.value = false;
    }
}, { deep: true });

onMounted(() => {
    createSubmit([]);
    getAllExtras();
});

function onClosed() {
    submit.value = { conditions: [] };
    emit('closed');
}

function createSubmit(conditionsForm) {
    submit.value = { conditions: conditionsForm };
}

function conditionsSafe() {
    if (props.data.conditions) {
        return props.data.conditions.conditions || [];
    }
    return [];
}

function getAllExtras() {
    axios.get(cp_url('resrv/extra'))
        .then((response) => {
            extras.value = response.data;
        })
        .catch(() => {
            toast.error(__('Cannot retrieve extras'));
        });
}
</script>
