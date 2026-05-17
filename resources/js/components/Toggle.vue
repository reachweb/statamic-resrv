<template>
    <button
        type="button"
        class="toggle-container"
        :class="{ 'on': state, 'cursor-not-allowed': readOnly }"
        @click="toggle"
        :aria-pressed="stateLiteral"
        aria-label="Toggle Button"
    >
        <div class="toggle-slider">
            <div class="toggle-knob" tabindex="0" @keyup.prevent.space.enter="toggle" ref="knob" />
        </div>
    </button>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    modelValue: {
        type: [Boolean, String],
    },
    parent: {
        type: String,
    },
    readOnly: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['update:modelValue']);

const stateLiteral = computed(() => (props.modelValue === 'disabled' ? 'false' : 'true'));
const state = computed(() => !(props.modelValue === 'disabled' || props.modelValue === false));

function toggle() {
    if (props.modelValue === 'disabled') {
        emit('update:modelValue', props.parent);
    } else {
        emit('update:modelValue', 'disabled');
    }
}
</script>
