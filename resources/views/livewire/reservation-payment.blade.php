<div class="w-full">
    @if ($linkFailed || $this->reservation === null)
        <div class="max-w-xl">
            <div class="flex flex-col gap-2 my-4 p-4 bg-amber-50 border border-amber-300 rounded" role="status">
                <p class="text-gray-700">{{ trans('statamic-resrv::frontend.paymentLinkFailed') }}</p>
                @include('statamic-resrv::livewire.components.partials.business-contact')
            </div>
        </div>
    @else
        @php($reservation = $this->reservation)
        @php($entry = $reservation->entry)
        @php($state = $this->state)
        @php($payable = in_array($state, ['awaiting', 'instructions'], true))
        <div class="max-w-2xl">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg xl:text-xl font-medium">
                    {{ $this->heading }}
                </h2>
            </div>

            @if ($state === 'paid')
                <div class="flex flex-col my-4 p-4 bg-green-50 border border-green-300 rounded" role="status">
                    <p class="text-gray-700">{{ trans('statamic-resrv::frontend.paymentAlreadyCompleted') }}</p>
                </div>
            @elseif ($state === 'processing')
                <div class="flex flex-col my-4 p-4 bg-blue-50 border border-blue-300 rounded" role="status">
                    <p class="text-gray-700">{{ trans('statamic-resrv::frontend.paymentProcessing') }}</p>
                </div>
            @elseif ($state === 'deadline_passed')
                <div class="flex flex-col gap-2 my-4 p-4 bg-amber-50 border border-amber-300 rounded" role="status">
                    <p class="text-gray-700">{{ trans('statamic-resrv::frontend.paymentLinkExpired') }}</p>
                    @include('statamic-resrv::livewire.components.partials.business-contact')
                </div>
            @elseif ($state === 'arrival_passed')
                <div class="flex flex-col gap-2 my-4 p-4 bg-amber-50 border border-amber-300 rounded" role="status">
                    <p class="text-gray-700">{{ trans('statamic-resrv::frontend.paymentArrivalPassed') }}</p>
                    @include('statamic-resrv::livewire.components.partials.business-contact')
                </div>
            @elseif ($state === 'unavailable')
                <div class="flex flex-col gap-2 my-4 p-4 bg-gray-50 border border-gray-300 rounded" role="status">
                    <p class="text-gray-700">{{ trans('statamic-resrv::frontend.paymentUnavailable') }}</p>
                    @include('statamic-resrv::livewire.components.partials.business-contact')
                </div>
            @endif

            <div class="bg-gray-100 rounded p-4 lg:p-8">
                @if ($payable)
                <div class="pb-4 mb-4 border-b border-gray-200">
                    <p class="font-medium text-gray-500">
                        {{ trans('statamic-resrv::frontend.amountToPay') }}
                    </p>
                    <p class="text-3xl font-semibold text-gray-900 tabular-nums">
                        {{ config('resrv-config.currency_symbol') }} {{ $this->amountDue }}
                    </p>
                    @if ($this->paymentDueLine)
                    <p class="mt-1 text-gray-700">
                        {{ $this->paymentDueLine }}
                    </p>
                    @endif
                </div>
                @endif
                <div class="text-lg xl:text-xl font-medium text-gray-900 mb-2 break-words">
                    {{ $entry['id'] ? $entry['title'] : trans('statamic-resrv::frontend.reservationItemUnavailable') }}
                </div>
                <hr class="h-px mt-2 lg:my-3 bg-gray-200 border-0">
                <div class="divide-y divide-gray-200">
                    <div class="py-3 md:py-4">
                        <p class="font-medium text-gray-500">
                            {{ trans('statamic-resrv::frontend.bookingReference') }}
                        </p>
                        <p class="text-gray-900">
                            {{ $reservation->reference }}
                        </p>
                    </div>
                    <div class="py-3 md:py-4">
                        <p class="font-medium text-gray-500 break-words">
                            {{ trans('statamic-resrv::frontend.reservationPeriod') }}
                            @if ($reservation->rate_id && $reservation->rate)
                            &middot; {{ $reservation->rate->title }}
                            @endif
                        </p>
                        <p class="text-gray-900">
                            {{ $reservation->date_start->format('d-m-Y') }}
                            {{ trans('statamic-resrv::frontend.to') }}
                            {{ $reservation->date_end->format('d-m-Y') }}
                            @if ($reservation->quantity > 1)
                            &middot; x{{ $reservation->quantity }}
                            @endif
                        </p>
                    </div>
                    @if ($payable)
                        @if ($reservation->extras->isNotEmpty())
                        <div class="py-3 md:py-4">
                            <p class="font-medium text-gray-500">
                                {{ trans('statamic-resrv::frontend.extras') }}
                            </p>
                            @foreach ($reservation->extras as $extra)
                            <p class="text-gray-900 break-words" wire:key="extra-{{ $extra->id }}">
                                {{ $extra->name }} x{{ $extra->pivot->quantity }}
                            </p>
                            @endforeach
                        </div>
                        @endif
                        @if ($reservation->options->isNotEmpty())
                        <div class="py-3 md:py-4">
                            <p class="font-medium text-gray-500">
                                {{ trans('statamic-resrv::frontend.options') }}
                            </p>
                            @foreach ($reservation->options as $option)
                            <p class="text-gray-900 break-words" wire:key="option-{{ $option->id }}">
                                {{ $option->name }}: {{ $option->values->firstWhere('id', $option->pivot->value)?->name }}
                            </p>
                            @endforeach
                        </div>
                        @endif
                        {{-- Total whenever the lead amount is not simply the total: a partial request or a surcharge. --}}
                        @if (! $reservation->payment->equals($reservation->total) || ! $reservation->payment_surcharge->isZero())
                        <div class="py-3 md:py-4">
                            <p class="font-medium text-gray-500">
                                {{ trans('statamic-resrv::frontend.total') }}
                            </p>
                            <p class="text-gray-900">
                                {{ config('resrv-config.currency_symbol') }} {{ $reservation->total->format() }}
                            </p>
                        </div>
                        @endif
                        @if (! $reservation->payment_surcharge->isZero())
                        <div class="py-3 md:py-4">
                            <p class="font-medium text-gray-500">
                                {{ trans('statamic-resrv::frontend.paymentSurcharge') }}
                            </p>
                            <p class="text-gray-900">
                                {{ config('resrv-config.currency_symbol') }} {{ $reservation->payment_surcharge->format() }}
                            </p>
                        </div>
                        @endif
                        @if ($this->balanceDue !== null)
                        <div class="py-3 md:py-4">
                            <p class="font-medium text-gray-500">
                                {{ trans('statamic-resrv::frontend.remainingBalance') }}
                            </p>
                            <p class="text-gray-900">
                                {{ config('resrv-config.currency_symbol') }} {{ $this->balanceDue }}
                            </p>
                            <p class="text-sm text-gray-600">
                                {{ trans('statamic-resrv::frontend.remainingBalanceNote') }}
                            </p>
                        </div>
                        @endif
                    @endif
                </div>
            </div>

            @if ($state === 'instructions')
                <div class="flex flex-col gap-2 my-4 p-4 bg-blue-50 border border-blue-300 rounded">
                    @if ($this->gatewayLabel)
                    <p class="font-medium text-gray-900">{{ trans('statamic-resrv::frontend.paymentMethodIs', ['method' => $this->gatewayLabel]) }}</p>
                    @endif
                    @if ($this->offlineInstructions)
                    <p class="text-gray-900 break-words">{!! nl2br(e($this->offlineInstructions)) !!}</p>
                    <p class="text-gray-700">{{ trans('statamic-resrv::frontend.paymentOfflineInstructions', ['reference' => $reservation->reference]) }}</p>
                    @else
                    <p class="text-gray-700">{{ trans('statamic-resrv::frontend.paymentOfflineNoInstructions', ['reference' => $reservation->reference]) }}</p>
                    @include('statamic-resrv::livewire.components.partials.business-contact')
                    @endif
                </div>
            @elseif ($state === 'awaiting')
                @if ($paymentError)
                <div class="flex flex-col my-4 p-4 bg-red-50 border border-red-300 rounded" role="alert">
                    <p class="text-gray-700">{{ trans('statamic-resrv::frontend.paymentMountFailed') }}</p>
                </div>
                @endif
                <div class="mt-6">
                    <button
                        type="button"
                        wire:click="pay"
                        wire:loading.attr="disabled"
                        wire:target="pay"
                        class="flex items-center justify-center w-full relative px-6 py-3.5 text-base font-medium text-white bg-blue-700 hover:bg-blue-800 focus:ring-4 focus:outline-none focus:ring-blue-300 rounded-lg text-center disabled:opacity-70 disabled:cursor-wait transition-opacity duration-300"
                    >
                        <span class="absolute left-0 right-0 top-0 w-full h-full bg-white/20 rounded-lg" wire:loading wire:target="pay">
                            <span class="flex items-center justify-center w-full h-full">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="animate-spin w-5 h-5" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                                </svg>
                                <span class="sr-only">{{ trans('statamic-resrv::frontend.paymentHeadingProcessing') }}</span>
                            </span>
                        </span>
                        <span wire:loading.class="invisible" wire:target="pay">
                            {{ trans('statamic-resrv::frontend.pay') }}
                            <span class="font-bold ml-1 whitespace-nowrap">{{ config('resrv-config.currency_symbol') }} {{ $this->amountDue }}</span>
                        </span>
                    </button>
                    @if ($this->gatewayLabel)
                    <p class="mt-2 text-sm text-gray-500 text-center">
                        {{ trans('statamic-resrv::frontend.paymentMethodIs', ['method' => $this->gatewayLabel]) }}
                    </p>
                    @endif
                </div>
            @endif
        </div>
    @endif
</div>
