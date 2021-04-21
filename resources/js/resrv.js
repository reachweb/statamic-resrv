import AvailabilityFieldtype from './fieldtypes/Availability.vue'

Statamic.booting(() => {
    // Fieldtypes
    Statamic.$components.register('availability-fieldtype', AvailabilityFieldtype); 
})