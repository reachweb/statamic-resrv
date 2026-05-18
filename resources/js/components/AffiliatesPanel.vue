<template>
    <Stack
        :open="true"
        :title="title"
        icon="fieldtype-users"
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
                    <Field :label="__('Code')" :errors="errors.code">
                        <Input v-model="submit.code" />
                    </Field>
                    <Field :label="__('Email')" :errors="errors.email">
                        <Input v-model="submit.email" type="email" />
                    </Field>
                    <Field :label="__('Cookie duration in days')" :errors="errors.cookie_duration">
                        <Input v-model="submit.cookie_duration" type="number" />
                    </Field>
                    <Field :label="__('Fee')" :errors="errors.fee">
                        <Input v-model="submit.fee" />
                    </Field>
                    <Field :label="__('Coupons')" :instructions="__('Select any coupons that would make a reservation credited to this affiliate.')" :errors="errors.coupons">
                        <template #actions>
                            <Button size="xs" variant="ghost" :text="__('Clear')" @click="clearAllCoupons" />
                        </template>
                        <Combobox
                            v-if="couponsLoaded"
                            v-model="submit.coupons"
                            multiple
                            :close-on-select="false"
                            :options="coupons"
                            option-label="title"
                            option-value="id"
                            :searchable="true"
                        />
                    </Field>
                    <Field :label="__('Allow skipping payment')">
                        <Switch v-model="submit.allow_skipping_payment" />
                    </Field>
                    <Field :label="__('Send reservation email')">
                        <Switch v-model="submit.send_reservation_email" />
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
import { Button, Card, Combobox, Field, Input, Stack, Switch } from '@statamic/cms/ui';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import axios from 'axios';
import { useFormHandler } from '../composables/useFormHandler.js';
import { useToast } from '../composables/useToast.js';

const props = defineProps({
    data: { type: Object, required: true },
    openPanel: { type: Boolean, default: false },
});

const emit = defineEmits(['closed', 'saved']);
const toast = useToast();

const submit = reactive({ coupons: [] });
const coupons = ref([]);
const couponsLoaded = ref(false);

const method = computed(() => ('id' in props.data ? 'patch' : 'post'));
const postUrl = computed(() =>
    'id' in props.data ? '/cp/resrv/affiliate/' + props.data.id : '/cp/resrv/affiliate',
);
const title = computed(() => ('id' in props.data ? __('Edit affiliate') : __('Add a new affiliate')));

const { disableSave, errors, save } = useFormHandler({
    submit,
    postUrl,
    method,
    successMessage: 'Affiliate successfully saved',
    emit,
});

watch(() => props.data, () => createSubmit(), { deep: true });

onMounted(() => {
    createSubmit();
    getCoupons();
});

function onClosed() {
    Object.keys(submit).forEach((key) => delete submit[key]);
    submit.coupons = [];
    emit('closed');
}

function createSubmit() {
    Object.keys(submit).forEach((key) => delete submit[key]);
    Object.entries(props.data).forEach(([name, value]) => {
        if (name !== 'coupons') {
            submit[name] = value;
        }
    });
    submit.coupons = props.data.coupons_ids || [];
}

function getCoupons() {
    axios.get('/cp/resrv/dynamicpricing/index', { params: { coupons_only: 'true' } })
        .then((response) => {
            coupons.value = response.data;
            couponsLoaded.value = true;
        })
        .catch(() => {
            toast.error('Cannot retrieve the coupons');
        });
}

function clearAllCoupons() {
    submit.coupons = [];
}
</script>
