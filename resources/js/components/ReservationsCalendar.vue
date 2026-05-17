<template>
    <div>
        <Header :title="__('Reservations Calendar')" icon="calendar">
            <div class="flex items-center gap-2">
                <span class="text-sm text-gray-700 dark:text-gray-300">{{ __('Only show start dates') }}</span>
                <Switch v-model="onlyStart" />
            </div>
        </Header>

        <Card>
            <FullCalendar ref="fullCalendarRef" :options="calendarOptions" />
        </Card>
    </div>
</template>

<script setup>
import { Card, Header, Switch } from '@statamic/cms/ui';
import FullCalendar from '@fullcalendar/vue3';
import dayGridPlugin from '@fullcalendar/daygrid';
import interactionPlugin from '@fullcalendar/interaction';
import { ref, watch } from 'vue';

const props = defineProps({
    calendarJsonUrl: { type: String, required: true },
});

const onlyStart = ref(false);
const fullCalendarRef = ref(null);

const calendarOptions = {
    plugins: [dayGridPlugin, interactionPlugin],
    initialView: 'dayGridMonth',
    navLinks: true,
    eventColor: '#5b21b6',
    events: {
        url: props.calendarJsonUrl,
        extraParams: () => {
            if (onlyStart.value) {
                return { onlyStart: 1 };
            }
            return {};
        },
    },
    timeZone: 'UTC',
    eventTimeFormat: {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    },
    headerToolbar: {
        left: 'prev,next today',
        center: 'title',
        right: 'dayGridMonth,dayGridDay',
    },
};

watch(onlyStart, () => {
    const calApi = fullCalendarRef.value?.getApi();
    calApi?.refetchEvents();
});
</script>
