import axios from 'axios';
import { ref, unref } from 'vue';
import { useToast } from './useToast.js';

export function useFormHandler({ submit, postUrl, method = 'post', successMessage = 'Success', emit }) {
    const toast = useToast();
    const disableSave = ref(false);
    const errors = ref({});

    function clearErrors() {
        errors.value = {};
    }

    function handleSuccess() {
        disableSave.value = false;
        toast.success(__(unref(successMessage)));
        emit?.('saved');
    }

    function handleErrors(response) {
        disableSave.value = false;

        if (response?.status === 422 && response.data?.errors) {
            errors.value = response.data.errors;
            return;
        }

        errors.value = {};
        toast.error(response?.data?.message || __('Something went wrong. Please try again.'));
    }

    // Success side effects flow through the 'saved' emit; the returned
    // promise never rejects but resolves true/false so callers can branch.
    function save() {
        disableSave.value = true;
        clearErrors();

        return axios({
            method: unref(method),
            url: unref(postUrl),
            data: unref(submit),
        })
            .then(() => {
                handleSuccess();
                return true;
            })
            .catch((error) => {
                handleErrors(error.response);
                return false;
            });
    }

    return { disableSave, errors, save, handleSuccess, handleErrors, clearErrors };
}
