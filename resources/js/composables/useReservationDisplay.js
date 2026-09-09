export function statusLabel(status) {
    return status?.replace(/_/g, ' ').toUpperCase() ?? '';
}

export async function copyPaymentLink(url, toast) {
    try {
        await navigator.clipboard.writeText(url);
        toast.success(__('Payment link copied'));
    } catch (error) {
        toast.error(__('Could not copy the payment link'));
    }
}
