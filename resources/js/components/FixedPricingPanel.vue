<template>
    <Stack
        :open="true"
        :title="__('Fixed pricing')"
        icon="money-cashier-price-tag"
        size="narrow"
        @closed="onClosed"
    >
        <template #header-actions>
            <Button :text="__('Save')" variant="primary" :disabled="disableSave" @click="save" />
        </template>
        <template #default>
            <Card>
                <Field :label="__('Days')" :errors="errors.days">
                    <Input v-model="submit.days" />
                </Field>
                <Field :label="__('Price')" :errors="errors.price">
                    <Input v-model="submit.price" />
                </Field>
            </Card>
        </template>
    </Stack>
</template>

<script setup>
import { Button, Card, Field, Input, Stack } from '@statamic/cms/ui';
import { onMounted, reactive, watch } from 'vue';
import { useFormHandler } from '../composables/useFormHandler.js';

const props = defineProps({
    data: { type: Object, required: true },
    openPanel: { type: Boolean, default: false },
});

const emit = defineEmits(['closed', 'saved']);

const submit = reactive({});

const { disableSave, errors, save } = useFormHandler({
    submit,
    postUrl: '/cp/resrv/fixedpricing',
    method: 'post',
    successMessage: 'Fixed pricing successfully saved',
    emit,
});

watch(() => props.data, () => createSubmit(), { deep: true });

onMounted(() => createSubmit());

function onClosed() {
    Object.keys(submit).forEach((key) => delete submit[key]);
    emit('closed');
}

function createSubmit() {
    Object.keys(submit).forEach((key) => delete submit[key]);
    Object.entries(props.data).forEach(([name, value]) => {
        submit[name] = value;
    });
}
</script>
