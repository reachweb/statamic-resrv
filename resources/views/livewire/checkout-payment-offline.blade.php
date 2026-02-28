<div>
    <div x-data="offlinePayment">
        <div class="my-6 xl:my-8">
            <div class="text-lg xl:text-xl font-medium mb-2">
                {{ trans('statamic-resrv::frontend.payment') }}
            </div>
            <div class="text-gray-700">
                {{ trans('statamic-resrv::frontend.offlinePaymentDescription') }}
            </div>
        </div>
        <div class="my-6 xl:my-8">
            <div class="text-base font-medium mb-4">
                {{ trans('statamic-resrv::frontend.offlinePaymentAmount') }}:
                <span class="font-bold">{{ config('resrv-config.currency_symbol') }} {{ $amount }}</span>
            </div>
            <button
                type="button"
                class="flex items-center justify-center w-full relative px-6 py-3.5 text-base font-medium text-white bg-blue-700 hover:bg-blue-800 focus:ring-4
                focus:outline-none focus:ring-blue-300 rounded-lg text-center disabled:opacity-70 transition-opacity duration-300"
                x-on:click="confirmReservation()"
                x-bind:disabled="loading"
            >
                <span class="py-0.5" x-cloak x-transition x-show="loading === true">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="animate-spin w-5 h-5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                    </svg>
                </span>
                <span x-transition x-show="loading === false">
                    {{ trans('statamic-resrv::frontend.confirmReservation') }}
                </span>
            </button>
        </div>
        @if ($errors->has('reservation'))
        <div class="mt-6 p-4 bg-red-50 border border-red-300 rounded text-red-600">
            @foreach ($errors->get('reservation') as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
        @endif
        <p x-show="errors" x-cloak x-transition class="mt-6 xm:mt-8 text-red-600">
            <span x-html="errors"></span>
        </p>
    </div>
</div>

@script
<script>
Alpine.data('offlinePayment', () => ({
    loading: false,
    errors: false,

    async confirmReservation() {
        this.loading = true;
        this.errors = false;

        try {
            await $wire.confirmPayment();
        } catch (e) {
            this.errors = e.message || 'An unexpected error occurred. Please try again.';
            this.loading = false;
        }
    }
}));
</script>
@endscript