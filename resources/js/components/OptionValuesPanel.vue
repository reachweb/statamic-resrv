<template>
    <Stack
        :open="true"
        :title="isEditing ? __('Edit option value') : __('Add an option value')"
        icon="add-item"
        size="narrow"
        @closed="onClosed"
    >
        <template #header-actions>
            <Button :text="__('Save')" variant="primary" :disabled="disableSave" @click="save" />
        </template>
        <template #default>
            <Card>
                <div class="space-y-6">
                    <Field :label="__('Name')" :errors="errors.name">
                        <Input v-model="submit.name" />
                    </Field>
                    <Field :label="__('Price type')" :errors="errors.price_type">
                        <Select
                            v-model="submit.price_type"
                            :options="priceTypeOptions"
                            @update:modelValue="togglePrice"
                        />
                    </Field>
                    <Field v-if="showPrice" :label="__('Price')" :errors="errors.price">
                        <Input v-model="submit.price" :input-attrs="{ inputmode: 'decimal' }" />
                    </Field>
                    <Field :label="__('Description (optional)')" :errors="errors.description">
                        <Textarea v-model="submit.description" />
                    </Field>
                    <Field :label="__('Published')">
                        <Switch v-model="submit.published" />
                    </Field>
                </div>
            </Card>
        </template>
    </Stack>
</template>

<script setup>
import { Button, Card, Field, Input, Select, Stack, Switch, Textarea } from '@statamic/cms/ui';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useFormHandler } from '../composables/useFormHandler.js';

const props = defineProps({
    data: { type: Object, required: true },
    openPanel: { type: Boolean, default: false },
    parent: { type: String, required: true },
});

const emit = defineEmits(['closed', 'saved']);

const submit = reactive({});
const showPrice = ref(false);

const priceTypeOptions = [
    { value: 'free', label: 'Free' },
    { value: 'perday', label: 'Per day' },
    { value: 'fixed', label: 'Fixed' },
];

const isEditing = computed(() => 'id' in props.data);
const method = computed(() => (isEditing.value ? 'patch' : 'post'));
const postUrl = computed(() => cp_url('resrv/option/' + props.parent));

const { disableSave, errors, save } = useFormHandler({
    submit,
    postUrl,
    method,
    successMessage: 'Option successfully saved',
    emit,
});

watch(() => props.data, () => createSubmit(), { deep: true });

onMounted(() => {
    createSubmit();
    if ('id' in props.data && props.data.price_type !== 'free') {
        showPrice.value = true;
    }
});

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

function togglePrice() {
    if (submit.price_type === 'free') {
        showPrice.value = false;
        submit.price = '0';
    } else {
        showPrice.value = true;
        // Only clear the '0' sentinel set when leaving 'free'; preserve a real price typed
        // for another priced type (a hydrated zero is formatted '0.00', not '0').
        if (submit.price === '0') {
            submit.price = '';
        }
    }
}
</script>
