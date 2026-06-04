<script setup>
import { Head } from '@statamic/cms/inertia';
import ReservationsCalendar from '../../components/ReservationsCalendar.vue';

const props = defineProps({
    calendarJsonUrl: { type: String, required: true },
    reservationsUrl: { type: String, required: true },
});
</script>

<template>
    <div class="max-w-page mx-auto">
        <Head :title="__('Reservations Calendar')" />

        <ReservationsCalendar :calendar-json-url="calendarJsonUrl" />
    </div>
</template>
