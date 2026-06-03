<template>
    <Stack
        :open="true"
        :title="__('Mass assign') + ' — ' + data.name"
        icon="duplicate"
        size="narrow"
        @closed="onClosed"
    >
        <template #header-actions>
            <Button :text="__('Save')" variant="primary" :disabled="disableSave" @click="save" />
        </template>
        <template #default>
            <Card>
                <div class="space-y-6">
                    <Field :label="__('Entries')" :instructions="__('Select the entries that this extra should apply to.')" :errors="errors.entries">
                        <EntriesStackPicker
                            v-if="entriesLoaded && selectedEntriesLoaded"
                            v-model="submit.entries"
                            :options="entries"
                            option-label="title"
                            option-value="id"
                            :stack-title="__('Select entries')"
                        >
                            <template #actions>
                                <Button size="xs" variant="ghost" :text="__('Select all')" @click="selectAll" />
                                <span class="text-xs text-gray-400">|</span>
                                <Button size="xs" variant="ghost" :text="__('Remove all')" @click="removeAll" />
                            </template>
                        </EntriesStackPicker>
                    </Field>
                </div>
            </Card>
        </template>
    </Stack>
</template>

<script setup>
import { Button, Card, Field, Stack } from '@statamic/cms/ui';
import { computed, onMounted, ref, watch } from 'vue';
import axios from 'axios';
import EntriesStackPicker from './EntriesStackPicker.vue';
import { useFormHandler } from '../composables/useFormHandler.js';
import { useToast } from '../composables/useToast.js';

const props = defineProps({
    data: { type: Object, required: true },
    openPanel: { type: Boolean, default: false },
});

const emit = defineEmits(['closed', 'saved']);
const toast = useToast();

const submit = ref({ entries: [] });
const entries = ref([]);
const selectedEntries = ref([]);
const entriesLoaded = ref(false);
const selectedEntriesLoaded = ref(false);

const postUrl = computed(() => '/cp/resrv/extra/massadd/' + props.data.id);

const { disableSave, errors, save } = useFormHandler({
    submit,
    postUrl,
    method: 'post',
    successMessage: 'Extra successfully assigned',
    emit,
});

disableSave.value = true;

watch(submit, (value) => {
    disableSave.value = !(value.entries && value.entries.length > 0);
}, { deep: true });

onMounted(() => {
    getSelectedEntries();
    getEntries();
});

function onClosed() {
    submit.value = { entries: [] };
    emit('closed');
}

function selectAll() {
    submit.value.entries = entries.value.map((item) => item.id);
}

function removeAll() {
    submit.value.entries = [];
}

function getSelectedEntries() {
    axios.get('/cp/resrv/extra/entries/' + props.data.id)
        .then((response) => {
            selectedEntries.value = response.data;
            // Seed the picker selection here (not via a watcher) so it runs exactly
            // once when the data arrives and can never re-fire over later user edits.
            submit.value.entries = selectedEntries.value.map((item) => item.id);
            selectedEntriesLoaded.value = true;
        })
        .catch(() => {
            toast.error('Cannot retrieve entries for this extra');
        });
}

function getEntries() {
    axios.get('/cp/resrv/utility/entries')
        .then((response) => {
            entries.value = response.data;
            entriesLoaded.value = true;
        })
        .catch(() => {
            toast.error('Cannot retrieve the entries');
        });
}
</script>
