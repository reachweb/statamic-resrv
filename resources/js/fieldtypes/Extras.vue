<template>
    <element-container @resized="containerWidth = $event.width">
    <div class="w-full h-full text-center my-4 text-gray-700 text-lg" v-if="newItem">
        {{ __('You need to save this entry before you can add extras.') }}
    </div>
    <div class="statamic-resrv-extras relative" v-else>
        <div class="text-sm text-gray-600 mb-2">
            {{ __('You can only enable or disable extra for this entry here. To edit an extra use') }}
            <a href="/cp/resrv/extras">{{ __('the appropriate section in the control panel') }}</a>.
        </div>
        <extras-list
            :parent="this.meta.parent"
            :inside-entry="true"        
        >
        </extras-list>
    </div>
    </element-container>

</template>

<script>
import ExtrasList from '../components/ExtrasList.vue'

export default {

    mixins: [Fieldtype],

    data() {
        return {
            containerWidth: null,           
        }
    },

    components: {
        ExtrasList,
    },

    computed: {
        newItem() {
            if (this.meta.parent == 'Collection') {
                return true
            }
            return false
        }
    },

    updated() {
        if (! this.newItem) {
            this.$emit('input', this.meta.parent)
        }
    },
   
}
</script>
