import AvailabilityFieldtype from './fieldtypes/Availability.vue'
import OptionsFieldtype from './fieldtypes/Options.vue'
import ExtrasFieldtype from './fieldtypes/Extras.vue'
import FixedPricing from './fieldtypes/FixedPricing.vue'
import CutoffFieldtype from './fieldtypes/Cutoff.vue'

import AffiliatesList from './components/AffiliatesList.vue'
import ExtrasList from './components/ExtrasList.vue'
import ReservationsList from './components/ReservationsList.vue'
import DynamicPricingList from './components/DynamicPricingList.vue'
import ReportsView from './components/ReportsView.vue'

import ReservationsCalendar from './components/ReservationsCalendar.vue'

Statamic.booting(() => {
    // Fieldtypes
    Statamic.$components.register('resrv_availability-fieldtype', AvailabilityFieldtype);
    Statamic.$components.register('resrv_options-fieldtype', OptionsFieldtype);
    Statamic.$components.register('resrv_extras-fieldtype', ExtrasFieldtype);
    Statamic.$components.register('resrv_fixed_pricing-fieldtype', FixedPricing);
    Statamic.$components.register('resrv_cutoff-fieldtype', CutoffFieldtype);

    // Lists
    Statamic.$components.register('affiliates-list', AffiliatesList);
    Statamic.$components.register('extras-list', ExtrasList);
    Statamic.$components.register('reservations-list', ReservationsList);
    Statamic.$components.register('dynamic-pricing-list', DynamicPricingList);
    Statamic.$components.register('reports-view', ReportsView);
  
    // Calendar
    Statamic.$components.register('reservations-calendar', ReservationsCalendar);
})