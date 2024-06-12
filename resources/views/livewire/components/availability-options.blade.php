@props(['options', 'enabledOptions'])

<div 
    x-data="{selectedOptions: {}}" 
    x-on:option-changed="selectedOptions[$event.detail.id.toString()] = $event.detail; $wire.set('enabledOptions.options', Object.assign({}, selectedOptions))"
    x-on:option-removed="delete selectedOptions[$event.detail.id.toString()]; $wire.set('enabledOptions.options', Object.assign({}, selectedOptions));"
    x-init="selectedOptions = @js($enabledOptions->options)"
>
    <div class="flex flex-col gap-y-6">
        @foreach ($options as $id => $option)
            <div>
                <div class="font-medium mb-2">{{ $option->name }}</div>
                <x-resrv::availability-option :option="$option" x-bind:key="{{ $option->id }}" />
            </div>
        @endforeach
    </div>
</div>
