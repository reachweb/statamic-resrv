<template>
    <div>
        <Header :title="__('Reservations Calendar')" icon="calendar">
            <div class="flex items-center gap-2">
                <span class="text-sm text-gray-700 dark:text-gray-300">{{ __('Only show start dates') }}</span>
                <Switch v-model="onlyStart" />
            </div>
        </Header>

        <Card>
            <div class="statamic-resrv-reservations">
                <FullCalendar ref="fullCalendarRef" :options="calendarOptions" />
            </div>
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

function appendSpan(parent, className, text) {
    const span = document.createElement('span');
    span.className = className;
    span.textContent = text;
    parent.appendChild(span);
}

function renderEvent(arg) {
    const { reservationId, entryTitle, rateLabel, quantity } = arg.event.extendedProps;
    const card = document.createElement('div');
    card.className = 'resrv-event-card';

    appendSpan(card, 'resrv-event-dot', '');

    if (reservationId) {
        appendSpan(card, 'resrv-event-id', '#' + reservationId);
    }
    if (arg.timeText) {
        appendSpan(card, 'resrv-event-time', arg.timeText);
    }

    const bodyText = rateLabel ? `${entryTitle} - ${rateLabel}` : entryTitle;
    appendSpan(card, 'resrv-event-title', bodyText);

    if (quantity) {
        appendSpan(card, 'resrv-event-qty', '×' + quantity);
    }

    return { domNodes: [card] };
}

const calendarOptions = {
    plugins: [dayGridPlugin, interactionPlugin],
    initialView: 'dayGridMonth',
    navLinks: true,
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
    eventContent: renderEvent,
};

watch(onlyStart, () => {
    const calApi = fullCalendarRef.value?.getApi();
    calApi?.refetchEvents();
});
</script>
