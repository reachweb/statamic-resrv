@props(['options', 'enabledOptions'])

<div 
    x-data="{selectedOptions: {}}" 
    x-on:option-changed="selectedOptions[$event.detail.id.toString()] = $event.detail; $wire.set('enabledOptions.options', Object.assign({}, selectedOptions))"
    x-init="selectedOptions = @js($enabledOptions->options)"
>
    <div class="divide-y divide-gray-100">
        @foreach ($options as $id => $option)
            <x-resrv::availability-option :option="$option" :selectedValue="data_get($enabledOptions->options, $option->id.'.value')" x-bind:key="{{ $option->id }}" />
        @endforeach
    </div>
</div>
