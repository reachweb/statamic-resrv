import { calendarPlugin } from '@reachweb/alpine-calendar';

document.addEventListener('alpine:init', () => {
    Alpine.plugin(calendarPlugin);
});

import dayjs from "dayjs";
window.dayjs = dayjs;