var _ = require('lodash')

import AvailabilityFieldtype from './fieldtypes/Availability.vue'
import OptionsFieldtype from './fieldtypes/Options.vue'
import ExtrasFieldtype from './fieldtypes/Extras.vue'
import FixedPricing from './fieldtypes/FixedPricing.vue'

import ExtrasList from './components/ExtrasList.vue'
import LocationsList from './components/LocationsList.vue'
import ReservationsList from './components/ReservationsList.vue'
import DynamicPricingList from './components/DynamicPricingList.vue'

import ReservationsCalendar from './components/ReservationsCalendar.vue'

Statamic.booting(() => {
    // Fieldtypes
    Statamic.$components.register('availability-fieldtype', AvailabilityFieldtype);
    Statamic.$components.register('options-fieldtype', OptionsFieldtype);
    Statamic.$components.register('extras-fieldtype', ExtrasFieldtype);
    Statamic.$components.register('fixed_pricing-fieldtype', FixedPricing);

    // Lists
    Statamic.$components.register('extras-list', ExtrasList);
    Statamic.$components.register('locations-list', LocationsList);
    Statamic.$components.register('reservations-list', ReservationsList);
    Statamic.$components.register('dynamic-pricing-list', DynamicPricingList);

    // Calendar
    Statamic.$components.register('reservations-calendar', ReservationsCalendar);
})