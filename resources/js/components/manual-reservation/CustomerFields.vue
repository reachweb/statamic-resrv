<script setup>
import { computed, ref, watch } from 'vue';
import {
    Checkbox,
    CheckboxGroup,
    Combobox,
    Description,
    Field,
    Input,
    Label,
    Radio,
    RadioGroup,
    Select,
    Switch,
    Textarea,
} from '@statamic/cms/ui';
import {
    fieldIsHidden,
    fieldIsRequired,
    fieldSelectOptions,
    knownFieldTypes,
    mirrorSourceHandle,
} from './customerForm.js';
import { vAccessibleName } from './accessibleName.js';

const props = defineProps({
    fields: { type: Array, required: true },
    values: { type: Object, required: true },
    errorFor: { type: Function, default: () => null },
});

const emit = defineEmits(['update']);

// Literal class strings so Tailwind's source scan picks them up.
const widthClasses = {
    100: '@lg/panel:col-span-12',
    75: '@lg/panel:col-span-9',
    66: '@lg/panel:col-span-8',
    50: '@lg/panel:col-span-6',
    33: '@lg/panel:col-span-4',
    25: '@lg/panel:col-span-3',
};

const nativeInputTypes = ['email', 'tel', 'url', 'number'];

const visibleFields = computed(() => props.fields.filter((field) => ! fieldIsHidden(field)));

const warnedTypes = new Set();

const componentType = (field) => {
    if (knownFieldTypes.includes(field.type)) return field.type;
    if (! warnedTypes.has(field.type)) {
        warnedTypes.add(field.type);
        console.warn(`[Resrv] Unknown checkout form field type [${field.type}] — rendering as text.`);
    }
    return 'text';
};

const widthClass = (field) => widthClasses[Number(field.width ?? 100)] ?? widthClasses[100];

const inputType = (field) => (nativeInputTypes.includes(field.input_type) ? field.input_type : 'text');

// Select, checkbox and radio groups render no single element a label can point at.
const controlId = (field) =>
    ['select', 'checkboxes', 'radio'].includes(componentType(field)) ? undefined : `resrv-create-customer-${field.handle}`;

const labelId = (field) => `resrv-create-customer-${field.handle}-label`;
const valueId = (field) => `resrv-create-customer-${field.handle}-value`;

// --- Repeat/confirm fields follow their source until the admin edits them ---
const mirrorSources = computed(() =>
    Object.fromEntries(
        props.fields
            .map((field) => [field.handle, mirrorSourceHandle(field, props.fields)])
            .filter(([, source]) => source !== null),
    ),
);

const touchedMirrors = ref(new Set());

watch(() => props.fields, () => (touchedMirrors.value = new Set()));

watch(
    () => Object.entries(mirrorSources.value).map(([mirror, source]) => [mirror, props.values[source] ?? '']),
    (pairs) => {
        pairs.forEach(([mirror, value]) => {
            if (! touchedMirrors.value.has(mirror) && props.values[mirror] !== value) {
                emit('update', mirror, value);
            }
        });
    },
    { deep: true, immediate: true },
);

const mirrorNote = (field) => {
    const source = mirrorSources.value[field.handle];
    if (! source || touchedMirrors.value.has(field.handle)) return null;
    const sourceField = props.fields.find((candidate) => candidate.handle === source);
    return __('Copied from :field automatically.', { field: sourceField?.display ?? source });
};

const update = (field, value) => {
    if (mirrorSources.value[field.handle] && ! touchedMirrors.value.has(field.handle)) {
        touchedMirrors.value = new Set([...touchedMirrors.value, field.handle]);
    }
    emit('update', field.handle, value);
};
</script>

<template>
    <div class="grid gap-x-4 gap-y-6 @lg/panel:grid-cols-12">
        <Field
            v-for="field in visibleFields"
            :key="field.handle"
            :class="widthClass(field)"
            :instructions="field.instructions ?? ''"
            :error="errorFor(field.handle)"
        >
            <template #label>
                <Label :id="labelId(field)" :for="controlId(field)" :text="field.display" :required="fieldIsRequired(field)" />
            </template>
            <Textarea
                v-if="componentType(field) === 'textarea'"
                :id="controlId(field)"
                :model-value="values[field.handle] ?? ''"
                @update:model-value="(value) => update(field, value)"
            />
            <Select
                v-else-if="componentType(field) === 'select'"
                v-accessible-name="{ labelId: labelId(field), valueId: valueId(field) }"
                :model-value="values[field.handle] === '' ? null : values[field.handle]"
                :options="fieldSelectOptions(field)"
                :clearable="! fieldIsRequired(field)"
                :placeholder="field.placeholder || __('Select...')"
                @update:model-value="(value) => update(field, value ?? '')"
            >
                <template #selected-option="{ option }">
                    <span :id="valueId(field)" class="block truncate">{{ option.label }}</span>
                </template>
            </Select>
            <CheckboxGroup
                v-else-if="componentType(field) === 'checkboxes'"
                :model-value="values[field.handle] ?? []"
                :aria-label="field.display"
                @update:model-value="(value) => update(field, value)"
            >
                <Checkbox
                    v-for="option in fieldSelectOptions(field)"
                    :key="option.value"
                    :value="option.value"
                    :label="String(option.label)"
                />
            </CheckboxGroup>
            <RadioGroup
                v-else-if="componentType(field) === 'radio'"
                :model-value="values[field.handle] === '' ? null : values[field.handle]"
                :aria-label="field.display"
                @update:model-value="(value) => update(field, value)"
            >
                <Radio
                    v-for="option in fieldSelectOptions(field)"
                    :key="option.value"
                    :value="option.value"
                    :label="String(option.label)"
                />
            </RadioGroup>
            <Switch
                v-else-if="componentType(field) === 'toggle'"
                :id="controlId(field)"
                :model-value="Boolean(values[field.handle])"
                @update:model-value="(value) => update(field, value)"
            />
            <Input
                v-else-if="componentType(field) === 'integer'"
                :id="controlId(field)"
                :model-value="values[field.handle]"
                type="number"
                :placeholder="field.placeholder || null"
                @update:model-value="(value) => update(field, value)"
            />
            <Input
                v-else-if="componentType(field) === 'dictionary' && field.phone_dictionary"
                :id="controlId(field)"
                :model-value="values[field.handle]"
                type="tel"
                @update:model-value="(value) => update(field, value)"
            />
            <Combobox
                v-else-if="componentType(field) === 'dictionary'"
                :id="controlId(field)"
                v-accessible-name="{ labelId: labelId(field), valueId: valueId(field) }"
                :model-value="values[field.handle] === '' ? null : values[field.handle]"
                :options="field.dictionary_items ?? []"
                :placeholder="__('Please select')"
                @update:model-value="(value) => update(field, value ?? '')"
            >
                <template #selected-option="{ option }">
                    <span :id="valueId(field)" class="block truncate">{{ option.label }}</span>
                </template>
            </Combobox>
            <Input
                v-else
                :id="controlId(field)"
                :model-value="values[field.handle]"
                :type="inputType(field)"
                :placeholder="field.placeholder || null"
                :prepend="field.prepend || null"
                :append="field.append || null"
                @update:model-value="(value) => update(field, value)"
            />
            <Description v-if="mirrorNote(field)" class="mt-2">{{ mirrorNote(field) }}</Description>
        </Field>
    </div>
</template>
