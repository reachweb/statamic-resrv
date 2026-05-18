<template>
    <element-container>
        <Alert v-if="newItem" :title="__('You need to save this entry before you can add cutoff rules.')" variant="info" />
        <Alert v-else-if="cutoffFeatureDisabled" :title="__('Cutoff Rules Disabled')" variant="warning">
            {{ __('Cutoff rules are currently disabled in the global configuration. Enable them in the Resrv settings to use this feature.') }}
        </Alert>
        <div class="statamic-resrv-cutoff relative" v-else>
            <Field :label="__('Enable Cutoff Rules')">
                <Switch v-model="enabled" @update:modelValue="toggleCutoff" />
            </Field>

            <div v-if="enabled" class="space-y-6">
                <Alert variant="info">
                    {{ __('Cutoff times are checked against server time') }}: <strong>{{ serverTime }}</strong>
                    <span class="text-xs block mt-1">{{ __('Server timezone') }}: {{ serverTimezone }}</span>
                </Alert>

                <Panel :heading="__('Default Settings')">
                    <Card>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-x-4 gap-y-6">
                            <Field :label="__('Default Starting Time')" :instructions="__('When does your activity/service typically start?')">
                                <Input v-model="settings.default_starting_time" type="time" />
                            </Field>
                            <Field :label="__('Default Cutoff Hours')" :instructions="__('Hours before starting time to stop accepting bookings')">
                                <Input v-model="settings.default_cutoff_hours" type="number" inputAttrs="min='0' max='240'" />
                            </Field>
                        </div>
                    </Card>
                </Panel>

                <Panel :heading="__('Schedules')">
                    <template #header-actions>
                        <Button size="sm" :text="__('Add Schedule')" icon="add" @click="addSchedule" />
                    </template>
                    <Card>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mb-2">
                            {{ __('Configure different starting times and cutoff periods for specific date ranges.') }}
                        </p>
                        <div v-if="settings.schedules && settings.schedules.length > 0" class="space-y-4">
                            <div
                                v-for="(schedule, index) in settings.schedules"
                                :key="index"
                                class="border border-gray-200 dark:border-gray-700/80 rounded-lg p-4 bg-white dark:bg-gray-900/40"
                            >
                                <div class="flex items-center justify-end">
                                    <Button icon="trash" variant="ghost" size="sm" :aria-label="__('Remove')" @click="removeSchedule(index)" />
                                </div>
                                <div class="grid grid-cols-1 lg:grid-cols-2 gap-x-4 gap-y-6">
                                    <Field class="lg:col-span-2" :label="__('Date Range')">
                                        <DateRangePicker
                                            :model-value="scheduleDateRange(index)"
                                            granularity="day"
                                            @update:model-value="updateScheduleDateRange(index, $event)"
                                        />
                                    </Field>
                                    <Field :label="__('Starting Time')">
                                        <Input v-model="schedule.starting_time" type="time" />
                                    </Field>
                                    <Field :label="__('Cutoff Hours')">
                                        <Input v-model="schedule.cutoff_hours" type="number" inputAttrs="min='0' max='240'" />
                                    </Field>
                                </div>
                            </div>
                        </div>
                        <div v-else class="text-center py-6 text-sm text-gray-500 dark:text-gray-400">
                            {{ __('No schedules configured. Use default settings for all dates.') }}
                        </div>
                    </Card>
                </Panel>
            </div>
        </div>
    </element-container>
</template>

<script setup>
import { Fieldtype } from '@statamic/cms';
import { Alert, Button, Card, DateRangePicker, Field, Input, Panel, Switch } from '@statamic/cms/ui';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { toCalendarDate, toIsoString } from '../composables/useDateRangeModel.js';

const emit = defineEmits(Fieldtype.emits);
const props = defineProps(Fieldtype.props);
const { update, expose } = Fieldtype.use(emit, props);

const enabled = ref(false);
const settings = reactive({
    enable_cutoff: false,
    default_starting_time: '16:00',
    default_cutoff_hours: 3,
    schedules: [],
});

const newItem = computed(() => props.meta.parent === 'Collection');
const cutoffFeatureDisabled = computed(() => props.meta.cutoff_feature_enabled === false);
const serverTime = computed(() => props.meta.server_time);
const serverTimezone = computed(() => props.meta.server_timezone);

onMounted(() => {
    loadExistingSettings();
    if (!newItem.value) {
        updateFieldValue();
    }
});

watch(settings, () => {
    if (enabled.value) {
        updateFieldValue();
    }
}, { deep: true });

function loadExistingSettings() {
    if (props.value && typeof props.value === 'object') {
        Object.assign(settings, props.value);
        enabled.value = settings.enable_cutoff || false;
    } else {
        enabled.value = false;
    }
}

function toggleCutoff() {
    settings.enable_cutoff = enabled.value;
    updateFieldValue();
}

function addSchedule() {
    if (!settings.schedules) {
        settings.schedules = [];
    }

    settings.schedules.push({
        date_start: '',
        date_end: '',
        starting_time: settings.default_starting_time,
        cutoff_hours: settings.default_cutoff_hours,
    });

    updateFieldValue();
}

function removeSchedule(index) {
    settings.schedules.splice(index, 1);
    updateFieldValue();
}

function updateFieldValue() {
    update(enabled.value ? { ...settings, schedules: [...settings.schedules] } : null);
}

function scheduleDateRange(index) {
    const row = settings.schedules[index];
    return {
        start: toCalendarDate(row.date_start),
        end: toCalendarDate(row.date_end),
    };
}

function updateScheduleDateRange(index, value) {
    const row = settings.schedules[index];
    row.date_start = toIsoString(value?.start) ?? '';
    row.date_end = toIsoString(value?.end) ?? '';
}

defineExpose(expose);
</script>
