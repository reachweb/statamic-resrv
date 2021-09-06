<template>
    <element-container @resized="containerWidth = $event.width">
    <div class="w-full h-full text-center my-4 text-gray-700 text-lg" v-if="newItem">
       {{  __('You need to save this entry before you can add options.' )}}
    </div>
    <div class="statamic-resrv-options relative" v-else>
        <options-list
            :parent="this.meta.parent"
        >
        </options-list>
    </div>
    </element-container>

</template>

<script>
import OptionsList from '../components/OptionsList.vue'

export default {

    mixins: [Fieldtype],

    data() {
        return {
            containerWidth: null,           
        }
    },

    components: {
        OptionsList,
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
