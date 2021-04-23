var _ = require('lodash')

import AvailabilityFieldtype from './fieldtypes/Availability.vue'
import ExtrasFieldtype from './fieldtypes/Extras.vue'

Statamic.booting(() => {
    // Fieldtypes
    Statamic.$components.register('availability-fieldtype', AvailabilityFieldtype);
    Statamic.$components.register('extras-fieldtype', ExtrasFieldtype);
})