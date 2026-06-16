<template>
    <element-container @resized="renderAgain">
        <Alert v-if="newItem" :text="__('You need to save this entry before you can add availability information.')" variant="default" />
        <div class="statamic-resrv-availability relative" v-else>
            <div class="flex items-center justify-between pb-4">
                <Label :text="__('Enable reservations')" />
                <Switch v-model="reservationsEnabled" :disabled="isReadOnly" @update:modelValue="changeAvailability" />
            </div>
            <Field v-if="hasRates" :label="__('Rate')" class="mb-3">
                <Select :placeholder="__('Select rate')" v-model="rateId" :options="rateOptions" />
            </Field>
            <Alert
                v-if="hasRates && rateId && editability.notice"
                variant="default"
                class="mb-3"
                :text="editability.notice"
            />
            <div class="w-full h-full relative" :inert="isReadOnly">
                <Loader v-if="!availabilityLoaded && !hasRates && ratesLoaded" />
                <div class="flex justify-end my-3" v-if="!hasRates || rateId">
                    <Button
                        size="sm"
                        variant="default"
                        :text="__('Bulk edit')"
                        icon="pencil"
                        :disabled="editability.kind === 'mirror'"
                        @click="showModal = 'massavailability'"
                    />
                </div>
                <div ref="calendarRef"></div>
            </div>
            <AvailabilityModal
                v-if="showModal === 'availability'"
                :dates="selectedDates"
                :parent-id="props.meta.parent"
                :rate="rateForChild"
                :pending-by-date="pendingByDateForSelection"
                @cancel="toggleModal"
                @saved="availabilitySaved"
            />
            <MassAvailabilityModal
                v-if="showModal === 'massavailability'"
                :parent-id="props.meta.parent"
                :rate="rateForChild"
                :rate-options="rateOptions"
                :rates="rates"
                @cancel="toggleModal"
                @saved="availabilitySaved"
            />
        </div>
    </element-container>
</template>

<script setup>
import { Fieldtype } from '@statamic/cms';
import { Alert, Button, Field, Label, Select, Switch } from '@statamic/cms/ui';
import { Calendar } from '@fullcalendar/core';
import dayGridPlugin from '@fullcalendar/daygrid';
import interactionPlugin from '@fullcalendar/interaction';
import { computed, onMounted, ref, watch } from 'vue';
import axios from 'axios';
import dayjs from 'dayjs';
import AvailabilityModal from '../components/AvailabilityModal.vue';
import MassAvailabilityModal from '../components/MassAvailabilityModal.vue';
import Loader from '../components/Loader.vue';
import { useToast } from '../composables/useToast.js';
import { rateEditability } from '../composables/useRateEditability.js';

const emit = defineEmits(Fieldtype.emits);
const props = defineProps(Fieldtype.props);
const { update, expose, isReadOnly } = Fieldtype.use(emit, props);
const toast = useToast();

const enabled = ref(props.value || 'disabled');
const reservationsEnabled = ref(enabled.value !== 'disabled');
const showModal = ref(false);
const selectedDates = ref(false);
const calendarRef = ref(null);
let calendar = null;
const availability = ref('');
const availabilityLoaded = ref(false);
const rateId = ref(null);
const rates = ref([]);
const ratesLoaded = ref(false);

const newItem = computed(() => props.meta.parent === 'Collection');
const hasRates = computed(() => rates.value.length > 1);
const maxAvailable = ref(0);
const rateOptions = computed(() => rates.value.map((r) => ({ label: rateOptionLabel(r), value: r.id })));
const selectedRate = computed(() => rates.value.find((r) => r.id === rateId.value) ?? null);
const editability = computed(() => rateEditability(selectedRate.value));
const rateForChild = computed(() => {
    if (!rateId.value) {
        return null;
    }
    const found = selectedRate.value;
    if (!found) {
        return null;
    }
    return {
        label: found.title,
        code: found.id,
        pricing_type: found.pricing_type,
        availability_type: found.availability_type,
        base_rate_id: found.base_rate_id,
        require_price_override: found.require_price_override,
        base_rate_title: found.base_rate?.title ?? null,
    };
});

// Suffix the rate label with its type so the picker (and the bulk-edit Combobox, which
// reuses these options) signals shared/relative rates before one is even selected.
function rateOptionLabel(rate) {
    const { tags } = rateEditability(rate);
    return tags.length ? `${rate.title} (${tags.join(', ')})` : rate.title;
}

const pendingByDateForSelection = computed(() => {
    if (!selectedDates.value || !availability.value) return {};
    const start = dayjs(selectedDates.value.start);
    // FullCalendar gives an exclusive end; iterate up to (but not including) it.
    const endExclusive = dayjs(selectedDates.value.end);
    const result = {};
    let cursor = start;
    while (cursor.isBefore(endExclusive)) {
        const key = cursor.format('YYYY-MM-DD');
        const row = availability.value[key];
        if (row?.pending && row.pending.length) {
            result[key] = row.pending;
        }
        cursor = cursor.add(1, 'day');
    }
    return result;
});

function handleSelect(date) {
    selectedDates.value = date;
    toggleModal('availability');
}

function renderDay(arg) {
    const day = dayjs(arg.date).format('YYYY-MM-DD');
    const arrayOfDomNodes = [];

    const dayLabel = document.createElement('div');
    dayLabel.className = 'resrv-day-number';
    dayLabel.innerHTML = arg.dayNumberText;
    arrayOfDomNodes.push(dayLabel);

    const data = availability.value && availability.value[day];
    if (!data) {
        return { domNodes: arrayOfDomNodes };
    }

    // renderDay runs only on calendar.render(); editability.value is read here non-reactively.
    // Every path that changes the selected rate calls getAvailability() -> calendar.render()
    // (see watch(rateId)), so the cells always reflect the current rate. If a future change
    // mutates editability WITHOUT refetching, add an explicit calendar.render() there.
    const lock = editability.value;

    if (data.available !== undefined && data.available !== null) {
        const count = Number(data.available);
        const locked = !lock.availability;
        const html = count === 0 ? window.__('Sold out') : `# ${count}`;
        arrayOfDomNodes.push(makeCell(
            ['resrv-cell', availabilityModifier(count, maxAvailable.value), locked && 'resrv-cell-locked'],
            html,
            locked ? {
                title: lock.availabilityReason ?? window.__('Availability follows the base rate'),
                ariaLabel: `${html} — ${window.__('shared with the base rate')}`,
            } : {},
        ));
    }

    // Shared+independent rates without a per-date override are unbookable when the rate
    // requires an override — the payload still returns the inherited base price, so it must
    // be suppressed here rather than shown as if the date were bookable.
    const priceRequiredButMissing = lock.kind === 'sharedIndependent'
        && lock.requirePriceOverride
        && data.price_override === false;

    if (priceRequiredButMissing) {
        arrayOfDomNodes.push(makeCell(
            ['resrv-cell', 'resrv-cell-price', 'resrv-cell-price-required'],
            window.__('Price required'),
            { title: window.__('Set a price for this rate — required before it can be booked on this date.') },
        ));
    } else if (data.price !== undefined && data.price !== null && data.price !== '') {
        const priceLocked = !lock.price;
        const inherited = lock.kind === 'sharedIndependent' && data.price_override === false;
        const html = `${props.meta.currency_symbol} ${data.price}`;
        let meta = {};
        if (priceLocked) {
            meta = {
                title: lock.priceReason ?? window.__('Price follows the base rate'),
                ariaLabel: `${html} — ${window.__('derived from the base rate')}`,
            };
        } else if (inherited) {
            meta = { title: window.__('Inherited from the base rate — set a price to override.') };
        }
        arrayOfDomNodes.push(makeCell(
            ['resrv-cell', 'resrv-cell-price', priceLocked && 'resrv-cell-locked', inherited && 'resrv-cell-price-inherited'],
            html,
            meta,
        ));
    }

    return { domNodes: arrayOfDomNodes };
}

function availabilityModifier(count, max) {
    if (count <= 0) return 'resrv-cell-sold-out';
    // With tiny inventories (yacht / single-unit rentals, ≤ 3 total) every
    // remaining unit is already meaningful — skip the amber "low" state so
    // 1/1 doesn't visually read as "running out".
    if (max <= 3) return 'resrv-cell-good';
    if (count <= Math.max(1, Math.ceil(max * 0.3))) return 'resrv-cell-low';
    return 'resrv-cell-good';
}

function makeCell(classes, html, { title, ariaLabel } = {}) {
    const cell = document.createElement('div');
    cell.className = classes.filter(Boolean).join(' ');
    cell.innerHTML = html;
    if (title) {
        cell.title = title;
    }
    if (ariaLabel) {
        cell.setAttribute('aria-label', ariaLabel);
    }
    return cell;
}

const calendarOptions = {
    plugins: [dayGridPlugin, interactionPlugin],
    selectable: true,
    initialView: 'dayGridMonth',
    select: handleSelect,
    dayCellContent: renderDay,
    aspectRatio: 0.85,
    fixedWeekCount: false,
};

onMounted(() => {
    calendar = new Calendar(calendarRef.value, calendarOptions);
    if (!newItem.value) {
        update(enabled.value);
        getRates();
    }
});

watch(rateId, () => {
    if (rateId.value !== null) {
        getAvailability();
    }
    renderAgain();
});

function toggleModal(modal) {
    if (!showModal.value) {
        showModal.value = modal;
    } else {
        showModal.value = false;
    }
}

function renderAgain() {
    window.dispatchEvent(new Event('resize'));
}

function availabilitySaved() {
    toggleModal();
    getAvailability();
    renderAgain();
}

function getRates() {
    axios.get('/cp/resrv/rates/for-entry/' + props.meta.parent)
        .then((response) => {
            rates.value = response.data;
            ratesLoaded.value = true;
            if (rates.value.length === 1) {
                rateId.value = rates.value[0].id;
            } else if (rates.value.length === 0) {
                getAvailability();
                calendar.render();
            }
        })
        .catch(() => {
            ratesLoaded.value = true;
            getAvailability();
            calendar.render();
        });
}

function getAvailability() {
    let url = '/cp/resrv/availability/' + props.meta.parent;
    if (rateId.value) {
        url += '/' + rateId.value;
    }
    availabilityLoaded.value = false;
    axios.get(url)
        .then((response) => {
            availability.value = response.data.data ?? {};
            maxAvailable.value = response.data.max_available ?? 0;
            calendar.render();
            availabilityLoaded.value = true;
        })
        .catch(() => {
            availabilityLoaded.value = true;
            toast.error(__('Cannot retrieve availability'));
        });
}

function changeAvailability(newValue) {
    enabled.value = newValue ? props.meta.parent : 'disabled';
    update(enabled.value);
}

defineExpose(expose);
</script>
