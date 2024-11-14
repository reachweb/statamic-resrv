@props(['extras', 'enabledExtras'])

<div
    x-data="{selectedExtras: {}}" 
    x-on:extra-changed="selectedExtras[$event.detail.id.toString()] = $event.detail; $wire.set('enabledExtras.extras', Object.assign({}, selectedExtras));"
    x-on:extra-removed="delete selectedExtras[$event.detail.id.toString()]; $wire.set('enabledExtras.extras', Object.assign({}, selectedExtras));"
    x-init="selectedExtras = @js($enabledExtras->extras)"
>
    <div class="flex flex-col">
        @foreach ($extras as $id => $extra)
            <div wire:key="{{ $extra->id }}.{{ $extra->price }}">
                <x-resrv::checkout-extra 
                    :extra="$extra"
                    :required="$this->extraConditions->get('required')->contains($extra->id)"
                    :hide="$this->extraConditions->get('hide')->contains($extra->id)"
                    x-bind:key="{{ $extra->id }}"
                    compact="true" 
                />
            </div>            
        @endforeach
    </div>
</div>