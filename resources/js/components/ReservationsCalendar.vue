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
                <ul class="resrv-calendar-legend mb-4 flex flex-wrap items-center gap-x-4 gap-y-2 text-xs" :aria-label="__('Legend')">
                    <li v-for="item in legend" :key="item.status" :class="`resrv-event--${item.status}`">
                        <span class="resrv-event-card">
                            <span class="resrv-event-dot" aria-hidden="true"></span>
                            <span class="resrv-event-title">{{ item.label }}</span>
                        </span>
                    </li>
                </ul>
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
import { formatInstant } from '../composables/useReservationDisplay.js';

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

const legend = [
    { status: 'confirmed', label: __('Confirmed') },
    { status: 'partner', label: __('Partner') },
    { status: 'awaiting_payment', label: __('Awaiting payment (holds availability)') },
];

function eventTooltip(event) {
    const { status, hold_expires_at: holdExpiresAt, holdExpiresAt: holdExpiresAtCamel } = event.extendedProps;
    const isAwaiting = status === 'awaiting_payment' || event.classNames.includes('resrv-event--awaiting_payment');
    if (!isAwaiting) return event.title;

    const deadline = formatInstant(holdExpiresAt ?? holdExpiresAtCamel);
    const note = deadline
        ? __('Awaiting payment — cancels automatically if not paid by :deadline.', { deadline })
        : __('Awaiting payment — no payment deadline.');
    return `${event.title}\n${note}`;
}

function renderEvent(arg) {
    const { reservationId, entryTitle, rateLabel, quantity } = arg.event.extendedProps;
    const card = document.createElement('div');
    card.className = 'resrv-event-card';
    card.title = eventTooltip(arg.event);

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
