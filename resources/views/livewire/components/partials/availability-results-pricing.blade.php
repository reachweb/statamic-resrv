<div class="text-lg font-medium mb-3">{{ trans('statamic-resrv::frontend.paymentDetails') }}</div>
<div class="flex items-center space-x-4 mb-2">
    <div class="flex-1 min-w-0">
        <p class="font-medium text-gray-500 truncate">
            {{ trans('statamic-resrv::frontend.totalAmount') }}
        </p>
     </div>
     <div class="inline-flex items-center text-base font-medium">
        {{ config('resrv-config.currency_symbol') }} {{ $this->calculateAvailabilityTotals($availability->get('data')['price'])->format() }}
     </div>
</div>
@if (config('resrv-config.payment') !== 'everything' && $this->freeCancellationPossible())
<div class="flex items-center space-x-4 mb-2">
    <div class="flex-1 min-w-0">
        <p class="font-medium text-gray-500 truncate">
            {{ trans('statamic-resrv::frontend.payableNow') }}
        </p>
     </div>
     <div class="inline-flex items-center text-base font-medium">
        {{ config('resrv-config.currency_symbol') }} {{ $this->calculateAvailabilityTotals($availability->get('data')['payment'])->format() }}
     </div>
</div>
@endif