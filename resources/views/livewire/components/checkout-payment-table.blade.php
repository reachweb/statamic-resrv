@props(['extras', 'enabledExtras', 'options', 'enabledOptions', 'totals', 'key'])
<div {{ $attributes->merge(['class' => 'flex flex-grow']) }} wire:key={{ $key }}>
    <div class="flex flex-grow flex-col justify-between">
        <div class="flex flex-col gap-y-4 xl:gap-y-6">       
            <div>
                @if ($enabledOptions->count() > 0)
                <p class="text-sm font-medium text-gray-500 truncate mb-1">
                    {{ trans('statamic-resrv::frontend.options') }}
                </p>
                <div class="divide-y divide-gray-200">
                    @foreach ($enabledOptions as $option)
                    @php
                    $optionModel = $options->firstWhere('id', $option['id']);
                    $selectedValue = $optionModel->values->firstWhere('id', $option['value']);
                    @endphp
                    <div class="flex justify-between items-center py-2" wire:key="{{ $option['id'] }}">
                        <div class="text-sm text-gray-900">
                            {{ $optionModel->name }}: <span class="font-medium">{{ $selectedValue->name }}</span>
                        </div>
                        <div class="flex justify-end">
                            @if ($selectedValue->price_type !== 'free')
                            <span class="text-sm text-gray-900">
                                {{ config('resrv-config.currency_symbol') }} {{ $option['price'] }}
                            </span>
                            @else
                            <span class="text-sm text-gray-900">
                                {{ ucfirst(trans('statamic-resrv::frontend.free')) }}
                            </span>
                            @endif                        
                        </div>
                    </div>
                    @endforeach
                </div>
                @endif
            </div>
            <div>
                @if ($enabledExtras->count() > 0)
                <p class="text-sm font-medium text-gray-500 truncate mb-1">
                    {{ trans('statamic-resrv::frontend.extras') }}
                </p>
                <div class="divide-y divide-gray-200">
                    @foreach ($enabledExtras as $extra)
                    <div class="flex justify-between items-center py-2" wire:key="{{ $extra['id'] }}">
                        <div class="text-sm text-gray-900">
                            {{ $extras->firstWhere('id', $extra['id'])->name }}
                            <span>
                                @if ($extra['quantity'] > 1)
                                <span class="text-xs text-gray-500">
                                    (x{{ $extra['quantity'] }})
                                </span>
                                @endif
                            </span>
                        </div>
                        <div class="flex justify-end">
                            <span class="text-sm text-gray-900">
                                {{ config('resrv-config.currency_symbol') }} {{ $extra['price'] * $extra['quantity'] }}
                            </span>
                        </div>
                    </div>
                    @endforeach
                </div>
                @endif
            </div>
        </div>
        <div class="mt-4 xl:mt-6">
            <div class="divide-y divide-gray-200">
                <div class="flex justify-between items-center py-3">
                    <div class="text-sm text-gray-900">
                        {{ trans('statamic-resrv::frontend.reservationTotal') }}
                    </div>
                    <div class="flex justify-end">
                        <span class="text-sm text-gray-900">
                            {{ config('resrv-config.currency_symbol') }} {{ $totals->get('reservationTotal')->format() }}
                        </span>
                    </div>
                </div>
                <div class="flex justify-between items-center py-3">
                    <div class="text-md text-gray-900">
                        {{ trans('statamic-resrv::frontend.total') }}
                    </div>
                    <div class="flex justify-end">
                        <span class="text-md text-gray-900">
                            {{ config('resrv-config.currency_symbol') }} {{ $totals->get('total')->format() }}
                        </span>
                    </div>
                </div>
                @if (config('resrv-config.payment') !== 'full')
                <div class="flex justify-between items-center py-3">
                    <div class="text-md text-gray-900">
                        {{ trans('statamic-resrv::frontend.payableNow') }}
                    </div>
                    <div class="flex justify-end">
                        <span class="text-md text-gray-900">
                            {{ config('resrv-config.currency_symbol') }} {{ $totals->get('payment')->format() }}
                        </span>
                    </div>
                </div>
                @endif
            </div>
        </div>
    </div>
</div>