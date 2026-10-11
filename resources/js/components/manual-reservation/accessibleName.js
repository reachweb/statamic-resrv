// Names controls core leaves unnamed: the Combobox/Select trigger (reka's "Show popup") and the date
// range picker's icon buttons. Usage: v-accessible-name="{ labelId, valueId?, openLabel?, clearLabel? }"
const apply = (el, { value }) => {
    if (! value || typeof el?.querySelectorAll !== 'function') return;

    const { labelId, valueId = null, openLabel = null, clearLabel = null } = value;

    if (labelId) {
        const labelledBy = [labelId, valueId].filter(Boolean).join(' ');
        el.querySelectorAll('[data-ui-combobox-trigger]').forEach((trigger) => {
            if (trigger.getAttribute('aria-labelledby') !== labelledBy) trigger.setAttribute('aria-labelledby', labelledBy);
        });
    }

    if (openLabel) {
        el.querySelectorAll('[data-reka-date-field-segment="trigger"]').forEach((button) => {
            if (button.getAttribute('aria-label') !== openLabel) button.setAttribute('aria-label', openLabel);
        });
    }

    if (clearLabel) {
        el.querySelectorAll('button:not([data-reka-date-field-segment])').forEach((button) => {
            if (button.textContent.trim() === '' && button.getAttribute('aria-label') !== clearLabel) {
                button.setAttribute('aria-label', clearLabel);
            }
        });
    }
};

export const vAccessibleName = {
    mounted: apply,
    updated: apply,
};
