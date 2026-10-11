// Value helpers for the checkout-form fields the manual reservation page renders. The same field
// types (and value shapes) the frontend checkout renders must work here.

export const knownFieldTypes = ['text', 'textarea', 'select', 'checkboxes', 'radio', 'toggle', 'integer', 'dictionary'];

export function emptyFieldValue(field) {
    if (field.type === 'checkboxes') return [];
    if (field.type === 'toggle') return false;
    return '';
}

function rulesOf(field) {
    const rules = field.validate ?? [];
    return Array.isArray(rules) ? rules : String(rules).split('|');
}

export function fieldIsRequired(field) {
    return rulesOf(field).some((rule) => String(rule).startsWith('required'));
}

// The frontend skips hidden text inputs; they stay in the payload with their empty value.
export function fieldIsHidden(field) {
    return field.type === 'text' && field.input_type === 'hidden';
}

// Statamic stores options as an assoc map, {key, value} rows, or a plain list — normalize all three.
export function fieldSelectOptions(field) {
    const options = field.options ?? {};

    if (Array.isArray(options)) {
        return options.map((option) =>
            option !== null && typeof option === 'object'
                ? { value: option.key, label: option.value ?? String(option.key) }
                : { value: option, label: String(option) },
        );
    }

    return Object.entries(options).map(([value, label]) => ({ value, label: label ?? value }));
}

// Keeps a value typed for another entry's form only when the new field can render it (forms can differ per entry).
export function carriedFieldValue(field, value) {
    const empty = emptyFieldValue(field);
    if (value === undefined || value === null) return empty;

    const allowed = () => new Set(fieldSelectOptions(field).map((option) => String(option.value)));

    switch (field.type) {
        case 'checkboxes':
            return Array.isArray(value) ? value.filter((item) => allowed().has(String(item))) : empty;
        case 'toggle':
            return typeof value === 'boolean' ? value : empty;
        case 'select':
        case 'radio':
            return allowed().has(String(value)) ? value : empty;
        case 'dictionary':
            if (field.phone_dictionary) break;
            return (field.dictionary_items ?? []).some((item) => String(item.value) === String(value)) ? value : empty;
    }

    return Array.isArray(value) || typeof value === 'boolean' ? empty : value;
}

// A "repeat"/"confirm" field (repeat_email, confirm_email, email_confirmation, or a same: rule)
// mirrors another field of the form; returns that field's handle.
export function mirrorSourceHandle(field, fields) {
    const handles = new Set(fields.map((other) => other.handle).filter((handle) => handle !== field.handle));
    const candidates = [];

    const prefixed = field.handle.match(/^(?:repeat|confirm)_(.+)$/);
    if (prefixed) candidates.push(prefixed[1]);

    const suffixed = field.handle.match(/^(.+)_confirmation$/);
    if (suffixed) candidates.push(suffixed[1]);

    rulesOf(field).forEach((rule) => {
        const same = String(rule).match(/^same:(?:form\.|customer\.)?(.+)$/);
        if (same) candidates.push(same[1]);
    });

    return candidates.find((handle) => handles.has(handle)) ?? null;
}
