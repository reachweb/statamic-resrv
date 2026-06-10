@use(Reach\StatamicResrv\Enums\ReservationStatus)
<div class="w-full">
    @if ($this->reservation === null)
        <div class="max-w-xl">
            <div class="text-lg xl:text-xl font-medium mb-2">
                {{ trans('statamic-resrv::frontend.findYourReservation') }}
            </div>
            <p class="text-gray-700 mb-4">
                {{ trans('statamic-resrv::frontend.findYourReservationDescription') }}
            </p>
            @if ($errors->has('lookup'))
                <div class="flex flex-col my-4 p-4 bg-red-50 border border-red-300 rounded">
                    <dt class="text-lg font-medium">{{ trans('statamic-resrv::frontend.somethingWentWrong') }}</dt>
                    <dd class="mb-1 text-gray-700">{{ $errors->first('lookup') }}</dd>
                </div>
            @endif
            <form wire:submit="lookup" class="flex flex-col gap-4">
                <div class="relative">
                    <label for="resrv-status-email" class="block mb-2 font-medium text-gray-900">
                        {{ trans('statamic-resrv::frontend.emailAddress') }}
                    </label>
                    <input
                        wire:model="email"
                        type="email"
                        id="resrv-status-email"
                        class="form-input bg-gray-50 border border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5"
                    />
                    @if ($errors->has('email'))
                    <p class="mt-2 text-red-600">{{ implode(', ', $errors->get('email')) }}</p>
                    @endif
                </div>
                <div class="relative">
                    <label for="resrv-status-reference" class="block mb-2 font-medium text-gray-900">
                        {{ trans('statamic-resrv::frontend.bookingReference') }}
                    </label>
                    <input
                        wire:model="reference"
                        type="text"
                        id="resrv-status-reference"
                        class="form-input bg-gray-50 border border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5"
                    />
                    @if ($errors->has('reference'))
                    <p class="mt-2 text-red-600">{{ implode(', ', $errors->get('reference')) }}</p>
                    @endif
                </div>
                <div class="mt-2">
                    <button
                        type="submit"
                        class="relative w-full px-6 py-3.5 text-base font-medium focus:ring-4 focus:outline-none focus:ring-blue-300 rounded-lg text-center disabled:opacity-70 transition-all duration-300 bg-blue-700 hover:bg-blue-800 text-white"
                    >
                        <span class="absolute left-0 right-0 top-0 w-full h-full bg-white/20" wire:loading.delay.long wire:target="lookup">
                            <span class="flex items-center justify-center w-full h-full">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="animate-spin w-5 h-5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                                </svg>
                            </span>
                        </span>
                        <span>{{ trans('statamic-resrv::frontend.findReservation') }}</span>
                    </button>
                </div>
            </form>
        </div>
    @else
        @php($reservation = $this->reservation)
        <div class="max-w-2xl">
            <div class="flex items-center justify-between mb-4">
                <div class="text-lg xl:text-xl font-medium">
                    {{ trans('statamic-resrv::frontend.reservationStatus') }}
                </div>
                <span @class([
                    'inline-flex items-center px-3 py-1 rounded-full text-sm font-medium',
                    'bg-green-100 text-green-800' => $reservation->isLive(),
                    'bg-gray-100 text-gray-800' => $reservation->status === ReservationStatus::REFUNDED->value,
                ])>
                    {{ $this->statusLabel }}
                </span>
            </div>

            @if ($cancelled)
            <div class="flex flex-col my-4 p-4 bg-green-50 border border-green-300 rounded">
                <dd class="text-gray-700">{{ trans('statamic-resrv::frontend.reservationCancelledSuccess') }}</dd>
            </div>
            @endif

            <div class="bg-gray-100 rounded p-4 lg:p-8">
                <div class="text-lg xl:text-xl font-medium mb-2">
                    {{ $reservation->entry['title'] }}
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
                @if ($reservation->isParent())
                    @foreach ($reservation->childs as $child)
                    <div class="py-3 md:py-4 border-b border-gray-200" wire:key="child-{{ $child->id }}">
                        <p class="font-medium text-gray-500 truncate">
                            {{ trans('statamic-resrv::frontend.reservationPeriod') }}
                            @if ($child->rate_id)
                            &middot; {{ $child->getRateLabel() }}
                            @endif
                        </p>
                        <p class="text-gray-900 truncate">
                            {{ $child->date_start->format('d-m-Y') }}
                            {{ trans('statamic-resrv::frontend.to') }}
                            {{ $child->date_end->format('d-m-Y') }}
                            @if ($child->quantity > 1)
                            &middot; x{{ $child->quantity }}
                            @endif
                        </p>
                    </div>
                    @endforeach
                @else
                    <div class="py-3 md:py-4 border-b border-gray-200">
                        <p class="font-medium text-gray-500 truncate">
                            {{ trans('statamic-resrv::frontend.reservationPeriod') }}
                        </p>
                        <p class="text-gray-900 truncate">
                            {{ $reservation->date_start->format('d-m-Y') }}
                            {{ trans('statamic-resrv::frontend.to') }}
                            {{ $reservation->date_end->format('d-m-Y') }}
                        </p>
                    </div>
                    @if ($reservation->quantity > 1)
                    <div class="py-3 md:py-4 border-b border-gray-200">
                        <p class="font-medium text-gray-500 truncate">
                            {{ trans('statamic-resrv::frontend.quantity') }}
                        </p>
                        <p class="text-gray-900 truncate">
                            x{{ $reservation->quantity }}
                        </p>
                    </div>
                    @endif
                    @if ($reservation->rate_id)
                    <div class="py-3 md:py-4 border-b border-gray-200">
                        <p class="font-medium text-gray-500 truncate">
                            {{ trans('statamic-resrv::frontend.property') }}
                        </p>
                        <p class="text-gray-900 truncate">
                            {{ $reservation->getRateLabel() }}
                        </p>
                    </div>
                    @endif
                @endif
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
                @php($cancellationLabel = $reservation->cancellationPolicyLabel())
                @if ($cancellationLabel)
                <div class="py-3 md:py-4 border-b border-gray-200">
                    <p class="font-medium text-gray-500 truncate">
                        {{ trans('statamic-resrv::frontend.cancellationPolicy') }}
                    </p>
                    <p class="text-gray-900 truncate">
                        {{ $cancellationLabel }}
                    </p>
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
                <div class="py-3 md:py-4">
                    <p class="font-medium text-gray-500 truncate">
                        {{ trans('statamic-resrv::frontend.amountPaid') }}
                    </p>
                    <p class="text-gray-900 truncate">
                        {{ config('resrv-config.currency_symbol') }} {{ $reservation->payment->format() }}
                    </p>
                </div>
            </div>

            @if ($errors->has('cancellation'))
            <div class="flex flex-col my-4 p-4 bg-red-50 border border-red-300 rounded">
                <dt class="text-lg font-medium">{{ trans('statamic-resrv::frontend.somethingWentWrong') }}</dt>
                <dd class="mb-1 text-gray-700">{{ $errors->first('cancellation') }}</dd>
            </div>
            @endif

            @if (! $cancelled)
                @if ($reservation->canBeCancelledByCustomer())
                <div class="mt-6">
                    <p class="text-gray-700 mb-4">
                        {{ trans('statamic-resrv::frontend.cancelReservationDescription') }}
                    </p>
                    <button
                        type="button"
                        wire:click="cancel"
                        wire:confirm="{{ trans('statamic-resrv::frontend.cancelReservationConfirmation') }}"
                        wire:loading.attr="disabled"
                        class="relative w-full px-6 py-3.5 text-base font-medium focus:ring-4 focus:outline-none focus:ring-red-300 rounded-lg text-center disabled:opacity-70 transition-all duration-300 border border-red-700 text-red-700 hover:bg-red-700 hover:text-white"
                    >
                        <span wire:loading.remove wire:target="cancel">{{ trans('statamic-resrv::frontend.cancelReservation') }}</span>
                        <span wire:loading wire:target="cancel">{{ trans('statamic-resrv::frontend.cancelReservation') }}&hellip;</span>
                    </button>
                </div>
                @elseif ($reservation->freeCancellationExpired())
                <div class="mt-6 p-4 bg-gray-100 border border-gray-200 rounded text-gray-700">
                    {{ trans('statamic-resrv::frontend.freeCancellationExpired') }}
                </div>
                @endif
            @endif

            <div class="mt-6">
                <button
                    type="button"
                    wire:click="startOver"
                    class="inline-flex items-center font-medium text-gray-900"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" />
                    </svg>
                    {{ trans('statamic-resrv::frontend.lookUpAnotherReservation') }}
                </button>
            </div>
        </div>
    @endif
</div>
