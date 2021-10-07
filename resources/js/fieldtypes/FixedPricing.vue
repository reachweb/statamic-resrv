<template>
    <element-container @resized="containerWidth = $event.width">
    <div class="w-full h-full text-center my-4 text-gray-700 text-lg" v-if="newItem">
       {{  __('You need to save this entry before you can add fixed pricing.' )}}
    </div>
    <div class="statamic-resrv-extras relative" v-else>
        <fixed-pricing-list
            :parent="this.meta.parent"
        >
        </fixed-pricing-list>
    </div>
    </element-container>

</template>

<script>
import FixedPricingList from '../components/FixedPricingList.vue'

export default {

    mixins: [Fieldtype],

    data() {
        return {
            containerWidth: null,           
        }
    },

    components: {
        FixedPricingList,
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
