import { getCurrentInstance } from 'vue';

export function useToast() {
    const { proxy } = getCurrentInstance();
    return {
        success: (message) => proxy.$toast.success(message),
        error: (message) => proxy.$toast.error(message),
    };
}
