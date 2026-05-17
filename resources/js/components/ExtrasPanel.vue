<template>
    <Stack
        :open="true"
        :title="isEditing ? __('Edit extra') : __('Add an extra')"
        icon="add-item"
        size="narrow"
        @closed="onClosed"
    >
        <template #header-actions>
            <Button :text="__('Save')" variant="primary" :disabled="disableSave" @click="save" />
        </template>
        <template #default>
            <Card>
                <Field :label="__('Name')" :errors="errors.name">
                    <Input v-model="submit.name" @input="slugify" />
                </Field>
                <Field :label="__('Slug')" :errors="errors.slug">
                    <Input v-model="submit.slug" />
                </Field>
                <Field :label="__('Price')" :errors="errors.price">
                    <Input v-model="submit.price" />
                </Field>
                <Field :label="__('Price type')" :errors="errors.price_type">
                    <Select v-model="submit.price_type" :options="priceTypeOptions" />
                </Field>
                <Field v-if="submit.price_type === 'custom'" :label="__('Custom field')" :errors="errors.custom">
                    <Input v-model="submit.custom" />
                </Field>
                <Field :label="__('Override label')" :errors="errors.override_label">
                    <Input v-model="submit.override_label" />
                </Field>
                <Field :label="__('Can add more than 1')">
                    <Switch v-model="submit.allow_multiple" />
                </Field>
                <Field v-if="submit.allow_multiple" :label="__('Maximum number for 1 reservation')" :errors="errors.maximum">
                    <Input v-model="submit.maximum" />
                </Field>
                <Field :label="__('Description')" :errors="errors.description">
                    <Textarea v-model="submit.description" />
                </Field>
                <Field :label="__('Published')">
                    <Switch v-model="submit.published" />
                </Field>
            </Card>
        </template>
    </Stack>
</template>

<script setup>
import { Button, Card, Field, Input, Select, Stack, Switch, Textarea } from '@statamic/cms/ui';
import { computed, getCurrentInstance, onMounted, reactive, watch } from 'vue';
import { useFormHandler } from '../composables/useFormHandler.js';

const props = defineProps({
    data: { type: Object, required: true },
    url: { type: String, default: '/cp/resrv/extra' },
});

const emit = defineEmits(['closed', 'saved']);
const { proxy } = getCurrentInstance();

const submit = reactive({});

const priceTypeOptions = [
    { value: 'perday', label: 'Per day' },
    { value: 'fixed', label: 'Fixed' },
    { value: 'relative', label: 'Relative to the reservation price' },
    { value: 'custom', label: 'Relative to a checkout form item' },
];

const isEditing = computed(() => 'id' in props.data);
const method = computed(() => (isEditing.value ? 'patch' : 'post'));

const { disableSave, errors, save } = useFormHandler({
    submit,
    postUrl: '/cp/resrv/extra',
    method,
    successMessage: 'Extra successfully saved',
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

function slugify() {
    submit.slug = proxy.$slug(submit.name);
}
</script>
