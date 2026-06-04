import { ref } from 'vue';

/**
 * Mirrors Statamic's slug auto-generation:
 *  - Slug is generated from a source field while the user hasn't touched it.
 *  - User typing in the slug field (or pre-filling it when editing) stops auto-generation.
 *  - Reset rebinds auto-generation (used when the panel is reopened for a new record).
 */
export function useSlugify() {
    const shouldSlugify = ref(true);

    function slugifyFrom(source) {
        if (!shouldSlugify.value) {
            return undefined;
        }

        const value = (source ?? '').toString();

        if (value === '') {
            return '';
        }

        return window.Statamic.$slug.separatedBy('-').create(value);
    }

    function onSlugInput() {
        shouldSlugify.value = false;
    }

    function reset(existingSlug = '') {
        shouldSlugify.value = !existingSlug;
    }

    return { shouldSlugify, slugifyFrom, onSlugInput, reset };
}
