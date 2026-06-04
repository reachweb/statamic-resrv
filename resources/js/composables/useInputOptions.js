export function normalizeInputOptions(options) {
    if (!options) {
        return [];
    }

    return Object.entries(options).map(([key, value]) => ({
        value: Array.isArray(options) ? value : key,
        label: value || key,
    }));
}
