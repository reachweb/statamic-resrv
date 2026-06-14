<template>
    <Stack
        :open="true"
        :title="title"
        size="narrow"
        @closed="onClosed"
    >
        <template #header-actions>
            <Button :text="__('Save')" variant="primary" :disabled="form.processing" @click="save" />
        </template>
        <template #default>
            <Card>
                <div class="space-y-6">
                    <Field :label="__('Name')" :instructions="__('Shown to the customer as the line item label (e.g. One-way fee).')" :error="form.errors.name">
                        <Input v-model="form.name" />
                    </Field>
                    <Field :label="__('First option')" :instructions="__('The first single-select option to compare (e.g. Pickup location).')" :error="form.errors.first_option_id">
                        <Combobox
                            v-model="form.first_option_id"
                            :options="options"
                            option-label="name"
                            option-value="id"
                            :searchable="true"
                        />
                    </Field>
                    <Field :label="__('Second option')" :instructions="__('The second option to compare (e.g. Return location). Values are matched by name.')" :error="form.errors.second_option_id">
                        <Combobox
                            v-model="form.second_option_id"
                            :options="options"
                            option-label="name"
                            option-value="id"
                            :searchable="true"
                        />
                    </Field>
                    <Field :label="__('Apply the fee when')" :error="form.errors.comparison">
                        <Combobox
                            v-model="form.comparison"
                            :options="comparisonOptions"
                            option-label="label"
                            option-value="value"
                        />
                    </Field>
                    <Field :label="__('Fee')" :instructions="__('Flat amount added once per reservation when the condition is met.')" :error="form.errors.price">
                        <Input v-model="form.price" />
                    </Field>
                    <Field :label="__('Published')">
                        <Switch v-model="form.published" />
                    </Field>
                </div>
            </Card>
        </template>
    </Stack>
</template>

<script setup>
import { Button, Card, Combobox, Field, Input, Stack, Switch } from '@statamic/cms/ui';
import { useForm } from '@statamic/cms/inertia';
import { computed, onMounted, watch } from 'vue';
import { useToast } from '../composables/useToast.js';

const props = defineProps({
    data: { type: Object, required: true },
    options: { type: Array, default: () => [] },
});

const emit = defineEmits(['closed', 'saved']);
const toast = useToast();

const comparisonOptions = [
    { label: __('The two selections differ'), value: 'differs' },
    { label: __('The two selections match'), value: 'matches' },
];

const form = useForm({
    name: '',
    first_option_id: null,
    second_option_id: null,
    comparison: 'differs',
    price: '',
    published: true,
});

const isEditing = computed(() => 'id' in props.data && !!props.data.id);
const title = computed(() => (isEditing.value ? __('Edit surcharge') : __('Add a new surcharge')));

watch(() => props.data, hydrateForm, { deep: true });

onMounted(hydrateForm);

function hydrateForm() {
    const d = props.data;
    form.name = d.name ?? '';
    form.first_option_id = d.first_option_id ?? null;
    form.second_option_id = d.second_option_id ?? null;
    form.comparison = d.comparison ?? 'differs';
    form.price = d.price ?? '';
    form.published = d.published ?? true;
    form.clearErrors();
}

function onClosed() {
    form.clearErrors();
    emit('closed');
}

function save() {
    const url = isEditing.value
        ? '/cp/resrv/surcharge/' + props.data.id
        : '/cp/resrv/surcharge';
    const method = isEditing.value ? 'patch' : 'post';

    form[method](url, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            toast.success(__('Surcharge successfully saved'));
            emit('saved');
        },
        onError: (errors) => {
            if (!Object.keys(errors).length) {
                toast.error(__('Something went wrong. Please try again.'));
            }
        },
    });
}
</script>
