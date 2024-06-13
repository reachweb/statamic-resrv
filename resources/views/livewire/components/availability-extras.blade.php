@props(['extras', 'enabledExtras'])

<div
    x-data="{selectedExtras: {}}" 
    x-on:extra-changed="selectedExtras[$event.detail.id.toString()] = $event.detail; $wire.set('enabledExtras.extras', Object.assign({}, selectedExtras));"
    x-on:extra-removed="delete selectedExtras[$event.detail.id.toString()]; $wire.set('enabledExtras.extras', Object.assign({}, selectedExtras));"
    x-init="selectedExtras = @js($enabledExtras->extras)"
>
    <div class="flex flex-col gap-y-6">
        @foreach ($this->extras as $id => $extra)
            <div>
                <div class="font-medium mb-2">{{ $extra->name }}</div>
                <x-resrv::checkout-extra :extra="$extra" x-bind:key="{{ $extra->id }}" compact="true" />
            </div>            
        @endforeach
    </div>
</div>