import AvailabilityFieldtype from './fieldtypes/Availability.vue';
import OptionsFieldtype from './fieldtypes/Options.vue';
import ExtrasFieldtype from './fieldtypes/Extras.vue';
import FixedPricingFieldtype from './fieldtypes/FixedPricing.vue';
import CutoffFieldtype from './fieldtypes/Cutoff.vue';

import ReservationsIndex from './pages/Reservations/Index.vue';
import ReservationsCalendarPage from './pages/Reservations/Calendar.vue';
import ReservationsShow from './pages/Reservations/Show.vue';
import DataImportIndex from './pages/DataImport/Index.vue';
import DataImportConfirm from './pages/DataImport/Confirm.vue';
import DataImportStore from './pages/DataImport/Store.vue';
import AffiliatesIndex from './pages/Affiliates/Index.vue';
import DynamicPricingIndex from './pages/DynamicPricing/Index.vue';
import ExportIndex from './pages/Export/Index.vue';
import ReportsIndex from './pages/Reports/Index.vue';
import ExtrasIndex from './pages/Extras/Index.vue';
import RatesIndex from './pages/Rates/Index.vue';

Statamic.booting(() => {
    Statamic.$components.register('resrv_availability-fieldtype', AvailabilityFieldtype);
    Statamic.$components.register('resrv_options-fieldtype', OptionsFieldtype);
    Statamic.$components.register('resrv_extras-fieldtype', ExtrasFieldtype);
    Statamic.$components.register('resrv_fixed_pricing-fieldtype', FixedPricingFieldtype);
    Statamic.$components.register('resrv_cutoff-fieldtype', CutoffFieldtype);

    Statamic.$inertia.register('resrv::Reservations/Index', ReservationsIndex);
    Statamic.$inertia.register('resrv::Reservations/Calendar', ReservationsCalendarPage);
    Statamic.$inertia.register('resrv::Reservations/Show', ReservationsShow);
    Statamic.$inertia.register('resrv::DataImport/Index', DataImportIndex);
    Statamic.$inertia.register('resrv::DataImport/Confirm', DataImportConfirm);
    Statamic.$inertia.register('resrv::DataImport/Store', DataImportStore);
    Statamic.$inertia.register('resrv::Affiliates/Index', AffiliatesIndex);
    Statamic.$inertia.register('resrv::DynamicPricing/Index', DynamicPricingIndex);
    Statamic.$inertia.register('resrv::Export/Index', ExportIndex);
    Statamic.$inertia.register('resrv::Reports/Index', ReportsIndex);
    Statamic.$inertia.register('resrv::Extras/Index', ExtrasIndex);
    Statamic.$inertia.register('resrv::Rates/Index', RatesIndex);
});
