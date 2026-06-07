@use(Carbon\Carbon)
@use(Reach\StatamicResrv\Enums\CancellationPolicy)

<div class="relative">
    @if (! $data->hasDates())
    <div class="flex flex-col py-4">
        <dt class="text-lg font-medium">{{ trans('statamic-resrv::frontend.pleaseSelectDates') }}</dt>
    </div>
    @else
        @if ($this->rows->isEmpty())
        <div class="flex flex-col py-4">
            <dt class="text-lg font-medium">{{ trans('statamic-resrv::frontend.noAvailability') }}</dt>
            <dd class="mb-1 text-gray-500">{{ trans('statamic-resrv::frontend.tryAdjustingYourSearch') }}</dd>
        </div>
        @else
        <div class="grid grid-cols-1 gap-4" role="list">
        @foreach ($this->rows as $row)
            @php($entry = $row['entry'])
            <div
                class="flex flex-col gap-3 rounded-lg border border-gray-200 p-4 sm:flex-row sm:items-center sm:justify-between"
                role="listitem"
                wire:key="resrv-collection-{{ $row['id'] }}"
            >
                <div class="min-w-0">
                    <div class="text-lg font-medium">
                        @if ($entry->url())
                        <a href="{{ $entry->url() }}" class="hover:underline">{{ $entry->get('title') }}</a>
                        @else
                        {{ $entry->get('title') }}
                        @endif
                    </div>
                </div>

                @if ($row['available'])
                    @if ($showRates)
                    <div class="flex flex-col gap-2 sm:items-end">
                        @foreach ($row['rates'] as $rate)
                        @php($cancellation = data_get($rate, 'cancellation_policy'))
                        @php($cancellationLabel = $cancellation ? CancellationPolicy::labelFor($cancellation['policy'], $cancellation['period'], Carbon::parse($data->dates['date_start'])) : null)
                        <div class="flex items-center gap-3">
                            <div class="text-right">
                                <span class="text-sm font-medium text-gray-600">
                                    {{ data_get($this->rateLabels, data_get($rate, 'rate_id'), data_get($rate, 'rateLabel')) }}
                                </span>
                                <span class="font-medium">
                                    {{ config('resrv-config.currency_symbol') }} {{ data_get($rate, 'price') }}
                                </span>
                                @if ($cancellationLabel)
                                <div class="text-xs text-gray-500">{{ $cancellationLabel }}</div>
                                @endif
                            </div>
                            <button
                                type="button"
                                class="rounded-lg bg-blue-700 px-5 py-2.5 text-sm font-medium text-white hover:bg-blue-800 focus:outline-none focus:ring-4 focus:ring-blue-300"
                                wire:click="select('{{ $row['id'] }}', {{ data_get($rate, 'rate_id') ?? 'null' }})"
                                aria-label="Book {{ data_get($this->rateLabels, data_get($rate, 'rate_id'), $entry->get('title')) }} now"
                            >
                                {{ trans('statamic-resrv::frontend.bookNow') }}
                            </button>
                        </div>
                        @endforeach
                    </div>
                    @else
                    @php($cancellation = data_get($row['from'], 'cancellation_policy'))
                    @php($cancellationLabel = $cancellation ? CancellationPolicy::labelFor($cancellation['policy'], $cancellation['period'], Carbon::parse($data->dates['date_start'])) : null)
                    <div class="flex items-center gap-3 sm:justify-end">
                        <div class="text-right">
                            @if (data_get($row['from'], 'original_price'))
                            <span class="mr-1 text-gray-400 line-through">
                                {{ config('resrv-config.currency_symbol') }} {{ data_get($row['from'], 'original_price') }}
                            </span>
                            @endif
                            <span class="font-medium">
                                {{ config('resrv-config.currency_symbol') }} {{ data_get($row['from'], 'price') }}
                            </span>
                            @if ($cancellationLabel)
                            <div class="text-xs text-gray-500">{{ $cancellationLabel }}</div>
                            @endif
                        </div>
                        <button
                            type="button"
                            class="rounded-lg bg-blue-700 px-5 py-2.5 text-sm font-medium text-white hover:bg-blue-800 focus:outline-none focus:ring-4 focus:ring-blue-300"
                            wire:click="select('{{ $row['id'] }}', {{ data_get($row['from'], 'rate_id') ?? 'null' }})"
                            aria-label="Book {{ $entry->get('title') }} now"
                        >
                            {{ trans('statamic-resrv::frontend.bookNow') }}
                        </button>
                    </div>
                    @endif
                @else
                <div class="font-medium text-gray-500">{{ trans('statamic-resrv::frontend.noAvailability') }}</div>
                @endif
            </div>
        @endforeach
    </div>
        @endif

    @if ($paginate)
    <div class="mt-6">
        {{ $this->resolvedEntries->links() }}
    </div>
    @endif
    @endif

    @if ($errors->has('availability') && $data->hasDates())
    <div class="flex flex-col py-4">
        <dt class="text-lg font-medium">{{ trans('statamic-resrv::frontend.searchError') }}</dt>
        <dd class="mb-1 text-gray-500">{{ $errors->first('availability') }}</dd>
    </div>
    @endif

    <div class="absolute left-0 right-0 top-0 h-full w-full bg-white/50" wire:loading.delay.long>
        <span class="flex h-full w-full items-center justify-center">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-5 w-5 animate-spin">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
            </svg>
        </span>
    </div>
</div>
