<script>
import axios from 'axios'

export default {   

    data() {
        return {
            disableSave: false,
            errors: '',
            successMessage: 'Success',
            postUrl: '/',
        }
    },

    emits: ['saved'],

    computed: {
        finalPostUrl() {
           return this.computedPostUrl || this.postUrl;
        },
    },

    methods: {
        save() {
            this.toggleDisableSave()
            this.clearErrors()
            axios({
                method: this.method,
                url: this.finalPostUrl,
                data: this.submit
            })
            .then(response => {
                this.handleSuccess(response.data)
            })
            .catch(error => {
                this.handleErrors(error.response)
            })
        },
        handleSuccess(data) {
            this.toggleDisableSave()
            this.$toast.success(this.successMessage)
            this.$emit('saved')
        },
        handleErrors(error) {
            this.errors = error.data.errors
            this.toggleDisableSave()
        },
        toggleDisableSave() {
            this.disableSave = !this.disableSave
        },
        clearErrors() {
            this.errors = ''
        },
    }
}
</script>
