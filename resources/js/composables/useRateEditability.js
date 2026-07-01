/**
 * Resolve which fields are editable for a given rate, mirroring the backend's
 * Rate::isRelative() / isShared() / hasIndependentSharedPricing() logic
 * (src/Models/Rate.php) and AvailabilityCpController::updateAvailability() so the CP
 * availability UI matches what the backend actually persists.
 *
 * - availability_type === 'shared' => inventory mirrors the base rate (read-only).
 * - price is read-only ONLY for the mirror (shared + relative): it derives its price
 *   from the base rate's modifier and stores nothing of its own. A relative + independent
 *   rate keeps its OWN availability rows whose price is the SOURCE the modifier is applied
 *   to on read, so that price is editable here.
 *
 * The four combinations:
 *   independent + independent → both editable (the base / default rate).
 *   relative    + independent → both editable; the price entered is the source the modifier adjusts.
 *   independent + shared      → price editable (per-date override), availability locked.
 *   relative    + shared      → both locked (mirrors the base rate).
 *
 * Accepts either a full rate object (with a `base_rate` relation) or the flattened
 * shape the modals receive (with a `base_rate_title` key).
 *
 * @param {object|null} rate
 * @returns {{
 *   price: boolean,
 *   availability: boolean,
 *   kind: ('base'|'relativeIndependent'|'sharedIndependent'|'mirror'),
 *   requirePriceOverride: boolean,
 *   tags: string[],
 *   priceReason: ?string,
 *   availabilityReason: ?string,
 *   notice: ?string,
 * }}
 */
export function rateEditability(rate) {
    if (!rate) {
        return {
            price: true,
            availability: true,
            kind: 'base',
            requirePriceOverride: false,
            tags: [],
            priceReason: null,
            availabilityReason: null,
            notice: null,
        };
    }

    const isRelative = rate.pricing_type === 'relative';
    const isShared = rate.availability_type === 'shared';
    const base = (rate.base_rate?.title ?? rate.base_rate_title) ?? __('the base rate');

    // Price is only ever read-only for the mirror (shared + relative); availability mirrors the base
    // whenever inventory is shared.
    const priceLocked = isRelative && isShared;
    const availabilityLocked = isShared;

    const tags = [];
    if (isRelative) {
        tags.push(__('Relative'));
    }
    if (isShared) {
        tags.push(__('Shared'));
    }

    let kind = 'base';
    let notice = null;
    if (isShared && isRelative) {
        kind = 'mirror';
        notice = __('This rate mirrors :base for both price and availability. To make changes, edit :base.', { base });
    } else if (isShared) {
        kind = 'sharedIndependent';
        notice = __('This rate shares its inventory with :base. You can set its own price here; availability is managed on :base.', { base });
    } else if (isRelative) {
        kind = 'relativeIndependent';
        notice = __("This rate keeps its own availability. The price you set here is the base the rate's modifier is applied to before it is shown to customers.", { base });
    }

    return {
        price: !priceLocked,
        availability: !availabilityLocked,
        kind,
        requirePriceOverride: !!rate.require_price_override,
        tags,
        priceReason: priceLocked
            ? __('Price follows :base.', { base })
            : (kind === 'relativeIndependent' ? __('The rate modifier is applied to this price.') : null),
        availabilityReason: availabilityLocked ? __('Availability follows :base.', { base }) : null,
        notice,
    };
}
