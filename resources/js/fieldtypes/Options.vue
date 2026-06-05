<template>
    <element-container>
        <Alert v-if="newItem" :title="__('You need to save this entry before you can add options.')" variant="info" />
        <div class="statamic-resrv-options relative" v-else>
            <OptionsList :parent="props.meta.parent" />
        </div>
    </element-container>
</template>

<script setup>
import { Fieldtype } from '@statamic/cms';
import { Alert } from '@statamic/cms/ui';
import { computed, onMounted } from 'vue';
import OptionsList from '../components/OptionsList.vue';

const emit = defineEmits(Fieldtype.emits);
const props = defineProps(Fieldtype.props);
const { update, expose } = Fieldtype.use(emit, props);

const newItem = computed(() => props.meta.parent === 'Collection');

onMounted(() => {
    if (!newItem.value) {
        update(props.meta.parent);
    }
});

defineExpose(expose);
</script>
