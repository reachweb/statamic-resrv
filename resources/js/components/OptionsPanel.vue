<template>
    <Stack
        :open="true"
        :title="isEditing ? __('Edit option') : __('Add an option')"
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
                <Field :label="__('Description (optional)')" :errors="errors.description">
                    <Textarea v-model="submit.description" />
                </Field>
                <Field :label="__('Required')">
                    <Switch v-model="submit.required" />
                </Field>
                <Field :label="__('Published')">
                    <Switch v-model="submit.published" />
                </Field>
            </Card>
        </template>
    </Stack>
</template>

<script setup>
import { Button, Card, Field, Input, Stack, Switch, Textarea } from '@statamic/cms/ui';
import { computed, getCurrentInstance, onMounted, reactive, watch } from 'vue';
import { useFormHandler } from '../composables/useFormHandler.js';

const props = defineProps({
    data: { type: Object, required: true },
    openPanel: { type: Boolean, default: false },
});

const emit = defineEmits(['closed', 'saved']);
const { proxy } = getCurrentInstance();

const submit = reactive({});

const isEditing = computed(() => 'id' in props.data);
const method = computed(() => (isEditing.value ? 'patch' : 'post'));

const { disableSave, errors, save } = useFormHandler({
    submit,
    postUrl: '/cp/resrv/option',
    method,
    successMessage: 'Option successfully saved',
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
