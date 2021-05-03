var _ = require('lodash')

import AvailabilityFieldtype from './fieldtypes/Availability.vue'
import ExtrasFieldtype from './fieldtypes/Extras.vue'

import ExtrasList from './components/ExtrasList.vue'
import LocationsList from './components/LocationsList.vue'

Statamic.booting(() => {
    // Fieldtypes
    Statamic.$components.register('availability-fieldtype', AvailabilityFieldtype);
    Statamic.$components.register('extras-fieldtype', ExtrasFieldtype);

    // Lists
    Statamic.$components.register('extras-list', ExtrasList);
    Statamic.$components.register('locations-list', LocationsList);
})