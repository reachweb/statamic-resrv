import { computed } from 'vue';
import { CalendarDate } from '@internationalized/date';

function toCalendarDate(value) {
    if (!value) {
        return null;
    }

    if (typeof value === 'object' && 'year' in value && 'month' in value && 'day' in value) {
        return value;
    }

    const [year, month, day] = String(value).slice(0, 10).split('-').map(Number);

    if (!year || !month || !day) {
        return null;
    }

    return new CalendarDate(year, month, day);
}

function toIsoString(value) {
    if (!value) {
        return null;
    }

    if (typeof value === 'string') {
        return value.slice(0, 10);
    }

    const { year, month, day } = value;
    return `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}

export function useDateRangeModel(getStart, getEnd, setStart, setEnd) {
    return computed({
        get() {
            return {
                start: toCalendarDate(getStart()),
                end: toCalendarDate(getEnd()),
            };
        },
        set(value) {
            setStart(toIsoString(value?.start));
            setEnd(toIsoString(value?.end));
        },
    });
}

export { toCalendarDate, toIsoString };
