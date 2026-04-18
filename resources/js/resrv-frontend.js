import { calendarPlugin } from '@reachweb/alpine-calendar';
import '@reachweb/alpine-calendar/css';

document.addEventListener('alpine:init', () => {
    Alpine.plugin(calendarPlugin);
});

import dayjs from "dayjs";
window.dayjs = dayjs;