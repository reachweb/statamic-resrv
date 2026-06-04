<template>
    <Stack
        :open="true"
        :title="title"
        icon="fieldtype-users"
        size="narrow"
        @closed="onClosed"
    >
        <template #header-actions>
            <Button :text="__('Save')" variant="primary" :disabled="form.processing" @click="save" />
        </template>
        <template #default>
            <Card>
                <div class="space-y-6">
                    <Field :label="__('Name')" :error="form.errors.name">
                        <Input v-model="form.name" />
                    </Field>
                    <Field :label="__('Code')" :error="form.errors.code">
                        <Input v-model="form.code" />
                    </Field>
                    <Field :label="__('Email')" :error="form.errors.email">
                        <Input v-model="form.email" type="email" />
                    </Field>
                    <Field :label="__('Cookie duration in days')" :error="form.errors.cookie_duration">
                        <Input v-model="form.cookie_duration" type="number" />
                    </Field>
                    <Field :label="__('Fee')" :error="form.errors.fee">
                        <Input v-model="form.fee" />
                    </Field>
                    <Field :label="__('Coupons')" :instructions="__('Select any coupons that would make a reservation credited to this affiliate.')" :error="form.errors.coupons">
                        <template #actions>
                            <Button size="xs" variant="ghost" :text="__('Clear')" @click="clearAllCoupons" />
                        </template>
                        <Combobox
                            v-if="couponsLoaded"
                            v-model="form.coupons"
                            multiple
                            :close-on-select="false"
                            :options="coupons"
                            option-label="title"
                            option-value="id"
                            :searchable="true"
                        />
                    </Field>
                    <Field :label="__('Allow skipping payment')">
                        <Switch v-model="form.allow_skipping_payment" />
                    </Field>
                    <Field :label="__('Send reservation email')">
                        <Switch v-model="form.send_reservation_email" />
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
import { computed, onMounted, ref, watch } from 'vue';
import axios from 'axios';
import { useToast } from '../composables/useToast.js';

const props = defineProps({
    data: { type: Object, required: true },
});

const emit = defineEmits(['closed', 'saved']);
const toast = useToast();

const coupons = ref([]);
const couponsLoaded = ref(false);

const form = useForm({
    name: '',
    code: '',
    email: '',
    cookie_duration: '',
    fee: '',
    coupons: [],
    allow_skipping_payment: false,
    send_reservation_email: false,
    published: true,
});

const isEditing = computed(() => 'id' in props.data && !!props.data.id);
const title = computed(() => (isEditing.value ? __('Edit affiliate') : __('Add a new affiliate')));

watch(() => props.data, hydrateForm, { deep: true });

onMounted(() => {
    hydrateForm();
    getCoupons();
});

function hydrateForm() {
    const d = props.data;
    form.name = d.name ?? '';
    form.code = d.code ?? '';
    form.email = d.email ?? '';
    form.cookie_duration = d.cookie_duration ?? '';
    form.fee = d.fee ?? '';
    form.allow_skipping_payment = d.allow_skipping_payment ?? false;
    form.send_reservation_email = d.send_reservation_email ?? false;
    form.published = d.published ?? true;
    form.coupons = Array.isArray(d.coupons_ids) ? [...d.coupons_ids] : [];
    form.clearErrors();
}

function onClosed() {
    form.clearErrors();
    emit('closed');
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
    form.coupons = [];
}

function save() {
    const url = isEditing.value
        ? '/cp/resrv/affiliate/' + props.data.id
        : '/cp/resrv/affiliate';
    const method = isEditing.value ? 'patch' : 'post';

    form[method](url, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            toast.success(__('Affiliate successfully saved'));
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
