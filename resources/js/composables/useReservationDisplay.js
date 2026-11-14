import { DateFormatter } from '@statamic/cms';

// Sentence-case name for a raw status value ("awaiting_payment" → "Awaiting payment").
export function statusName(status) {
    const name = (status ?? '').replace(/_/g, ' ');
    return name.charAt(0).toUpperCase() + name.slice(1);
}

export function statusBadgeClass(status) {
    const map = {
        confirmed: 'bg-green-800',
        partner: 'bg-green-600',
        awaiting_payment: 'bg-blue-800',
        refunded: 'bg-yellow-800',
        cancelled: 'bg-orange-800',
        expired: 'bg-red-800',
    };
    return map[status] ?? 'bg-gray-800';
}

// The Listing's date presets (DateIndexFieldtype), so the Index, Show and modals read alike;
// instants also carry the viewer's zone because deadlines matter across time zones.
const instantOptions = { preset: 'datetime', timeZoneName: 'short' };
const wallDateOptions = { preset: 'date', timeZone: 'UTC' };
const WALL_DATE = /^(\d{4})-(\d{2})-(\d{2})/;

function toDate(value) {
    if (!value) return null;
    const date = value instanceof Date ? value : new Date(value);
    return Number.isNaN(date.getTime()) ? null : date;
}

// An ISO-8601 instant in the viewer's timezone with the zone shown.
export function formatInstant(iso, fallback = null, timeZone = null) {
    const date = toDate(iso);
    if (!date) return fallback;
    return DateFormatter.format(date, timeZone ? { ...instantOptions, timeZone } : instantOptions);
}

// A wall date ("2026-11-14…") shown as the Listing shows date-only values, never shifted by timezone.
export function formatWallDate(value, fallback = null) {
    const match = WALL_DATE.exec(String(value ?? ''));
    if (!match) return fallback;
    return DateFormatter.format(new Date(Date.UTC(+match[1], +match[2] - 1, +match[3])), wallDateOptions);
}

// Stay dates are wall dates unless the site charges by time; a midnight ISO value is shown date-only.
export function formatStayDate(iso, fallback = null, hasTime = null) {
    if (!iso) return fallback;
    const timed = hasTime ?? !/T00:00(:00(\.0+)?)?(Z|[+-]\d{2}:?\d{2})?$/.test(String(iso));
    return timed ? formatInstant(iso, fallback) : formatWallDate(iso, fallback);
}

function startOfLocalDay(date) {
    return new Date(date.getFullYear(), date.getMonth(), date.getDate());
}

// Relative wording by calendar day in the viewer's timezone ("in 2 days" for a deadline two dates
// ahead, even when fewer than 48 hours away); same-day instants use hours or minutes.
export function relativeInstant(iso, now = new Date()) {
    const date = toDate(iso);
    if (!date) return null;
    const locale = new DateFormatter().locale;
    const rtf = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });
    const days = Math.round((startOfLocalDay(date) - startOfLocalDay(now)) / 86400000);
    if (days !== 0) {
        return Math.abs(days) < 60 ? rtf.format(days, 'day') : DateFormatter.format(date, { relative: 'year' });
    }
    const minutes = Math.round((date - now) / 60000);
    return Math.abs(minutes) >= 60 ? rtf.format(Math.round(minutes / 60), 'hour') : rtf.format(minutes, 'minute');
}

// Listing rows carry the Date fieldtype's preProcessIndex payload; a date-only value is a
// wall date and must not be shifted into the viewer's timezone.
export function formatIndexDate(value) {
    if (!value?.date) return null;
    if (!value.format_has_time) return formatWallDate(value.date, value.date);
    const timeZone = value.timezone && value.timezone !== 'auto' ? value.timezone : null;
    return formatInstant(value.date, value.date, timeZone);
}

// The arrival day as a wall date "YYYY-MM-DD" from an ISO / Y-m-d value or a "d-m-Y H:i" string.
function wallDateOf(iso, legacy = null) {
    const match = WALL_DATE.exec(String(iso ?? ''));
    if (match) return `${match[1]}-${match[2]}-${match[3]}`;
    const old = /^(\d{2})-(\d{2})-(\d{4})/.exec(String(legacy ?? ''));
    return old ? `${old[3]}-${old[2]}-${old[1]}` : null;
}

function indexWallDate(value) {
    if (!value?.date) return null;
    if (!value.format_has_time) return wallDateOf(value.date);
    const date = toDate(value.date);
    if (!date) return null;
    const pad = (n) => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

// The end of the arrival day in the viewer's timezone: payment closes once it has passed.
export function arrivalDayEnd(wallDate) {
    const match = WALL_DATE.exec(String(wallDate ?? ''));
    return match ? new Date(+match[1], +match[2] - 1, +match[3], 23, 59, 59, 999) : null;
}

function prefixSymbol(amount, sample) {
    if (amount === null || amount === undefined || amount === '') return null;
    if (!/^[-\d]/.test(String(amount))) return String(amount);
    const symbol = String(sample ?? '').replace(/[-\d.,\s]+$/, '').trim();
    return symbol ? `${symbol} ${amount}` : String(amount);
}

const isZeroAmount = (amount) => amount !== null && amount !== undefined && amount !== '' && Number(amount) === 0;

/**
 * Facts the action modals show about a reservation, normalised from either the Show payload
 * (serializeReservation, amounts without symbol) or an Index row (ReservationResource).
 */
export function factsFromDetail(reservation, currencySymbol = '') {
    const withSymbol = (amount) => (amount === null || amount === undefined ? null : `${currencySymbol} ${amount}`.trim());
    const hasTime = reservation.stay_dates_have_time ?? null;
    const surchargeIsZero = reservation.payment_surcharge_is_zero ?? true;

    return {
        id: reservation.id,
        reference: reservation.reference,
        entryTitle: reservation.entry?.title ?? null,
        customerName: reservation.customer_name ?? null,
        customerEmail: reservation.customer_email ?? reservation.customer_data?.email ?? null,
        dateStart: formatStayDate(reservation.date_start_iso, reservation.date_start ?? null, hasTime),
        dateEnd: formatStayDate(reservation.date_end_iso, reservation.date_end ?? null, hasTime),
        arrivalDate: wallDateOf(reservation.date_start_iso, reservation.date_start),
        amount: withSymbol(reservation.payment_formatted),
        // undefined: not serialized; null: Resrv cannot tell whether the money arrived.
        amountPaid: reservation.amount_paid_formatted === undefined ? undefined : withSymbol(reservation.amount_paid_formatted),
        surcharge: surchargeIsZero ? null : withSymbol(reservation.payment_surcharge_formatted),
        chargeAmount: surchargeIsZero ? null : withSymbol(reservation.total_to_charge_formatted),
        total: withSymbol(reservation.total_formatted),
        method: reservation.payment_gateway_label ?? null,
        manualConfirmation: reservation.payment_gateway_supports_manual_confirmation ?? null,
        requestSent: !!(reservation.payment_request_email_sent_at_iso ?? reservation.payment_request_email_sent_at),
        affectsAvailability: !!reservation.affects_availability,
        holdExpiresAt: formatInstant(reservation.hold_expires_at_iso, reservation.hold_expires_at ?? null),
        holdDeadlinePassed: !!reservation.hold_deadline_passed,
        arrivalDayPassed: !!reservation.arrival_day_passed,
    };
}

export function factsFromRow(row) {
    const symbolSample = row.payment ?? row.price;
    const surchargeIsZero = row.payment_surcharge_is_zero ?? true;
    const surcharge = row.payment_surcharge_formatted ?? row.surcharge_formatted ?? null;

    return {
        id: row.id,
        reference: row.reference,
        entryTitle: row.entry?.title ?? null,
        customerName: row.customer_name ?? null,
        customerEmail: row.customer_email ?? row.customer?.email ?? null,
        dateStart: formatIndexDate(row.date_start),
        dateEnd: formatIndexDate(row.date_end),
        arrivalDate: indexWallDate(row.date_start),
        amount: row.payment ?? prefixSymbol(row.payment_formatted, row.price),
        amountPaid: row.amount_paid_formatted === undefined ? undefined : prefixSymbol(row.amount_paid_formatted, symbolSample),
        surcharge: surchargeIsZero || isZeroAmount(surcharge) ? null : prefixSymbol(surcharge, symbolSample),
        chargeAmount: surchargeIsZero || isZeroAmount(surcharge) ? null : prefixSymbol(row.total_to_charge_formatted, symbolSample),
        total: prefixSymbol(row.total_formatted, symbolSample),
        method: row.payment_gateway ?? null,
        manualConfirmation: row.supports_manual_confirmation ?? row.payment_gateway_supports_manual_confirmation ?? null,
        requestSent: !!row.payment_request_email_sent_at,
        affectsAvailability: !!row.affects_availability,
        holdExpiresAt: formatInstant(row.hold_expires_at),
        holdDeadlinePassed: !!row.payment_window_closed && !row.arrival_day_passed,
        arrivalDayPassed: !!row.arrival_day_passed,
    };
}

export function paymentRequestActionLabel(requestSent) {
    return requestSent ? __('Resend payment request') : __('Send payment request');
}

export function paymentRequestConfirmText(facts) {
    const email = facts?.customerEmail;
    if (facts?.requestSent) {
        return email
            ? __('This will email the payment request again to :email.', { email })
            : __('This will email the payment request again to the customer for this reservation.');
    }
    return email
        ? __('This will email the payment request to :email.', { email })
        : __('This will email the payment request to the customer for this reservation.');
}

// Mark-as-paid labels follow the gateway: an online booking is normally paid through its link,
// so recording a payment by hand is the exception and says so.
export function markAsPaidLabel(manualConfirmation) {
    return manualConfirmation === true ? __('Mark as paid…') : __('Mark as paid manually…');
}

function copyWithTextarea(text) {
    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.setAttribute('readonly', '');
    textarea.style.position = 'fixed';
    textarea.style.top = '0';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.focus({ preventScroll: true });
    textarea.select();
    // A focus trap (an open menu or modal) can pull focus back, leaving nothing selected while
    // execCommand('copy') still reports success; only trust it while the textarea holds the selection.
    const selected = document.activeElement === textarea
        && textarea.selectionStart === 0
        && textarea.selectionEnd === text.length;
    let copied = false;
    if (selected) {
        try {
            copied = document.execCommand('copy');
        } catch (error) {
            copied = false;
        }
    }
    document.body.removeChild(textarea);
    return copied;
}

// Lets a dropdown menu that triggered the copy close first, releasing its focus trap.
const afterMenusClose = () => new Promise((resolve) => setTimeout(resolve, 0));

// Clipboard API needs a secure origin; fall back to execCommand, then to Statamic's own
// "Copy to clipboard" modal that shows the link in a selectable read-only input.
export async function copyPaymentLink(url, toast) {
    if (!url) return;
    if (typeof navigator.clipboard?.writeText === 'function') {
        try {
            await navigator.clipboard.writeText(url);
            toast.success(__('Payment link copied'));
            return;
        } catch (error) {
            // Fall through to the fallbacks below.
        }
    }
    await afterMenusClose();
    if (copyWithTextarea(url)) {
        toast.success(__('Payment link copied'));
        return;
    }
    if (window.Statamic?.$callbacks) {
        window.Statamic.$callbacks.call('copyToClipboard', url);
        return;
    }
    toast.error(__('Could not copy the payment link'));
}
