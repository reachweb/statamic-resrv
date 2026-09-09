<div class="w-full">
    @if ($linkFailed || $this->reservation === null)
        <div class="max-w-xl">
            <div class="my-4 p-4 bg-amber-50 border border-amber-300 rounded text-gray-700">
                {{ trans('statamic-resrv::frontend.paymentLinkFailed') }}
            </div>
        </div>
    @else
        @php($reservation = $this->reservation)
        @php($entry = $reservation->entry)
        <div class="max-w-2xl">
            <div class="flex items-center justify-between mb-4">
                <div class="text-lg xl:text-xl font-medium">
                    {{ trans('statamic-resrv::frontend.completeYourPayment') }}
                </div>
            </div>

            @if ($this->state === 'paid')
                <div class="flex flex-col my-4 p-4 bg-green-50 border border-green-300 rounded">
                    <dd class="text-gray-700">{{ trans('statamic-resrv::frontend.paymentAlreadyCompleted') }}</dd>
                </div>
            @elseif ($this->state === 'processing')
                <div class="flex flex-col my-4 p-4 bg-blue-50 border border-blue-300 rounded">
                    <dd class="text-gray-700">{{ trans('statamic-resrv::frontend.paymentProcessing') }}</dd>
                </div>
            @elseif ($this->state === 'deadline_passed')
                <div class="flex flex-col my-4 p-4 bg-amber-50 border border-amber-300 rounded">
                    <dd class="text-gray-700">{{ trans('statamic-resrv::frontend.paymentLinkExpired') }}</dd>
                </div>
            @elseif ($this->state === 'unavailable')
                <div class="flex flex-col my-4 p-4 bg-gray-50 border border-gray-300 rounded">
                    <dd class="text-gray-700">{{ trans('statamic-resrv::frontend.paymentUnavailable') }}</dd>
                </div>
            @else
                <div class="bg-gray-100 rounded p-4 lg:p-8">
                    <div class="text-lg xl:text-xl font-medium mb-2">
                        {{ $entry['id'] ? $entry['title'] : trans('statamic-resrv::frontend.reservationItemUnavailable') }}
                    </div>
                    <hr class="h-px mt-2 lg:my-3 bg-gray-200 border-0">
                    <div class="py-3 md:py-4 border-b border-gray-200">
                        <p class="font-medium text-gray-500 truncate">
                            {{ trans('statamic-resrv::frontend.bookingReference') }}
                        </p>
                        <p class="text-gray-900 truncate">
                            {{ $reservation->reference }}
                        </p>
                    </div>
                    <div class="py-3 md:py-4 border-b border-gray-200">
                        <p class="font-medium text-gray-500 truncate">
                            {{ trans('statamic-resrv::frontend.reservationPeriod') }}
                            @if ($reservation->rate_id && $reservation->rate)
                            &middot; {{ $reservation->rate->title }}
                            @endif
                        </p>
                        <p class="text-gray-900 truncate">
                            {{ $reservation->date_start->format('d-m-Y') }}
                            {{ trans('statamic-resrv::frontend.to') }}
                            {{ $reservation->date_end->format('d-m-Y') }}
                            @if ($reservation->quantity > 1)
                            &middot; x{{ $reservation->quantity }}
                            @endif
                        </p>
                    </div>
                    @if ($reservation->extras->isNotEmpty())
                    <div class="py-3 md:py-4 border-b border-gray-200">
                        <p class="font-medium text-gray-500 truncate">
                            {{ trans('statamic-resrv::frontend.extras') }}
                        </p>
                        @foreach ($reservation->extras as $extra)
                        <p class="text-gray-900 truncate" wire:key="extra-{{ $extra->id }}">
                            {{ $extra->name }} x{{ $extra->pivot->quantity }}
                        </p>
                        @endforeach
                    </div>
                    @endif
                    @if ($reservation->options->isNotEmpty())
                    <div class="py-3 md:py-4 border-b border-gray-200">
                        <p class="font-medium text-gray-500 truncate">
                            {{ trans('statamic-resrv::frontend.options') }}
                        </p>
                        @foreach ($reservation->options as $option)
                        <p class="text-gray-900 truncate" wire:key="option-{{ $option->id }}">
                            {{ $option->name }}: {{ $option->values->firstWhere('id', $option->pivot->value)?->name }}
                        </p>
                        @endforeach
                    </div>
                    @endif
                    <div class="py-3 md:py-4 border-b border-gray-200">
                        <p class="font-medium text-gray-500 truncate">
                            {{ trans('statamic-resrv::frontend.total') }}
                        </p>
                        <p class="text-gray-900 truncate">
                            {{ config('resrv-config.currency_symbol') }} {{ $reservation->total->format() }}
                        </p>
                    </div>
                    <div class="py-3 md:py-4 border-b border-gray-200">
                        <p class="font-medium text-gray-500 truncate">
                            {{ trans('statamic-resrv::frontend.amountToPay') }}
                        </p>
                        <p class="text-gray-900 font-semibold truncate">
                            {{ config('resrv-config.currency_symbol') }} {{ $this->amountDue }}
                        </p>
                    </div>
                    @if ($reservation->hold_expires_at)
                    <div class="py-3 md:py-4">
                        <p class="font-medium text-gray-500 truncate">
                            {{ trans('statamic-resrv::frontend.paymentDeadline') }}
                        </p>
                        <p class="text-gray-900 truncate">
                            {{ $reservation->hold_expires_at->format('d-m-Y H:i') }}
                        </p>
                    </div>
                    @endif
                </div>

                @if ($this->state === 'instructions')
                    <div class="flex flex-col my-4 p-4 bg-blue-50 border border-blue-300 rounded">
                        <dd class="text-gray-700">{{ trans('statamic-resrv::frontend.paymentOfflineInstructions') }}</dd>
                    </div>
                @else
                    @if ($paymentError)
                    <div class="flex flex-col my-4 p-4 bg-red-50 border border-red-300 rounded">
                        <dd class="text-gray-700">{{ trans('statamic-resrv::frontend.paymentMountFailed') }}</dd>
                    </div>
                    @endif
                    <div class="mt-6">
                        <button
                            type="button"
                            wire:click="pay"
                            class="flex items-center justify-center w-full relative px-6 py-3.5 text-base font-medium text-white bg-blue-700 hover:bg-blue-800 focus:ring-4 focus:outline-none focus:ring-blue-300 rounded-lg text-center disabled:opacity-70 transition-opacity duration-300"
                        >
                            {{ trans('statamic-resrv::frontend.pay') }}
                            <span class="font-bold ml-1">{{ config('resrv-config.currency_symbol') }} {{ $this->amountDue }}</span>
                        </button>
                    </div>
                @endif
            @endif
        </div>
    @endif
</div>
