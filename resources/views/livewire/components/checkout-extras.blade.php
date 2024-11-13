@props(['extras', 'enabledExtras'])

<div
    x-data="{selectedExtras: {}}" 
    x-on:extra-changed="selectedExtras[$event.detail.id.toString()] = $event.detail; $wire.set('enabledExtras.extras', Object.assign({}, selectedExtras));"
    x-on:extra-removed="delete selectedExtras[$event.detail.id.toString()]; $wire.set('enabledExtras.extras', Object.assign({}, selectedExtras));"
    x-init="selectedExtras = @js($enabledExtras->extras)"
>
    <div class="mt-6 xl:mt-8">
        <div class="text-lg xl:text-xl font-medium mb-2">
            {{ trans('statamic-resrv::frontend.extras') }}
        </div>
        <div class="text-gray-700">
            {{ trans('statamic-resrv::frontend.extrasDescription') }}
        </div>
    </div>
    <hr class="h-px my-4 bg-gray-200 border-0">
    <div class="divide-y divide-gray-100">
        @foreach ($extras as $id => $extra)
            <div wire:key="{{ $extra->id }}.{{ $extra->price }}">
                <x-resrv::checkout-extra :extra="$extra" :selectedValue="data_get($enabledExtras->extras, $extra->id)" x-bind:key="{{ $extra->id }}" />
            </div>
        @endforeach
    </div>
</div>