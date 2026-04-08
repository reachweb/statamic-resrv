@use(Carbon\Carbon)

<div class="relative">
    @if ($availability->isNotEmpty() && $data->hasDates())
    <div class="mb-4">
        <div class="text-sm font-medium text-gray-600 mb-3">
            {{ trans('statamic-resrv::frontend.pleaseSelectRateToBook') }}:
        </div>
        <div class="text-sm text-gray-500 mb-2">
            <span>{{ Carbon::parse($data->dates['date_start'])->format('D d M Y') }}</span>
            <span class="mx-1">&rarr;</span>
            <span>{{ Carbon::parse($data->dates['date_end'])->format('D d M Y') }}</span>
        </div>
        <div class="grid grid-cols-1 gap-2">
            @foreach ($availability as $rateId => $rateData)
                @if (data_get($rateData, 'message.status') !== true)
                <div class="flex items-center justify-between rounded-lg bg-gray-50 border border-gray-300 text-gray-900 p-3">
                    <div>
                        <span class="font-bold text-sm">{{ data_get($this->entryRates, $rateId) }}</span>
                    </div>
                    <div class="text-sm text-gray-500">
                        {{ trans('statamic-resrv::frontend.noAvailability') }}
                    </div>
                </div>
                @else
                <div class="flex items-center justify-between rounded-lg bg-gray-50 border border-blue-600 text-gray-900 p-3">
                    <div>
                        <span class="font-bold text-sm">{{ data_get($this->entryRates, $rateId) }}</span>
                        <span class="ml-2 text-sm text-gray-600">
                            {{ config('resrv-config.currency_symbol') }} {{ data_get($rateData, 'data.price') }}
                        </span>
                    </div>
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            class="w-8 h-8 flex items-center justify-center rounded-full border border-gray-300 hover:bg-gray-200 text-sm font-medium"
                            wire:click="updateRateQuantity({{ $rateId }}, {{ ($rateQuantities[$rateId] ?? 0) - 1 }})"
                        >-</button>
                        <span class="w-6 text-center text-sm font-medium">{{ $rateQuantities[$rateId] ?? 0 }}</span>
                        <button
                            type="button"
                            class="w-8 h-8 flex items-center justify-center rounded-full border border-gray-300 hover:bg-gray-200 text-sm font-medium"
                            wire:click="updateRateQuantity({{ $rateId }}, {{ ($rateQuantities[$rateId] ?? 0) + 1 }})"
                        >+</button>
                    </div>
                </div>
                @endif
            @endforeach
        </div>
        @if (array_sum($rateQuantities) > 0)
        <div class="mt-3">
            <button
                type="button"
                class="w-full px-4 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-lg"
                wire:click="addSelections"
            >
                {{ trans('statamic-resrv::frontend.addToBooking') }}
            </button>
        </div>
        @endif
    </div>
    @elseif (! $data->hasDates())
    <div class="flex flex-col py-4">
        <dt class="text-lg font-medium">{{ trans('statamic-resrv::frontend.pleaseSelectDates') }}</dt>
    </div>
    @endif

    @if (count($selections) > 0)
    <div class="border-t border-gray-200 pt-4">
        <div class="text-lg font-medium mb-3">{{ trans('statamic-resrv::frontend.yourBooking') }}</div>
        @if ($this->showOptions)
        <div class="flex flex-col gap-y-6 my-4">
            <livewire:options
                :data="$this->data"
                :filter="$this->showOptions"
                :entryId="$this->entry->id"
                :useMultiSelections="true"
            />
        </div>
        @endif
        @if ($this->showExtras)
        <div class="flex flex-col gap-y-6 my-4">
            <livewire:extras
                :data="$this->data"
                :filter="$this->showExtras"
                :entryId="$this->entry->id"
                :useMultiSelections="true"
            />
        </div>
        @endif
        <div class="space-y-2">
            @foreach ($selections as $index => $selection)
            <div class="flex items-center justify-between bg-gray-50 rounded-lg p-3 text-sm">
                <div class="flex-1">
                    <div class="font-medium">{{ $selection['rate_label'] }} &times; {{ $selection['quantity'] }}</div>
                    <div class="text-gray-500 text-xs">
                        {{ Carbon::parse($selection['date_start'])->format('D d M Y') }}
                        &rarr;
                        {{ Carbon::parse($selection['date_end'])->format('D d M Y') }}
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <span class="font-medium">{{ config('resrv-config.currency_symbol') }} {{ $this->lineTotal($selection) }}</span>
                    <button
                        type="button"
                        class="text-red-500 hover:text-red-700 text-xs"
                        wire:click="removeSelection({{ $index }})"
                        aria-label="Remove"
                    >&times;</button>
                </div>
            </div>
            @endforeach
        </div>
        <div class="flex items-center justify-between mt-4 pt-3 border-t border-gray-200">
            <div>
                <span class="text-lg font-bold">{{ trans('statamic-resrv::frontend.total') }}:</span>
                <span class="text-lg font-bold ml-1">{{ config('resrv-config.currency_symbol') }} {{ $this->totalPrice }}</span>
            </div>
            <button
                type="button"
                class="text-sm text-gray-500 hover:text-gray-700 underline"
                wire:click="clearSelections"
            >{{ trans('statamic-resrv::frontend.clear') }}</button>
        </div>
        <div class="mt-4">
            <button
                type="button"
                class="w-full px-6 py-3.5 text-base font-medium text-white bg-blue-700 hover:bg-blue-800 focus:ring-4 focus:outline-none focus:ring-blue-300 rounded-lg text-center"
                wire:click="checkout"
            >
                {{ trans('statamic-resrv::frontend.bookNow') }}
            </button>
        </div>
    </div>
    @endif

    @if ($errors->has('availability') && $data->hasDates())
    <div class="flex flex-col py-4">
        <dt class="text-lg font-medium">{{ trans('statamic-resrv::frontend.searchError') }}</dt>
        <dd class="mb-1 text-gray-500">{{ $errors->first('availability') }}</dd>
    </div>
    @endif
    @if ($errors->has('cutoff') && $data->hasDates())
    <div class="flex flex-col py-4">
        <dt class="text-lg font-medium">{{ trans('statamic-resrv::frontend.youAreTooLate') }}</dt>
        <dd class="mb-1 text-gray-500">{{ $errors->first('cutoff') }}</dd>
    </div>
    @endif
    <div class="absolute left-0 right-0 top-0 w-full h-full bg-white/50" wire:loading.delay.long>
        <span class="flex items-center justify-center w-full h-full">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="animate-spin w-5 h-5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
            </svg>
        </span>
    </div>
</div>
