@props(['extras', 'enabledExtras', 'options', 'enabledOptions', 'totals', 'key'])

<div class="{{ $attributes->get('class') }}" wire:key={{ $key }}>
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