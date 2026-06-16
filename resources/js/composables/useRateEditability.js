/**
 * Resolve which fields are editable for a given rate, mirroring the backend's
 * Rate::isRelative() / isShared() / hasIndependentSharedPricing() logic
 * (src/Models/Rate.php) so the CP availability UI matches what updateAvailability()
 * actually persists.
 *
 * - pricing_type === 'relative'    => price is derived from the base rate (read-only).
 * - availability_type === 'shared' => inventory mirrors the base rate (read-only).
 *
 * The four combinations:
 *   independent + independent → both editable (the base / default rate).
 *   relative    + independent → price locked, availability editable.
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
        notice = __("This rate's price is calculated from :base. You can edit availability here, but the price is read-only.", { base });
    }

    return {
        price: !isRelative,
        availability: !isShared,
        kind,
        requirePriceOverride: !!rate.require_price_override,
        tags,
        priceReason: isRelative ? __('Price follows :base.', { base }) : null,
        availabilityReason: isShared ? __('Availability follows :base.', { base }) : null,
        notice,
    };
}
