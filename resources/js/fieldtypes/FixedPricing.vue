<template>
    <element-container>
        <Alert v-if="newItem" :text="__('You need to save this entry before you can add fixed pricing.')" variant="default" />
        <div class="statamic-resrv-extras relative" v-else :inert="isReadOnly">
            <FixedPricingList :parent="props.meta.parent" />
        </div>
    </element-container>
</template>

<script setup>
import { Fieldtype } from '@statamic/cms';
import { Alert } from '@statamic/cms/ui';
import { computed, onMounted } from 'vue';
import FixedPricingList from '../components/FixedPricingList.vue';

const emit = defineEmits(Fieldtype.emits);
const props = defineProps(Fieldtype.props);
const { update, expose, isReadOnly } = Fieldtype.use(emit, props);

const newItem = computed(() => props.meta.parent === 'Collection');

onMounted(() => {
    if (!newItem.value) {
        update(props.meta.parent);
    }
});

defineExpose(expose);
</script>
