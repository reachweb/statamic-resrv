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

<script>
export default {
    props: {
        value: {
            type: [Boolean, String]
        },
        parent: {
            type: String
        },
    },
    computed: {
        stateLiteral() {
            if (this.value == 'disabled') {
                return 'false';
            }
            return 'true';
        },
        state() {
            if (this.value == 'disabled' || this.value == false) {
                return false;
            }
            return true
        }
    },
    methods: {
        toggle() {
            if (this.value == 'disabled') {
                this.$emit("input", this.parent)                
            } else {
                this.$emit("input", 'disabled')
            }
        }
    }
}
</script>