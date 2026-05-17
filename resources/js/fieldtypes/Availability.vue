<template>
    <element-container @resized="renderAgain">
        <Alert v-if="newItem" :title="__('You need to save this entry before you can add availability information.')" variant="info" />
        <div class="statamic-resrv-availability relative" v-else>
            <Field :label="__('Enable reservations')">
                <Toggle v-model="enabled" :parent="props.meta.parent" @update:modelValue="changeAvailability" />
            </Field>
            <Field v-if="hasRates" :label="__('Rate')" class="mb-3">
                <Select :placeholder="__('Select rate')" v-model="rateId" :options="rateOptions" />
            </Field>
            <div class="w-full h-full relative">
                <Loader v-if="!availabilityLoaded && !hasRates && ratesLoaded" />
                <div class="flex justify-end my-3" v-if="!hasRates || rateId">
                    <Button size="sm" variant="default" :text="__('Bulk edit')" icon="pencil" @click="showModal = 'massavailability'" />
                </div>
                <div ref="calendarRef"></div>
            </div>
            <AvailabilityModal
                v-if="showModal === 'availability'"
                :dates="selectedDates"
                :parent-id="props.meta.parent"
                :rate="rateForChild"
                @cancel="toggleModal"
                @saved="availabilitySaved"
            />
            <MassAvailabilityModal
                v-if="showModal === 'massavailability'"
                :parent-id="props.meta.parent"
                :rate="rateForChild"
                :rate-options="rateOptions"
                @cancel="toggleModal"
                @saved="availabilitySaved"
            />
        </div>
    </element-container>
</template>

<script setup>
import { Fieldtype } from '@statamic/cms';
import { Alert, Button, Field, Select } from '@statamic/cms/ui';
import { Calendar } from '@fullcalendar/core';
import dayGridPlugin from '@fullcalendar/daygrid';
import interactionPlugin from '@fullcalendar/interaction';
import { computed, onMounted, onUpdated, ref, watch } from 'vue';
import axios from 'axios';
import dayjs from 'dayjs';
import AvailabilityModal from '../components/AvailabilityModal.vue';
import MassAvailabilityModal from '../components/MassAvailabilityModal.vue';
import Toggle from '../components/Toggle.vue';
import Loader from '../components/Loader.vue';
import { useToast } from '../composables/useToast.js';

const emit = defineEmits(Fieldtype.emits);
const props = defineProps(Fieldtype.props);
const { update, expose } = Fieldtype.use(emit, props);
const toast = useToast();

const enabled = ref(props.value || 'disabled');
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
const rateOptions = computed(() => rates.value.map((r) => ({ label: r.title, value: r.id })));
const rateForChild = computed(() => {
    if (!rateId.value) {
        return null;
    }
    const found = rates.value.find((r) => r.id === rateId.value);
    return found ? { label: found.title, code: found.id } : null;
});

function handleSelect(date) {
    selectedDates.value = date;
    toggleModal('availability');
}

function renderDay(arg) {
    const arrayOfDomNodes = [];
    const day = dayjs(arg.date).format('YYYY-MM-DD');
    const defaultClasses = ['p-2', 'text-xs', 'text-white', 'bg-green-700'];

    const dayLabel = document.createElement('div');
    dayLabel.classList.add('mt-1', 'mb-1');
    dayLabel.innerHTML = arg.dayNumberText;
    arrayOfDomNodes.push(dayLabel);

    if (!availability.value) {
        return { domNodes: arrayOfDomNodes };
    }

    if (hasAvailable(day)) {
        const avail = document.createElement('div');
        if (hasAvailable(day) > 0) {
            avail.classList.add(...defaultClasses, 'bg-green-700');
        }
        avail.innerHTML = '# ' + hasAvailable(day);
        arrayOfDomNodes.push(avail);
    }

    if (hasPrice(day)) {
        const price = document.createElement('div');
        if (hasPrice(day) > 0) {
            price.classList.add(...defaultClasses, 'bg-gray-700');
        }
        price.innerHTML = props.meta.currency_symbol + ' ' + hasPrice(day);
        arrayOfDomNodes.push(price);
    }

    return { domNodes: arrayOfDomNodes };
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

onUpdated(() => {
    if (!newItem.value) {
        update(enabled.value);
    }
});

watch(rateId, () => {
    if (rateId.value !== null) {
        getAvailability();
    } else {
        clearAvailability();
        calendar?.destroy();
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

function toggleAvailability() {
    availabilityLoaded.value = !availabilityLoaded.value;
}

function renderAgain() {
    window.dispatchEvent(new Event('resize'));
}

function hasAvailable(day) {
    if (day in availability.value) {
        if (availability.value[day].available) {
            return availability.value[day].available;
        }
    }
    return false;
}

function hasPrice(day) {
    if (day in availability.value) {
        if (availability.value[day].price) {
            return availability.value[day].price;
        }
    }
    return false;
}

function availabilitySaved() {
    toggleAvailability();
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
    axios.get(url)
        .then((response) => {
            availability.value = response.data;
            calendar.render();
            toggleAvailability();
        })
        .catch(() => {
            toast.error('Cannot retrieve availability');
        });
}

function clearAvailability() {
    availability.value = '';
    calendar.render();
    toggleAvailability();
}

function changeAvailability(newValue) {
    if (newValue === 'disabled') {
        update('disabled');
    } else {
        update(props.meta.parent);
    }
}

defineExpose(expose);
</script>
