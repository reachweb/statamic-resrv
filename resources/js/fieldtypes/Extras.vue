<template>
    <element-container>
        <Alert v-if="newItem" :title="__('You need to save this entry before you can add extras.')" variant="info" />
        <div class="statamic-resrv-extras relative" v-else>
            <p class="text-sm text-gray-600 dark:text-gray-400 mb-3">
                {{ __('You can only enable or disable extra for this entry here. To edit an extra use') }}
                <a href="/cp/resrv/extras" class="text-blue-600 hover:underline dark:text-blue-400">{{ __('the appropriate section in the control panel') }}</a>.
            </p>
            <ExtrasList :parent="props.meta.parent" :inside-entry="true" />
        </div>
    </element-container>
</template>

<script setup>
import { Fieldtype } from '@statamic/cms';
import { Alert } from '@statamic/cms/ui';
import { computed, onMounted } from 'vue';
import ExtrasList from '../components/ExtrasList.vue';

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
