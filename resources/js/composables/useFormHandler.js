import axios from 'axios';
import { ref, unref } from 'vue';
import { useToast } from './useToast.js';

export function useFormHandler({ submit, postUrl, method = 'post', successMessage = 'Success', emit }) {
    const toast = useToast();
    const disableSave = ref(false);
    const errors = ref('');

    function clearErrors() {
        errors.value = '';
    }

    function handleSuccess() {
        disableSave.value = false;
        toast.success(unref(successMessage));
        emit?.('saved');
    }

    function handleErrors(response) {
        errors.value = response.data.errors;
        disableSave.value = false;
    }

    function save() {
        disableSave.value = true;
        clearErrors();

        return axios({
            method: unref(method),
            url: unref(postUrl),
            data: unref(submit),
        })
            .then((response) => handleSuccess(response.data))
            .catch((error) => handleErrors(error.response));
    }

    return { disableSave, errors, save, handleSuccess, handleErrors, clearErrors };
}
