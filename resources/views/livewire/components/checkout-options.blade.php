@props(['options', 'enabledOptions'])

<div 
    x-data="{selectedOptions: {}}" 
    x-on:option-changed="selectedOptions[$event.detail.id.toString()] = $event.detail; $wire.set('enabledOptions', Object.assign({}, selectedOptions))"
    x-init="selectedOptions = @js($enabledOptions)"
>
    <div class="mt-6 xl:mt-8">
        <div class="text-lg xl:text-xl font-medium mb-2">
            {{ trans('statamic-resrv::frontend.options') }}
        </div>
        <div class="text-gray-700">
            {{ trans('statamic-resrv::frontend.optionsDescription') }}
        </div>
    </div>
    <hr class="h-px my-4 bg-gray-200 border-0">
    <div class="divide-y divide-gray-100">
        @foreach ($options as $id => $option)
            <x-resrv::checkout-option :option="$option" :selectedValue="data_get($enabledOptions, $option->id.'.value')" x-bind:key="{{ $option->id }}" />
        @endforeach
    </div>
</div>
