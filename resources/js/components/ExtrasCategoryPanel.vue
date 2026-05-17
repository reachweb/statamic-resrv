<template>
    <Stack
        :open="true"
        :title="isEditing ? __('Edit category') : __('Add category')"
        icon="add-folder"
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
                    <Input v-model="submit.slug" @input="onSlugInput" />
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
import { Button, Card, Field, Input, Stack, Switch, Textarea } from '@statamic/cms/ui';
import { computed, onMounted, reactive, watch } from 'vue';
import { useFormHandler } from '../composables/useFormHandler.js';
import { useSlugify } from '../composables/useSlugify.js';

const props = defineProps({
    data: { type: Object, required: true },
    url: { type: String, default: '/cp/resrv/extra-category' },
});

const emit = defineEmits(['closed', 'saved']);
const { slugifyFrom, onSlugInput, reset: resetSlugify } = useSlugify();

const submit = reactive({});

const isEditing = computed(() => 'id' in props.data);
const method = computed(() => (isEditing.value ? 'patch' : 'post'));
const postUrl = computed(() =>
    isEditing.value ? `${props.url}/${props.data.id}` : props.url,
);

const { disableSave, errors, save } = useFormHandler({
    submit,
    postUrl,
    method,
    successMessage: 'Category successfully saved',
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
    resetSlugify(submit.slug);
}

function slugify() {
    const next = slugifyFrom(submit.name);
    if (next !== undefined) {
        submit.slug = next;
    }
}
</script>
